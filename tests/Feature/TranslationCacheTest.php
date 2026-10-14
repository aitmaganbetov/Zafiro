<?php

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Language;
use App\Models\Translation;
use App\Models\User;
use App\Services\CurrentClub;
use App\Services\TranslationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
});

// What a new PHP-FPM worker starts with: no translations in process memory.
function newProcess(): void
{
    app('translator')->setLoaded([]);
    app()->forgetInstance(TranslationCatalog::class);
    app()->forgetScopedInstances();
    app()->extend('translation.loader', fn ($loader) => $loader);
    (function () {
        $this->catalog = app(TranslationCatalog::class);
    })->call(app('translation.loader'));
}

/** @return list<string> SQL statements run by $callback */
function sqlOf(callable $callback): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });
    $callback();
    DB::flushQueryLog();
    app('events')->forget('Illuminate\Database\Events\QueryExecuted');

    return $sql;
}

function translationQueries(array $sql): array
{
    return array_values(array_filter($sql, fn (string $q): bool => preg_match('/\b(translations|languages)\b/', $q) === 1));
}

function clubWithLocale(?string $locale): Club
{
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['locale' => $locale])->save();

    return $club;
}

test('a cache miss loads the selected language in one query and only the languages it needs', function () {
    $user = User::factory()->create();
    clubWithLocale('kk');
    newProcess();

    $sql = sqlOf(fn () => $this->actingAs($user)->get(route('dashboard'))->assertSee('Қойма'));
    $queries = translationQueries($sql);

    $packQueries = array_values(array_filter($queries, fn (string $q): bool => str_contains($q, 'inner join "languages"')));
    expect($packQueries)->toHaveCount(1)
        ->and(substr_count($packQueries[0], '?'))->toBe(2); // kk + its English fallback, not ru
});

test('a cache hit does not query translation tables at all', function () {
    $user = User::factory()->create();
    clubWithLocale('kk');
    $this->actingAs($user)->get(route('dashboard'));
    newProcess();

    $sql = sqlOf(fn () => $this->actingAs($user)->get(route('dashboard'))->assertSee('Қойма'));

    expect(translationQueries($sql))->toBe([]);
});

test('missing phrases fall back without touching the database', function () {
    newProcess();
    app()->setLocale('kk');
    __('Склад');

    $sql = sqlOf(function () {
        expect(__('Совсем новая фраза. С точкой'))->toBe('Совсем новая фраза. С точкой')
            ->and(__('(and :count more errors)', ['count' => 2]))->toBe('(and 2 more errors)');
    });

    expect($sql)->toBe([]);
});

test('editing a translation is visible on the next request and old packs are removed', function () {
    $owner = User::factory()->superadmin()->create();
    clubWithLocale('kk');
    $this->actingAs($owner)->get(route('dashboard'))->assertSee('Қойма');
    $oldVersion = Cache::get('translations.state')['version'];
    expect(Cache::has("translations.v{$oldVersion}.pack.kk"))->toBeTrue();

    $kk = Language::query()->where('code', 'kk')->firstOrFail();
    $this->put(route('languages.translations.save', $kk), ['translations' => [Translation::hashKey('*', 'Склад') => 'Қойма (жаңа)']]);
    newProcess();

    $this->get(route('dashboard'))->assertSee('Қойма (жаңа)');
    expect(Cache::has("translations.v{$oldVersion}.pack.kk"))->toBeFalse()
        ->and(Cache::get('translations.state')['version'])->toBe($oldVersion + 1);
});

