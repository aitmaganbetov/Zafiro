<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Translation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Translations live in the database and are shared by all clubs. The source texts
 * ship with the code in database/translations/{code}.php; Russian is the source
 * language (its texts are the translation keys).
 */
class TranslationCatalog
{
    public const SOURCE_LOCALE = 'ru';

    private const CACHE_VERSION_KEY = 'translations.version';

    /** @var array<string, array<string, string>> */
    private array $memory = [];

    /**
     * Rows for one locale and group, cached until translations change.
     *
     * @return array<string, string>
     */
    public function lines(string $locale, string $group): array
    {
        $cacheKey = "translations.{$this->version()}.{$locale}.{$group}";

        return $this->memory[$cacheKey] ??= Cache::rememberForever($cacheKey, function () use ($locale, $group): array {
            try {
                return DB::table('translations')
                    ->join('languages', 'languages.id', '=', 'translations.language_id')
                    ->where('languages.code', $locale)
                    ->where('translations.group', $group)
                    ->pluck('translations.value', 'translations.key')
                    ->all();
            } catch (QueryException) {
                return []; // tables not migrated yet
            }
        });
    }

    public function flush(): void
    {
        Cache::forever(self::CACHE_VERSION_KEY, $this->version() + 1);
        $this->memory = [];
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

        $this->flush();

        return $inserted;
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

    private function version(): int
    {
        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }
}
