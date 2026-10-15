<?php

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\News;
use App\Models\PlatformLog;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

/** A real PNG without GD. */
function newsPng(): UploadedFile
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $rows = str_repeat("\0".str_repeat("\xff\x00\x00", 80), 80);
    $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 80, 80, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($rows)).$chunk('IEND', '');

    return UploadedFile::fake()->createWithContent('news.png', $png);
}

function newsAgentHeaders(User $user): array
{
    app('auth')->forgetGuards();
    $clubId = ClubMembership::query()->withoutGlobalScope('club')->where('user_id', $user->id)->value('club_id');
    app(CurrentClub::class)->clear();

    return ['Authorization' => 'Bearer '.$user->createToken('PC', ['club:'.$clubId])->plainTextToken, 'Accept' => 'application/json'];
}

test('club staff publish club news with a picture, edit and delete it', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->get(route('dashboard'))->assertSee(route('news.index'), false);

    $this->post(route('news.store'), ['title' => 'Турнир в субботу', 'body' => "Регистрация до пятницы.\nПризы!", 'is_published' => 1, 'image' => newsPng()])
        ->assertSessionHasNoErrors();
    $news = News::query()->sole();
    expect($news->club_id)->toBe(app(CurrentClub::class)->id())
        ->and($news->image_path)->toStartWith('news/'.$news->club_id.'/');
    Storage::disk('local')->assertExists($news->image_path);
    $this->get(route('news.index'))->assertOk()->assertSee('Турнир в субботу');
    $this->get($news->imageUrl())->assertOk();

    $old = $news->image_path;
    $this->patch(route('news.update', $news), ['title' => 'Турнир перенесён', 'body' => 'В воскресенье', 'is_published' => 0, 'remove_image' => 1])->assertSessionHasNoErrors();
    expect($news->fresh())->title->toBe('Турнир перенесён')->is_published->toBeFalse()->image_path->toBeNull();
    Storage::disk('local')->assertMissing($old);

    $this->delete(route('news.destroy', $news))->assertSessionHasNoErrors();
    expect(News::query()->count())->toBe(0)
        ->and(ActivityLog::query()->whereIn('event', ['news.created', 'news.updated', 'news.deleted'])->count())->toBe(3);
});

test('a club cannot touch platform news or news of another club; cashiers cannot manage news', function () {
    $owner = User::factory()->create();
    $platform = News::query()->forceCreate(['club_id' => null, 'title' => 'Платформенная новость', 'body' => 'x']);
    $other = Club::query()->create(['name' => 'Other', 'slug' => 'other']);
    $foreign = News::query()->forceCreate(['club_id' => $other->id, 'title' => 'Чужая', 'body' => 'x']);

    $this->actingAs($owner)->delete(route('news.destroy', $platform))->assertNotFound();
    $this->delete(route('news.destroy', $foreign))->assertNotFound();
    $this->get(route('news.index'))->assertOk()->assertDontSee('Чужая')->assertDontSee('Платформенная новость');

    ClubMembership::query()->where('user_id', $owner->id)->update(['role' => 'cashier']);
    $this->get(route('news.index'))->assertForbidden();
});

test('the superadmin publishes Zafiro news for every club', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_superadmin' => true])->save();
    $club = News::query()->forceCreate(['club_id' => app(CurrentClub::class)->id(), 'title' => 'Клубная', 'body' => 'x']);

    $this->actingAs($admin)->post(route('superadmin.news.store'), ['title' => 'Новая версия агента', 'body' => 'Обновитесь', 'is_published' => 1])->assertSessionHasNoErrors();
    $news = News::query()->platform()->sole();
    $this->get(route('superadmin.news.index'))->assertOk()->assertSee('Новая версия агента')->assertDontSee('Клубная');
    $this->patch(route('superadmin.news.update', $club), ['title' => 'x', 'body' => 'y'])->assertNotFound();
    $this->patch(route('superadmin.news.update', $news), ['title' => 'Агент 0.0.36', 'body' => 'Обновитесь', 'is_published' => 1])->assertSessionHasNoErrors();

    expect($news->fresh()->title)->toBe('Агент 0.0.36')
        ->and(PlatformLog::query()->where('event', 'superadmin.news_created')->exists())->toBeTrue();
});

test('the agent gets published Zafiro news and news of its own club, newest first', function () {
    $customer = User::factory()->create();
    $clubId = app(CurrentClub::class)->id();
    $other = Club::query()->create(['name' => 'Other', 'slug' => 'other']);
    News::query()->forceCreate(['club_id' => null, 'title' => 'Zafiro старая', 'body' => 'a', 'created_at' => now()->subDay()]);
    News::query()->forceCreate(['club_id' => null, 'title' => 'Zafiro новая', 'body' => 'b']);
    News::query()->forceCreate(['club_id' => null, 'title' => 'Черновик', 'body' => 'c', 'is_published' => false]);
    News::query()->forceCreate(['club_id' => $clubId, 'title' => 'Наш турнир', 'body' => 'd']);
    News::query()->forceCreate(['club_id' => $other->id, 'title' => 'Чужой клуб', 'body' => 'e']);

    $this->getJson(route('api.guest.news'), newsAgentHeaders($customer))->assertOk()
        ->assertJsonCount(2, 'platform')
        ->assertJsonPath('platform.0.title', 'Zafiro новая')
        ->assertJsonPath('platform.1.title', 'Zafiro старая')
        ->assertJsonCount(1, 'club')
        ->assertJsonPath('club.0.title', 'Наш турнир')
        ->assertJsonPath('club.0.image_url', null);
});
