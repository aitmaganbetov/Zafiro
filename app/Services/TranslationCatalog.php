<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Translation;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Translations live in MySQL and are shared by all clubs. The source texts ship
 * with the code in database/translations/{code}.php; Russian is the source
 * language (its texts are the translation keys).
 *
 * Cache layout (one shared store, safe across PHP processes and servers):
 * - translations.state           {version, enabled codes, stored groups} – 1 read per request
 * - translations.v{N}.pack.{code} every group of one language, the JSON group
 *                                 already merged over the English fallback – 1 read per locale
 * Changing a translation bumps the version, so every process switches to the new
 * packs atomically; packs of the previous version are deleted and also expire.
 */
class TranslationCatalog
{
    public const SOURCE_LOCALE = 'ru';

    public const FALLBACK_LOCALE = 'en';

    private const STATE_KEY = 'translations.state';

    private const PACK_TTL_SECONDS = 604800; // 7 days; packs are rebuilt on change anyway

    /** @var array{version: int, enabled: list<string>, groups: list<string>}|null */
    private ?array $state = null;

    /** @var array<string, array<string, array<string, string>>> packs loaded by this process */
    private array $packs = [];

    /**
     * Translations of one locale and group (database rows, fallback included for '*').
     *
     * @return array<string, string>|null null when the group is not stored in the database
     */
    public function lines(string $locale, string $group): ?array
    {
        // Laravel retries a missing phrase as "group.item" in every fallback locale;
        // groups that are not stored in the database must not load any pack.
        if (! in_array($group, $this->state()['groups'], true)) {
            return null;
        }

        return $this->pack($locale)[$group] ?? null;
    }

    /** @return list<string> */
    public function enabledCodes(): array
    {
        return $this->state()['enabled'];
    }

    /** Grows on every change of languages or translations: the PC agent refetches its texts then. */
    public function version(): int
    {
        return $this->state()['version'];
    }

    /**
     * One group of a shipped translation file (database/translations/{code}.php).
     *
     * @return array<string, string>
     */
    public function shippedGroup(string $code, string $group): array
    {
        return $this->shipped($code)[$group] ?? [];
    }

    public function flush(): void
    {
        $previous = $this->state()['version'];
        $this->state = ['version' => $previous + 1, ...($this->loadMeta() ?? $this->defaultMeta())];
        Cache::forever(self::STATE_KEY, $this->state);
        $this->packs = [];

        try {
            foreach (DB::table('languages')->pluck('code') as $code) {
                Cache::forget($this->packKey($previous, $code));
            }
        } catch (QueryException) {
            // old packs expire on their own
        }

        // The translator keeps loaded groups for the process lifetime (tests, queue workers).
        if (app()->resolved('translator')) {
            app('translator')->setLoaded([]);
        }
    }

    /**
     * Source keys shipped with the code: [group => [key => russian text]].
     *
     * @return array<string, array<string, string>>
     */
    public function sourceKeys(): array
    {
        return $this->shipped(self::SOURCE_LOCALE);
    }

    public function sourceKeyCount(): int
    {
        return collect($this->sourceKeys())->sum(fn (array $lines): int => count($lines));
    }

