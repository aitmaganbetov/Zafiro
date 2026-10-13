<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Language;
use App\Models\Translation;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\LocaleResolver;
use App\Services\TranslationCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Interface languages are shared by all clubs; each club picks the language it uses.
 */
class LanguageController extends Controller
{
    public function __construct(
        private CurrentClub $currentClub,
        private TranslationCatalog $catalog,
        private LocaleResolver $locales,
        private ActivityLogger $activity,
    ) {}

    public function select(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', Rule::in($this->locales->enabledCodes())]]);
        $club = $this->currentClub->get();
        $from = $this->locales->forClub($club);

        DB::transaction(function () use ($club, $data, $from, $request): void {
            $club->forceFill(['locale' => $data['locale']])->save();
            $this->locales->apply($data['locale']);
            $this->activity->record('club.language_changed', __('Язык клуба изменён: :from → :to', ['from' => $from, 'to' => $data['locale']]), $request->user()->getKey(), $club, [
                'from' => $from,
                'to' => $data['locale'],
            ]);
        });

        return back()->with('status', __('Язык интерфейса изменён.'));
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
            $this->activity->record('language.created', __('Добавлен язык: :name (:code)', ['name' => $language->name, 'code' => $language->code]), $request->user()->getKey(), $language);
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
            if ($language->code === $this->locales->forClub($this->currentClub->get())) {
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
                $this->activity->record('language.updated', __('Изменён язык: :name (:code)', ['name' => $language->name, 'code' => $language->code]), $request->user()->getKey(), $language, $changes);
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
            $this->activity->record('language.deleted', __('Удалён язык: :name (:code)', ['name' => $language->name, 'code' => $language->code]), $request->user()->getKey(), $language, [
                'clubs_switched_to_default' => $clubs,
            ]);
        });
        $this->catalog->flush();

        return redirect()->route('settings.club')->with('status', __('Язык удалён.'));
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

        return view('settings.translations', [
            'language' => $language,
            'rows' => $paginator,
            'filters' => $filters,
            'translated' => $values->count(),
            'total' => $this->catalog->sourceKeyCount(),
        ]);
    }

    public function saveTranslations(Request $request, Language $language): RedirectResponse
    {
        $data = $request->validate([
            'translations' => ['required', 'array', 'max:200'],
            'translations.*' => ['nullable', 'string', 'max:5000'],
        ]);
        $source = collect($this->catalog->sourceKeys())
            ->flatMap(fn (array $lines, string $group) => collect($lines)->keys()->mapWithKeys(fn (string $key): array => [Translation::hashKey($group, $key) => [$group, $key]]));

        $changed = DB::transaction(function () use ($data, $source, $language, $request): int {
            $submitted = collect($data['translations'])->only($source->keys()->all());
            $existing = Translation::query()
                ->where('language_id', $language->id)
                ->whereIn('key_hash', $submitted->keys()->all())
                ->get(['id', 'key_hash', 'value'])
                ->keyBy('key_hash');
            $deletes = [];
            $upserts = [];
            $now = now();

            foreach ($submitted as $hash => $value) {
                [$group, $key] = $source[$hash];
                $value = trim((string) $value);
                $row = $existing->get($hash);

                if ($value === '') {
                    if ($row !== null) {
                        $deletes[] = $row->id;
                    }
                } elseif ($row?->value !== $value) {
                    $upserts[] = ['language_id' => $language->id, 'group' => $group, 'key' => $key, 'key_hash' => $hash, 'value' => $value, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            if ($deletes !== []) {
                Translation::query()->whereKey($deletes)->delete();
            }

            if ($upserts !== []) {
                Translation::query()->upsert($upserts, ['language_id', 'group', 'key_hash'], ['value', 'updated_at']);
            }

            $changed = count($deletes) + count($upserts);

            if ($changed > 0) {
                $this->activity->record('translations.updated', __('Изменены переводы: :name (:count)', ['name' => $language->name, 'count' => $changed]), $request->user()->getKey(), $language, ['changed' => $changed]);
            }

            return $changed;
        });

        if ($changed > 0) {
            $this->catalog->flush();
        }

        return back()->with('status', trans_choice('Сохранён :count перевод.|Сохранено :count перевода.|Сохранено :count переводов.', $changed));
    }
}
