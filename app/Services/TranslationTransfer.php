<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Translation;
use Illuminate\Validation\ValidationException;

/**
 * Translations of one language as a CSV file for translators (Excel, Google Sheets)
 * and back. Columns: id; group; ru (source); en (hint); <language code> (translation).
 * The id is the translation key hash, so rows survive reordering and edits of the
 * other columns. Importing changes only filled-in cells: empty cells never delete
 * a translation, and rows whose placeholders (:name) differ from the source are
 * skipped and reported.
 */
class TranslationTransfer
{
    public const DELIMITER = ';';

    private const MAX_ROWS = 10000;

    public function __construct(private TranslationCatalog $catalog) {}

    /**
     * Header and rows of the export.
     *
     * @return list<list<string>>
     */
    public function exportRows(Language $language, bool $missingOnly = false): array
    {
        $values = Translation::query()->where('language_id', $language->id)->pluck('value', 'key_hash');
        $english = $language->code === TranslationCatalog::FALLBACK_LOCALE
            ? collect()
            : Translation::query()->where('language_id', Language::query()->where('code', TranslationCatalog::FALLBACK_LOCALE)->value('id'))->pluck('value', 'key_hash');
        $rows = [['id', 'group', TranslationCatalog::SOURCE_LOCALE, TranslationCatalog::FALLBACK_LOCALE, $language->code]];

        foreach ($this->sources() as $hash => [$group, , $source]) {
            $value = (string) ($values[$hash] ?? '');

            if ($missingOnly && $value !== '') {
                continue;
            }

            $rows[] = array_map($this->protectCell(...), [$hash, $group, $source, (string) ($english[$hash] ?? ''), $value]);
        }

        return $rows;
    }

    /**
     * @return array{changed: int, unchanged: int, empty: int, unknown: int, invalid: list<string>}
     */
    public function import(Language $language, string $contents): array
    {
        $rows = $this->parse($contents);
        $header = array_map(fn (string $cell): string => mb_strtolower(trim($cell)), array_shift($rows) ?? []);

        if (($header[0] ?? null) !== 'id' || count($header) < 5) {
            throw ValidationException::withMessages(['file' => __('Это не файл переводов Zafiro: нужна первая строка «id;group;ru;en;код языка».')]);
        }

        if ($header[4] !== $language->code) {
            throw ValidationException::withMessages(['file' => __('Файл выгружен для языка «:file», а импорт идёт в «:language».', ['file' => $header[4], 'language' => $language->code])]);
        }

        $sources = $this->sources();
        $stats = ['changed' => 0, 'unchanged' => 0, 'empty' => 0, 'unknown' => 0, 'invalid' => []];
        $submitted = [];

        foreach ($rows as $row) {
            $hash = trim((string) ($row[0] ?? ''));
            $value = trim($this->unprotectCell((string) ($row[4] ?? '')));

            if ($hash === '' && $value === '') {
                continue;
            }

            if (! isset($sources[$hash])) {
                $stats['unknown']++;

                continue;
            }

            if ($value === '') {
                $stats['empty']++;

                continue;
            }

            if (! $this->placeholdersMatch($sources[$hash][2], $value)) {
                $stats['invalid'][] = $sources[$hash][2];

                continue;
            }

            $submitted[$hash] = $value;
        }

        $stats['changed'] = $this->apply($language, $submitted, deleteEmpty: false);
        $stats['unchanged'] = count($submitted) - $stats['changed'];

        return $stats;
    }

    /**
     * Upsert translations given as [key hash => value]; unknown hashes are ignored.
     * Returns the number of rows inserted, updated or deleted.
     *
     * @param  array<string, string|null>  $values
     */
    public function apply(Language $language, array $values, bool $deleteEmpty): int
    {
        $sources = $this->sources();
        $values = array_intersect_key($values, $sources);

        if ($values === []) {
            return 0;
        }

        $existing = Translation::query()
            ->where('language_id', $language->id)
            ->whereIn('key_hash', array_keys($values))
            ->get(['id', 'key_hash', 'value'])
            ->keyBy('key_hash');
        $deletes = [];
        $upserts = [];
        $now = now();

        foreach ($values as $hash => $value) {
            [$group, $key] = $sources[$hash];
            $value = trim((string) $value);
            $row = $existing->get($hash);

            if ($value === '') {
                if ($deleteEmpty && $row !== null) {
                    $deletes[] = $row->id;
                }
            } elseif ($row?->value !== $value) {
                $upserts[] = ['language_id' => $language->id, 'group' => $group, 'key' => $key, 'key_hash' => $hash, 'value' => $value, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($deletes, 500) as $chunk) {
            Translation::query()->whereKey($chunk)->delete();
        }

        foreach (array_chunk($upserts, 200) as $chunk) {
            Translation::query()->upsert($chunk, ['language_id', 'group', 'key_hash'], ['value', 'updated_at']);
        }

        return count($deletes) + count($upserts);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> hash => [group, key, russian text] */
    private function sources(): array
    {
        $sources = [];

        foreach ($this->catalog->sourceKeys() as $group => $lines) {
            foreach ($lines as $key => $source) {
                $sources[Translation::hashKey($group, (string) $key)] = [$group, (string) $key, (string) $source];
            }
        }

        return $sources;
    }

    private function placeholdersMatch(string $source, string $value): bool
    {
        $names = function (string $text): array {
            preg_match_all('/:[a-z_]+/i', $text, $m);
            $found = array_unique(array_map('strtolower', $m[0]));
            sort($found);

            return $found;
        };

        return $names($source) === $names($value);
    }

    /** @return list<list<string>> */
    private function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => __('Файл должен быть в кодировке UTF-8. В Excel выберите «CSV UTF-8».')]);
        }

        $firstLine = strtok($contents, "\n") ?: '';
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn (string $d): int => substr_count($firstLine, $d))->first();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => __('В файле слишком много строк.')]);
            }

            $rows[] = array_map(fn (?string $cell): string => (string) $cell, $row);
        }

        fclose($stream);

        return $rows;
    }

    /** Spreadsheet apps run cells starting with = + - @ as formulas; such cells get a leading apostrophe. */
    private function protectCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    private function unprotectCell(string $value): string
    {
        return preg_match("/^'[=+\\-@\\t\\r]/", $value) === 1 ? substr($value, 1) : $value;
    }
}
