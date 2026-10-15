<?php

use App\Console\Commands\ExportAgentTranslations;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Language;
use App\Models\Translation;
use App\Models\User;
use App\Services\ClubComputerSessions;
use App\Services\CurrentClub;
use App\Services\TranslationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Every text the agent shows: the key (Russian source) as written in its code. */
function agentSourceKeys(): array
{
    $root = base_path('WindowsAgent');
    $keys = [];

    // C#: L.T("…")
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/ZafiroAgent")) as $file) {
        if ($file->getExtension() !== 'cs' || preg_match('#[/\\\\](bin|obj)[/\\\\]#', $file->getPathname())) {
            continue;
        }
        preg_match_all('/\bL\.T\("((?:[^"\\\\]|\\\\.)*)"/u', file_get_contents($file->getPathname()), $matches);
        array_push($keys, ...array_map('stripcslashes', $matches[1]));
    }

    // Page: data-i18n="…" and data-i18n-*="…"
    preg_match_all('/data-i18n(?:-[a-z-]+)?="([^"]+)"/u', file_get_contents("{$root}/Frontend/index.html"), $matches);
    array_push($keys, ...$matches[1]);

    // Script: every Cyrillic string except product category stems and the language labels.
    preg_match_all("/'([^'\\n]*\\p{Cyrillic}[^'\\n]*)'/u", file_get_contents("{$root}/Frontend/main.js"), $matches);
    array_push($keys, ...array_diff($matches[1], ['нап', 'пер', 'ед', 'пищ', 'акс', 'тех', 'игр', 'ҚАЗ']));

    return array_values(array_unique($keys));
}

/** @return list<string> */
function placeholders(string $text): array
{
    preg_match_all('/:([a-z]+)/', $text, $matches);
    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

test('every agent text is in the catalog and translated to kazakh and english with the same placeholders', function () {
    $catalog = app(TranslationCatalog::class);
    $source = $catalog->shippedGroup('ru', 'agent');
    $used = agentSourceKeys();

    expect(count($used))->toBeGreaterThan(150)
        ->and(array_values(array_diff($used, array_keys($source))))->toBe([]);

    foreach (['kk', 'en'] as $locale) {
        $lines = $catalog->shippedGroup($locale, 'agent');
        expect(array_values(array_diff(array_keys($source), array_keys($lines))))->toBe([], "missing in {$locale}");

        foreach ($source as $key => $text) {
            expect(placeholders($lines[$key]))->toBe(placeholders($text), "placeholders of «{$key}» in {$locale}")
                ->and(trim($lines[$key]))->not->toBe('');
        }
    }
});

test('the texts shipped with the agent match the catalog (php artisan agent:translations)', function () {
    $catalog = app(TranslationCatalog::class);

    foreach (ExportAgentTranslations::LANGUAGES as $code => $name) {
        $shipped = file_get_contents(base_path("WindowsAgent/Frontend/i18n/{$code}.json"));

        expect(json_decode($shipped, true))->toBe(json_decode(ExportAgentTranslations::json($code, $name, $catalog->shippedGroup($code, 'agent')), true));
    }
});

test('the agent gets its texts with the superadmin\'s edits and a version that changes with them', function () {
    $catalog = app(TranslationCatalog::class);
    $first = $this->getJson(route('api.agent.translations', 'kk'))->assertOk()
        ->assertJsonPath('locale', 'kk')
        ->assertJsonPath('lines.Магазин', 'Дүкен');

    $kk = Language::query()->where('code', 'kk')->firstOrFail();
    Translation::query()->where('language_id', $kk->id)->where('group', 'agent')->where('key', 'Магазин')->update(['value' => 'Клуб дүкені']);
    $catalog->flush();

    $this->getJson(route('api.agent.translations', 'kk'))->assertOk()
        ->assertJsonPath('lines.Магазин', 'Клуб дүкені')
        ->assertJsonPath('version', $first->json('version') + 1);

    $this->getJson(route('api.agent.translations', 'xx'))->assertNotFound();
    Language::query()->where('code', 'en')->update(['is_enabled' => false]);
    $catalog->flush();
    $this->getJson(route('api.agent.translations', 'en'))->assertNotFound();
});

test('the heartbeat tells the agent the club language, the enabled languages and the translations version', function () {
    User::factory()->create();
    Club::query()->findOrFail(app(CurrentClub::class)->id())->forceFill(['locale' => 'kk'])->save();
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $token = app(ClubComputerSessions::class)->issueAgentToken(ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]));
    app(CurrentClub::class)->clear();

    $response = $this->postJson(route('api.agent.heartbeat'), [], ['X-Agent-Token' => $token])->assertOk()
        ->assertJsonPath('club.locale', 'kk')
        ->assertJsonPath('club.translations_version', app(TranslationCatalog::class)->version());

    expect(collect($response->json('club.languages'))->pluck('code')->all())->toContain('ru', 'kk', 'en')
        ->and(collect($response->json('club.languages'))->firstWhere('code', 'kk')['name'])->toBe('Қазақша');
});

test('server messages to the agent follow the language picked on the PC, else the club language', function () {
    User::factory()->create();
    $club = Club::query()->findOrFail(app(CurrentClub::class)->id());
    $club->forceFill(['locale' => 'en', 'agent_exit_pin_hash' => bcrypt('4821')])->save();
    $zone = ClubZone::query()->create(['name' => 'Hall']);
    $token = app(ClubComputerSessions::class)->issueAgentToken(ClubComputer::query()->create(['club_zone_id' => $zone->id, 'number' => 1]));
    app(CurrentClub::class)->clear();

    $this->postJson(route('api.agent.exit-pin'), ['pin' => '0000'], ['X-Agent-Token' => $token, 'X-Agent-Locale' => 'kk'])
        ->assertUnprocessable()->assertJsonPath('message', 'PIN-код қате.');
    $this->postJson(route('api.agent.exit-pin'), ['pin' => '0000'], ['X-Agent-Token' => $token])
        ->assertUnprocessable()->assertJsonPath('message', 'Wrong PIN.');
    $this->postJson(route('api.agent.exit-pin'), ['pin' => '0000'], ['X-Agent-Token' => $token, 'X-Agent-Locale' => 'zz'])
        ->assertUnprocessable()->assertJsonPath('message', 'Wrong PIN.');
});