test('clubs with different languages never see each other\'s language', function () {
    $kazakhClub = clubWithLocale('kk');
    $kazakhOwner = User::factory()->create();
    $englishClub = Club::query()->create(['name' => 'English club', 'slug' => 'english-club']);
    $englishClub->forceFill(['locale' => 'en'])->save();
    DB::table('club_subscriptions')->insert(['club_id' => $englishClub->id, 'saas_plan_id' => 1, 'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $englishOwner = User::query()->create(['name' => 'Eve', 'phone' => '+77000000111', 'password' => 'secret123']);
    DB::table('club_memberships')->insert(['club_id' => $englishClub->id, 'user_id' => $englishOwner->id, 'role' => 'owner', 'discount' => 0]);

    foreach (range(1, 3) as $round) {
        newProcess();
        $this->flushSession();
        $this->actingAs($kazakhOwner)->get(route('dashboard'))->assertSee('Қойма')->assertDontSee('Warehouse');
        newProcess();
        $this->flushSession();
        $this->actingAs($englishOwner)->get(route('dashboard'))->assertSee('Warehouse')->assertDontSee('Қойма');
    }

    expect($kazakhClub->refresh()->locale)->toBe('kk')->and($englishClub->refresh()->locale)->toBe('en');
});

test('a database failure is not cached as empty translations', function () {
    newProcess();
    Schema::rename('translations', 'translations_tmp');
    app()->setLocale('kk');
    expect(__('Склад'))->toBe('Склад');
    Schema::rename('translations_tmp', 'translations');

    newProcess();
    app()->setLocale('kk');
    expect(__('Склад'))->toBe('Қойма');
});

test('re-running sync keeps manual edits and does not invalidate the cache', function () {
    $kk = Language::query()->where('code', 'kk')->firstOrFail();
    Translation::query()->where('language_id', $kk->id)->where('key', 'Склад')->update(['value' => 'Қолмен өзгертілген']);
    $catalog = app(TranslationCatalog::class);
    $catalog->flush();
    $version = Cache::get('translations.state')['version'];

    expect($catalog->sync())->toBe(0)
        ->and(Cache::get('translations.state')['version'])->toBe($version)
        ->and(Translation::query()->where('language_id', $kk->id)->where('key', 'Склад')->value('value'))->toBe('Қолмен өзгертілген');
});

test('laravel validation errors are localized in every language', function (string $locale, string $message) {
    $user = User::factory()->create();
    clubWithLocale($locale);

    $this->actingAs($user)->post(route('club-map.store'), [])->assertSessionHasErrors(['name' => $message]);
})->with([
    'russian' => ['ru', 'Поле «имя» обязательно.'],
    'kazakh' => ['kk', '«аты» өрісі міндетті.'],
    'english' => ['en', 'The name field is required.'],
]);

test('saving translations costs the same number of queries for 10 or 50 rows', function () {
    $owner = User::factory()->superadmin()->create();
    $kk = Language::query()->where('code', 'kk')->firstOrFail();
    $save = function (int $rows, string $mark) use ($kk) {
        $payload = Translation::query()->where('language_id', $kk->id)->where('group', '*')->orderBy('id')->limit($rows)->get()
            ->mapWithKeys(fn (Translation $t): array => [$t->key_hash => $t->value.$mark])->all();

        return count(sqlOf(fn () => $this->put(route('languages.translations.save', $kk), ['translations' => $payload])->assertRedirect()));
    };
    $this->actingAs($owner);
    $save(5, ' ·'); // warm-up: first request builds caches

    $ten = $save(10, ' •');
    $fifty = $save(50, ' ✓');

    expect($fifty)->toBe($ten)
        ->and(Translation::query()->where('language_id', $kk->id)->where('value', 'like', '% ✓')->count())->toBe(50);
});

test('the translation editor is limited to the superadmin', function () {
    $cashier = User::factory()->create();
    ClubMembership::query()->where('user_id', $cashier->id)->update(['role' => 'cashier']);
    $kk = Language::query()->where('code', 'kk')->firstOrFail();

    $this->actingAs($cashier)->put(route('languages.translations.save', $kk), ['translations' => []])->assertForbidden();
    $this->actingAs(User::factory()->create())->put(route('languages.translations.save', $kk), ['translations' => []])->assertForbidden();
});