    /**
     * Insert shipped translations that are missing in the database. Existing rows
     * (possibly edited in the UI) are never overwritten. Returns inserted row count.
     */
    public function sync(): int
    {
        $inserted = 0;
        $now = now();

        foreach (Language::query()->get() as $language) {
            $existing = Translation::query()->where('language_id', $language->id)->pluck('key_hash')->flip();
            $rows = [];

            foreach ($this->shipped($language->code) as $group => $lines) {
                foreach ($lines as $key => $value) {
                    $hash = Translation::hashKey($group, (string) $key);

                    if (! $existing->has($hash)) {
                        $rows[] = ['language_id' => $language->id, 'group' => $group, 'key' => (string) $key, 'key_hash' => $hash, 'value' => $value, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                Translation::query()->insert($chunk);
            }
            $inserted += count($rows);
        }

        if ($inserted > 0) {
            $this->flush();
        }

        return $inserted;
    }

    /** @return array{version: int, enabled: list<string>} */
    private function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $state = Cache::get(self::STATE_KEY);

        if (! is_array($state) || ! isset($state['version'], $state['enabled'], $state['groups'])) {
            $meta = $this->loadMeta();
            $state = ['version' => is_array($state) ? (int) ($state['version'] ?? 1) : 1, ...($meta ?? $this->defaultMeta())];

            if ($meta === null) {
                return $this->state = $state; // database unavailable: do not cache a guess
            }

            Cache::forever(self::STATE_KEY, $state);
        }

        return $this->state = $state;
    }

    /** @return array<string, array<string, string>> */
    private function pack(string $locale): array
    {
        if (isset($this->packs[$locale])) {
            return $this->packs[$locale];
        }

        $key = $this->packKey($this->state()['version'], $locale);
        $pack = Cache::get($key);

        if (! is_array($pack)) {
            $pack = $this->rebuild($key, $locale);
        }

        return $this->packs[$locale] = $pack;
    }

    /**
     * One process rebuilds a missing pack while concurrent requests wait for it,
     * so a translation change does not trigger a burst of identical queries.
     *
     * @return array<string, array<string, string>>
     */
    private function rebuild(string $key, string $locale): array
    {
        $build = function () use ($key, $locale): array {
            $cached = Cache::get($key);

            if (is_array($cached)) {
                return $cached;
            }

            $pack = $this->buildPack($locale);

            if ($pack !== null) {
                Cache::put($key, $pack, self::PACK_TTL_SECONDS);
            }

            return $pack ?? [];
        };

        try {
            return Cache::lock("{$key}.building", 10)->block(5, $build);
        } catch (LockTimeoutException) {
            return $build();
        }
    }

    /**
     * All groups of one locale in a single query; null when the database is unavailable.
     *
     * @return array<string, array<string, string>>|null
     */
    private function buildPack(string $locale): ?array
    {
        $locales = $locale === self::SOURCE_LOCALE || $locale === self::FALLBACK_LOCALE
            ? [$locale]
            : [self::FALLBACK_LOCALE, $locale];

        try {
            $rows = DB::table('translations')
                ->join('languages', 'languages.id', '=', 'translations.language_id')
                ->whereIn('languages.code', $locales)
                ->get(['languages.code', 'translations.group', 'translations.key', 'translations.value']);
        } catch (QueryException) {
            return null;
        }

        $pack = [];

        // The fallback language goes first so the requested language overrides it. Only
        // JSON phrases fall back here; grouped lines fall back through Laravel's own files.
        foreach ($rows->sortBy(fn (object $row): int => $row->code === $locale ? 1 : 0) as $row) {
            if ($row->code !== $locale && $row->group !== '*') {
                continue;
            }

            $pack[$row->group][$row->key] = $row->value;
        }

        return $pack;
    }

    /** @return array{enabled: list<string>, groups: list<string>}|null null when the database is unavailable */
    private function loadMeta(): ?array
    {
        try {
            return [
                'enabled' => DB::table('languages')->where('is_enabled', true)->orderBy('sort_order')->orderBy('id')->pluck('code')->all(),
                'groups' => DB::table('translations')->distinct()->pluck('group')->all(),
            ];
        } catch (QueryException) {
            return null;
        }
    }

    /** @return array{enabled: list<string>, groups: list<string>} */
    private function defaultMeta(): array
    {
        return ['enabled' => [self::SOURCE_LOCALE, self::FALLBACK_LOCALE], 'groups' => ['*']];
    }

    private function packKey(int $version, string $locale): string
    {
        return "translations.v{$version}.pack.{$locale}";
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function shipped(string $code): array
    {
        $path = database_path("translations/{$code}.php");

        if (! is_file($path)) {
            return [];
        }

        $data = require $path;

        return collect($data)
            ->map(fn (array $lines, string $group): array => $group === '*' ? $lines : Arr::dot($lines))
            ->all();
    }
}
