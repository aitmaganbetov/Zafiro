<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Language;
use App\Models\Translation;
use App\Services\LocaleResolver;
use App\Services\PlatformLogger;
use App\Services\TranslationCatalog;
use App\Services\TranslationTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Interface languages and translations shared by every club: the superadmin adds and
 * switches off languages, edits, exports and imports translations. Clubs only pick one.
 */
class LanguageController extends Controller
{
    public function __construct(
        private TranslationCatalog $catalog,
        private PlatformLogger $platformLog,
    ) {}

    public function index(): View
    {
        // Only texts that still exist in the code count; rows of removed texts stay in the table.
        $sourceHashes = collect($this->catalog->sourceKeys())
            ->flatMap(fn (array $lines, string $group) => collect($lines)->keys()->map(fn ($key) => Translation::hashKey($group, (string) $key)))
            ->all();
        $translated = Translation::query()->whereIn('key_hash', $sourceHashes)->selectRaw('language_id, count(*) as aggregate')->groupBy('language_id')->pluck('aggregate', 'language_id');
        $clubs = Club::query()->whereNotNull('locale')->selectRaw('locale, count(*) as aggregate')->groupBy('locale')->pluck('aggregate', 'locale');

        return view('superadmin.languages', [
            'languages' => Language::query()->orderBy('sort_order')->orderBy('id')->get()
                ->each(fn (Language $language) => $language->setAttribute('translated_count', (int) ($translated[$language->id] ?? 0)))
                ->each(fn (Language $language) => $language->setAttribute('clubs_count', (int) ($clubs[$language->code] ?? 0))),
            'defaultLocale' => LocaleResolver::DEFAULT_CLUB_LOCALE,
            'translationTotal' => $this->catalog->sourceKeyCount(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', 'unique:languages,code'],
            'name' => ['required', 'string', 'max:64'],
        ], [
            'code.regex' => __('Код языка: 2–3 латинские буквы, например uz или tr.'),
        ]);

        DB::transaction(function () use ($data, $request): void {
            $language = Language::query()->create([
                ...$data,
                'is_enabled' => true,
                'sort_order' => (int) Language::query()->max('sort_order') + 1,
            ]);
            $this->platformLog->record('language.created', __('Добавлен язык: :name (:code)', ['name' => $language->name, 'code' => $language->code]), $request->user(), null, $language);
        });
        $this->catalog->flush();

        return back()->with('status', __('Язык добавлен. Теперь заполните переводы.'));
    }

    public function update(Request $request, Language $language): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:64'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_enabled', $data) && ! $data['is_enabled']) {
            // Clubs without their own choice use the default language, so it stays on too.
            if ($language->code === LocaleResolver::DEFAULT_CLUB_LOCALE || Club::query()->where('locale', $language->code)->exists()) {
                throw ValidationException::withMessages(['language' => __('Нельзя выключить язык, который выбран для клуба.')]);
            }

            if (Language::query()->where('is_enabled', true)->whereKeyNot($language->getKey())->doesntExist()) {
                throw ValidationException::withMessages(['language' => __('Должен остаться хотя бы один включённый язык.')]);
            }
        }

        DB::transaction(function () use ($language, $data, $request): void {
            $language->update($data);
            $changes = collect($language->getChanges())->except('updated_at')->map(fn (mixed $value): string => (string) $value)->all();

            if ($changes !== []) {
                $this->platformLog->record('language.updated', __('Изменён язык: :name (:code)', ['name' => $language->name, 'code' => $language->code]), $request->user(), null, $language, $changes);
            }
        });
        $this->catalog->flush();

        return back()->with('status', __('Язык обновлён.'));
    }

    public function destroy(Request $request, Language $language): RedirectResponse
    {
        if ($language->is_system) {
            throw ValidationException::withMessages(['language' => __('Встроенные языки удалить нельзя, их можно только выключить.')]);
        }

        DB::transaction(function () use ($language, $request): void {
            $clubs = Club::query()->where('locale', $language->code)->update(['locale' => null]);
            $language->delete();
            $this->platformLog->record('language.deleted', __('Удалён язык: :name (:code)', ['name' => $language->name, 'code' => $language->code]), $request->user(), null, $language, [
                'clubs_switched_to_default' => $clubs,
            ]);
        });
        $this->catalog->flush();

        return redirect()->route('superadmin.languages')->with('status', __('Язык удалён.'));
    }

    public function translations(Request $request, Language $language): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'missing' => ['nullable', 'boolean'],
        ]);
        $values = Translation::query()->where('language_id', $language->id)->pluck('value', 'key_hash');
        $search = mb_strtolower((string) ($filters['search'] ?? ''));

        $rows = collect($this->catalog->sourceKeys())
            ->flatMap(fn (array $lines, string $group) => collect($lines)->map(fn (string $source, string $key): array => [
                'hash' => Translation::hashKey($group, $key),
                'label' => $group === '*' ? $key : "{$group}.{$key}",
                'source' => $source,
            ])->values())
            ->map(fn (array $row): array => $row + ['value' => $values[$row['hash']] ?? ''])
            ->when($search !== '', fn ($rows) => $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['source'].' '.$row['value'].' '.$row['label']), $search)))
            ->when($filters['missing'] ?? false, fn ($rows) => $rows->filter(fn (array $row): bool => $row['value'] === ''))
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('superadmin.translations', [
            'language' => $language,
            'rows' => $paginator,
            'filters' => $filters,
            'translated' => $values->count(),
            'total' => $this->catalog->sourceKeyCount(),
        ]);
    }

    public function saveTranslations(Request $request, Language $language, TranslationTransfer $transfer): RedirectResponse
    {
        $data = $request->validate([
            'translations' => ['required', 'array', 'max:200'],
            'translations.*' => ['nullable', 'string', 'max:5000'],
        ]);

        $changed = DB::transaction(function () use ($data, $language, $request, $transfer): int {
            $changed = $transfer->apply($language, $data['translations'], deleteEmpty: true);

            if ($changed > 0) {
                $this->platformLog->record('translations.updated', __('Изменены переводы: :name (:count)', ['name' => $language->name, 'count' => $changed]), $request->user(), null, $language, ['changed' => $changed]);
            }

            return $changed;
        });

        if ($changed > 0) {
            $this->catalog->flush();
        }

        return back()->with('status', trans_choice('Сохранён :count перевод.|Сохранено :count перевода.|Сохранено :count переводов.', $changed));
    }

    /** CSV for translators: all texts, or only the untranslated ones. */
    public function export(Request $request, TranslationTransfer $transfer): StreamedResponse
    {
        $data = $request->validate(['language' => ['required', 'string', 'exists:languages,code'], 'missing' => ['nullable', 'boolean']]);
        $language = Language::query()->where('code', $data['language'])->firstOrFail();
        $missingOnly = (bool) ($data['missing'] ?? false);
        $rows = $transfer->exportRows($language, $missingOnly);
        $name = sprintf('zafiro-translations-%s%s-%s.csv', $language->code, $missingOnly ? '-missing' : '', now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel opens the file as UTF-8
            foreach ($rows as $row) {
                fputcsv($out, $row, TranslationTransfer::DELIMITER, '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function import(Request $request, TranslationTransfer $transfer): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', 'string', 'exists:languages,code'],
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ]);
        $language = Language::query()->where('code', $data['language'])->firstOrFail();

        $stats = DB::transaction(function () use ($request, $language, $transfer): array {
            $stats = $transfer->import($language, (string) file_get_contents($request->file('file')->getRealPath()));
            $this->platformLog->record('translations.imported', __('Импорт переводов: :name (:count)', ['name' => $language->name, 'count' => $stats['changed']]), $request->user(), null, $language, [
                'changed' => $stats['changed'],
                'unchanged' => $stats['unchanged'],
                'empty' => $stats['empty'],
                'unknown' => $stats['unknown'],
                'invalid_placeholders' => count($stats['invalid']),
            ]);

            return $stats;
        });

        if ($stats['changed'] > 0) {
            $this->catalog->flush();
        }

        return back()->with('status', __('Импорт :name: обновлено :changed, без изменений :unchanged, пустых :empty, неизвестных строк :unknown.', [
            'name' => $language->name,
            'changed' => $stats['changed'],
            'unchanged' => $stats['unchanged'],
            'empty' => $stats['empty'],
            'unknown' => $stats['unknown'],
        ]))->with('import_invalid', array_slice($stats['invalid'], 0, 20))->with('import_invalid_count', count($stats['invalid']));
    }
}
