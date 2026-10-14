<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Language;
use App\Models\PlatformLog;
use App\Models\Translation;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function memberAs(string $role): User
{
    $user = User::factory()->create();
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);

    return $user;
}

function currentClub(): Club
{
    return Club::query()->findOrFail(app(CurrentClub::class)->id());
}

test('a manager switches the club language and the interface follows', function () {
    $manager = memberAs('manager');

    $this->actingAs($manager)->post(route('settings.language'), ['locale' => 'kk'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Интерфейс тілі өзгертілді.');

    expect(currentClub()->locale)->toBe('kk')
        ->and(ActivityLog::query()->where('event', 'club.language_changed')->value('details'))->toBe(['from' => 'ru', 'to' => 'kk']);

    $this->get(route('settings.languages'))->assertSee('Интерфейс тілі')->assertSee('<html lang="kk">', false);
});

test('a disabled or unknown language cannot be selected', function () {
    $owner = User::factory()->create();
    Language::query()->where('code', 'en')->update(['is_enabled' => false]);

    $this->actingAs($owner)->post(route('settings.language'), ['locale' => 'en'])->assertSessionHasErrors('locale');
    $this->post(route('settings.language'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
    expect(currentClub()->locale)->toBeNull();
});

test('the superadmin adds a custom language and translates it in the editor', function () {
    $owner = User::factory()->superadmin()->create();

    $this->actingAs($owner)->post(route('languages.store'), ['code' => 'uz', 'name' => 'Oʻzbekcha'])->assertRedirect();
    $uz = Language::query()->where('code', 'uz')->firstOrFail();
    expect($uz->is_system)->toBeFalse()->and($uz->is_enabled)->toBeTrue();

    $this->get(route('languages.translations', ['language' => $uz, 'search' => 'Склад']))
        ->assertSuccessful()
        ->assertSee('Склад');

    $hash = Translation::hashKey('*', 'Склад');
    $this->put(route('languages.translations.save', $uz), ['translations' => [$hash => 'Ombor']])->assertRedirect();
    $this->post(route('settings.language'), ['locale' => 'uz']);

    $this->get(route('dashboard'))->assertSee('Ombor')->assertSee('Register');

    $this->put(route('languages.translations.save', $uz), ['translations' => [$hash => '']]);
    $this->get(route('dashboard'))->assertSee('Warehouse')->assertDontSee('Ombor');
    // Shared by every club: logged in the platform log, not in this club's log.
    expect(PlatformLog::query()->where('event', 'translations.updated')->count())->toBe(2)
        ->and(ActivityLog::query()->where('event', 'translations.updated')->exists())->toBeFalse();
});

test('the selected and the last enabled language cannot be disabled; built-in ones cannot be deleted', function () {
    $owner = User::factory()->superadmin()->create();
    $ru = Language::query()->where('code', 'ru')->firstOrFail();

    $this->actingAs($owner)->patch(route('languages.update', $ru), ['is_enabled' => 0])->assertSessionHasErrors('language');
    $this->delete(route('languages.destroy', $ru))->assertSessionHasErrors('language');

    Language::query()->where('code', '!=', 'kk')->update(['is_enabled' => false]);
    currentClub()->forceFill(['locale' => null])->save();
    $kk = Language::query()->where('code', 'kk')->firstOrFail();
    $this->patch(route('languages.update', $kk), ['is_enabled' => 0])->assertSessionHasErrors('language');
    expect($kk->refresh()->is_enabled)->toBeTrue();
});

test('deleting a custom language moves its clubs back to the default language', function () {
    $owner = User::factory()->superadmin()->create();
    $uz = Language::query()->create(['code' => 'uz', 'name' => 'Oʻzbekcha', 'is_enabled' => true]);
    currentClub()->forceFill(['locale' => 'uz'])->save();

    $this->actingAs($owner)->delete(route('languages.destroy', $uz))->assertRedirect(route('superadmin.languages'));

    expect(Language::query()->where('code', 'uz')->exists())->toBeFalse()
        ->and(currentClub()->locale)->toBeNull();
});

test('club roles cannot manage the languages shared by all clubs', function (string $role) {
    $user = memberAs($role);
    $kk = Language::query()->where('code', 'kk')->firstOrFail();

    $this->actingAs($user)->post(route('languages.store'), ['code' => 'uz', 'name' => 'Uz'])->assertForbidden();
    $this->get(route('languages.translations', $kk))->assertForbidden();
    $this->patch(route('languages.update', $kk), ['is_enabled' => 0])->assertForbidden();
    $this->put(route('languages.translations.save', $kk), ['translations' => []])->assertForbidden();
    $this->delete(route('languages.destroy', $kk))->assertForbidden();
    expect(Language::query()->where('code', 'uz')->exists())->toBeFalse()
        ->and($kk->refresh()->is_enabled)->toBeTrue();
})->with(['owner', 'admin', 'manager', 'cashier']);

test('languages have their own settings tab', function () {
    $owner = User::factory()->create();

    // A club owner only picks the club language; managing languages is for the superadmin.
    $this->actingAs($owner)->get(route('settings.languages'))
        ->assertOk()
        ->assertSee(__('Язык интерфейса'))
        ->assertDontSee(__('Добавить язык'));
    // The superadmin sees a link to the cabinet, where languages are managed.
    $this->actingAs(User::factory()->superadmin()->create())->get(route('settings.languages'))
        ->assertOk()
        ->assertDontSee(__('Добавить язык'))
        ->assertSee(route('superadmin.languages'));
    $this->get(route('superadmin.languages'))->assertOk()->assertSee(__('Добавить язык'));
    $this->get(route('settings.club'))
        ->assertOk()
        ->assertSee(route('settings.languages'))
        ->assertDontSee(__('Добавить язык'));
});
