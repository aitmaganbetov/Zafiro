<?php

use App\Models\Club;
use App\Models\Language;
use App\Models\Translation;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\LocaleResolver;
use App\Services\TranslationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function putTranslation(string $code, string $key, string $value, string $group = '*'): void
{
    Translation::query()->updateOrCreate(
        ['language_id' => Language::query()->where('code', $code)->value('id'), 'group' => $group, 'key_hash' => Translation::hashKey($group, $key)],
        ['key' => $key, 'value' => $value],
    );
    app(TranslationCatalog::class)->flush();
}

test('russian, kazakh and english are the built-in languages', function () {
    expect(Language::query()->orderBy('sort_order')->pluck('code')->all())->toBe(['ru', 'kk', 'en'])
        ->and(Language::query()->where('is_system', true)->count())->toBe(3);
});

test('guests get their browser language or english', function (string $header, string $expected) {
    $request = Request::create('/login', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => $header]);

    expect(app(LocaleResolver::class)->forGuest($request))->toBe($expected);
})->with([
    'kazakh' => ['kk-KZ,kk;q=0.9,ru;q=0.8', 'kk'],
    'russian region' => ['ru-RU,ru;q=0.9', 'ru'],
    'unsupported' => ['de-DE,de;q=0.9', 'en'],
    'empty' => ['', 'en'],
]);

test('guest pages are rendered in the browser language', function () {
    app(CurrentClub::class)->clear();
    $this->get(route('auth.show-login'), ['Accept-Language' => 'kk-KZ,kk;q=0.9'])->assertSee('<html lang="kk">', false);
    $this->get(route('auth.show-login'), ['Accept-Language' => 'fr-FR'])->assertSee('<html lang="en">', false);
});

test('a disabled language is not offered to guests', function () {
    Language::query()->where('code', 'kk')->update(['is_enabled' => false]);
    app(TranslationCatalog::class)->flush();
    app()->forgetScopedInstances();

    $request = Request::create('/login', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'kk-KZ,kk']);
    expect(app(LocaleResolver::class)->forGuest($request))->toBe('en');
});

test('signed-in pages use the club language and database translations', function () {
    $user = User::factory()->create();
    Club::query()->whereKey(app(CurrentClub::class)->id())->update(['locale' => 'kk']);
    putTranslation('kk', 'Тестовая строка', 'Сынақ жолы');

    $this->actingAs($user)->get(route('dashboard'))->assertSuccessful()->assertSee('<html lang="kk">', false);

    app()->setLocale('kk');
    expect(__('Тестовая строка'))->toBe('Сынақ жолы');
});

test('missing translations fall back to english, then to the russian source text', function () {
    app()->setLocale('kk');
    putTranslation('en', 'Только английский', 'English only');

    expect(__('Только английский'))->toBe('English only')
        ->and(__('Нигде не переведено'))->toBe('Нигде не переведено');
});

test('sync adds missing rows but keeps edited translations', function () {
    putTranslation('kk', 'Пользовательский ключ', 'Өңделген');
    app(TranslationCatalog::class)->sync();

    expect(Translation::query()->where('key', 'Пользовательский ключ')->value('value'))->toBe('Өңделген');
});

test('the interface is fully translated to kazakh and english', function (string $locale, string $welcome, string $menu) {
    $user = User::factory()->create(['name' => 'Aigerim']);
    Club::query()->whereKey(app(CurrentClub::class)->id())->update(['locale' => $locale]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee($welcome)
        ->assertSee($menu)
        ->assertDontSee('Добро пожаловать');
})->with([
    'kazakh' => ['kk', 'Қош келдіңіз, Aigerim!', 'Қойма'],
    'english' => ['en', 'Welcome, Aigerim!', 'Warehouse'],
]);

test('validation errors and plural forms follow the club language', function () {
    $user = User::factory()->create();
    Club::query()->whereKey(app(CurrentClub::class)->id())->update(['locale' => 'kk']);

    $this->actingAs($user)->post(route('club-map.store'), [])
        ->assertSessionHasErrors(['name' => '«аты» өрісі міндетті.']);

    app()->setLocale('kk');
    expect(trans_choice(':count товар|:count товара|:count товаров', 5))->toBe('5 тауар');
    app()->setLocale('en');
    expect(trans_choice(':count товар|:count товара|:count товаров', 5))->toBe('5 products')
        ->and(trans_choice(':count товар|:count товара|:count товаров', 1))->toBe('1 product');
});
