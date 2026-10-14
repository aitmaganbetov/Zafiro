<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSettingsRequest;
use App\Models\Language;
use App\Models\Translation;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\LocaleResolver;
use App\Services\TranslationCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function club()
    {
        return view('settings.club', [
            'settings' => $this->loadSettings(),
            'clubTimezone' => $this->currentClub->get()->timezone,
            'agentPinSetAt' => $this->currentClub->get()->agent_exit_pin_hash === null ? null : $this->currentClub->get()->agent_exit_pin_changed_at,
            'canManageAgentPin' => in_array(app(CurrentClub::class)->membership()?->role, ['owner', 'admin'], true),
            'timezones' => $this->timezoneOptions(),
        ]);
    }

    public function timezone(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate(['timezone' => ['required', 'string', 'timezone:all']]);
        $club = $this->currentClub->get();
        $from = $club->timezone;

        if ($from !== $data['timezone']) {
            DB::transaction(function () use ($club, $data, $from, $request, $activity): void {
                $club->forceFill(['timezone' => $data['timezone']])->save();
                $activity->record('club.timezone_changed', __('Часовой пояс клуба изменён: :from → :to', ['from' => $from, 'to' => $data['timezone']]), $request->user()->getKey(), $club, [
                    'from' => $from,
                    'to' => $data['timezone'],
                ]);
            });
        }

        return back()->with('status', __('Часовой пояс сохранён.'));
    }

    /** Staff PIN for closing the PC agent; stored as a hash, empty value removes it. */
    public function agentPin(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'pin' => ['nullable', 'string', 'regex:/^\d{4,8}$/', 'confirmed'],
        ], [
            'pin.regex' => __('PIN-код — от 4 до 8 цифр.'),
        ]);
        $club = $this->currentClub->get();
        $pin = $data['pin'] ?? null;

        DB::transaction(function () use ($club, $pin, $request, $activity): void {
            $club->forceFill([
                'agent_exit_pin_hash' => $pin === null ? null : Hash::make($pin),
                'agent_exit_pin_changed_at' => now(),
            ])->save();
            $activity->record(
                'club.agent_pin_changed',
                $pin === null ? __('PIN-код выхода из агента удалён') : __('PIN-код выхода из агента изменён'),
                $request->user()->getKey(),
                $club,
            );
        });

        return back()->with('status', $pin === null ? __('PIN-код удалён.') : __('PIN-код сохранён.'));
    }

    /**
     * Kazakhstan first, then every zone ordered by its current UTC offset.
     *
     * @return array<string, array<string, string>> group label => [identifier => label]
     */
    private function timezoneOptions(): array
    {
        $label = function (string $zone): string {
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone($zone)))->format('P');

            return "(UTC{$offset}) ".str_replace('_', ' ', $zone);
        };
        $kazakhstan = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, 'KZ');
        $all = collect(\DateTimeZone::listIdentifiers())
            ->sortBy(fn (string $zone): string => sprintf('%06d %s', (new \DateTimeZone($zone))->getOffset(new \DateTimeImmutable) + 50000, $zone));

        return [
            __('Казахстан') => collect($kazakhstan)->mapWithKeys(fn (string $zone): array => [$zone => $label($zone)])->all(),
            __('Все часовые пояса') => $all->mapWithKeys(fn (string $zone): array => [$zone => $label($zone)])->all(),
        ];
    }

    public function languages(TranslationCatalog $catalog, LocaleResolver $locales)
    {
        // Only texts that still exist in the code count; rows of removed texts stay in the table.
        $sourceHashes = collect($catalog->sourceKeys())
            ->flatMap(fn (array $lines, string $group) => collect($lines)->keys()->map(fn ($key) => Translation::hashKey($group, (string) $key)))
            ->all();
        $translated = Translation::query()->whereIn('key_hash', $sourceHashes)->selectRaw('language_id, count(*) as aggregate')->groupBy('language_id')->pluck('aggregate', 'language_id');

        return view('settings.languages', [
            'languages' => Language::query()->orderBy('sort_order')->orderBy('id')->get()
                ->each(fn (Language $language) => $language->setAttribute('translated_count', (int) ($translated[$language->id] ?? 0))),
            'clubLocale' => $locales->forClub($this->currentClub->get()),
            'translationTotal' => $catalog->sourceKeyCount(),
        ]);
    }

    public function finances()
    {
        return view('settings.finances', ['settings' => $this->loadSettings()]);
    }

    public function guests()
    {
        return view('settings.guests', ['settings' => $this->loadSettings()]);
    }

    public function api()
    {
        $settings = $this->loadSettings();
        $endpoints = [];

        foreach ($settings as $section => $sectionSettings) {
            foreach ($sectionSettings as $key => $value) {
                $endpoints[] = [
                    'section' => $section,
                    'key' => $key,
                    'name' => $this->settingLabels()[$section][$key],
                    'value' => $value,
                    'url' => route('api.settings.show', [$section, $key]),
                ];
            }
        }

        return view('settings.api', [
            'settings' => $settings,
            'endpoints' => $endpoints,
            'ip' => request()->getHost(),
            'productEndpoint' => [
                'name' => __('Товары для мобильного приложения'),
                'url' => route('api.products.index'),
                'response' => [
                    'data' => [[
                        'id' => 1,
                        'sku' => 'DRK-001',
                        'name' => __('Coca-Cola 0,5 л'),
                        'category' => __('Напитки'),
                        'price' => 650,
                        'currency' => 'KZT',
                        'quantity' => 48,
                        'in_stock' => true,
                        'image_url' => request()->root().'/images/product-placeholder.svg',
                    ]],
                ],
            ],
        ]);
    }

    public function showSetting(Request $request, string $section, string $key)
    {
        $settings = $this->loadSettings();

        abort_unless(array_key_exists($section, $settings), 404);
        abort_unless(array_key_exists($key, $settings[$section]), 404);

        return response()->json([
            'ip' => $request->getHost(),
            'setting' => $section.'.'.$key,
            'name' => $this->settingLabels()[$section][$key],
            'value' => $settings[$section][$key],
        ]);
    }

    public function save(SaveSettingsRequest $request, ActivityLogger $activity)
    {
        $section = $request->input('section', 'club');
        $settings = $this->loadSettings();
        $before = $settings[$section] ?? [];
        $settings[$section] = $settings[$section] ?? [];

        $fields = [
            'club' => [
                'session_idle_timeout',
                'pc_restart_after_session_end',
                'session_auto_terminate_when_pc_unavailable',
                'booking_interval',
            ],
            'finances' => [
                'currency',
            ],
            'guests' => [
                'show_qr_code_on_login',
                'allow_guest_self_transfer',
                'allow_adding_friends',
                'allow_balance_transfer_to_friends',
            ],
        ];

        $booleanFields = [
            'show_qr_code_on_login',
            'allow_guest_self_transfer',
            'allow_adding_friends',
            'allow_balance_transfer_to_friends',
        ];

        $minuteFields = [
            'session_idle_timeout',
            'pc_restart_after_session_end',
            'session_auto_terminate_when_pc_unavailable',
            'booking_interval',
        ];

        foreach ($fields[$section] ?? [] as $field) {
            if ($request->has($field)) {
                $settings[$section][$field] = match (true) {
                    in_array($field, $booleanFields, true) => $request->boolean($field),
                    in_array($field, $minuteFields, true) => $this->number((float) $request->input($field)),
                    default => $request->input($field),
                };
            }
        }

        $readable = fn (mixed $value): string => is_bool($value) ? ($value ? __('да') : __('нет')) : (string) ($value ?? '—');
        $changes = collect($settings[$section])
            ->filter(fn (mixed $value, string $field): bool => ($before[$field] ?? null) !== $value)
            ->map(fn (mixed $value, string $field): string => $readable($before[$field] ?? null).' → '.$readable($value))
            ->all();

        DB::transaction(function () use ($settings, $section, $changes, $request, $activity): void {
            $club = $this->currentClub->get();
            $club->settings = $settings;
            $club->save();

            if ($changes !== []) {
                $activity->record('settings.updated', __('Изменены настройки: :fields', ['fields' => implode(', ', array_keys($changes))]), $request->user()->getKey(), $club, [
                    'section' => $section,
                    ...$changes,
                ]);
            }
        });

        return back()->with('status', __('Настройки сохранены'));
    }

    private function loadSettings(): array
    {
        $settings = array_replace_recursive(
            $this->defaultSettings(),
            $this->currentClub->settings(),
        );

        foreach (array_keys($this->defaultSettings()['club']) as $field) {
            $settings['club'][$field] = $this->minutes($settings['club'][$field]);
        }

        return $settings;
    }

    private function defaultSettings(): array
    {
        return [
            'club' => [
                'session_idle_timeout' => 15,
                'pc_restart_after_session_end' => 0.5,
                'session_auto_terminate_when_pc_unavailable' => 5,
                'booking_interval' => 30,
            ],
            'finances' => [
                'currency' => 'KZT',
            ],
            'guests' => [
                'show_qr_code_on_login' => false,
                'allow_guest_self_transfer' => false,
                'allow_adding_friends' => false,
                'allow_balance_transfer_to_friends' => false,
            ],
        ];
    }

    private function settingLabels(): array
    {
        return [
            'club' => [
                'session_idle_timeout' => __('Время завершения сессии при бездействии (в минутах)'),
                'pc_restart_after_session_end' => __('Время перезагрузки ПК после завершения сессии (в минутах)'),
                'session_auto_terminate_when_pc_unavailable' => __('Время до автоматического завершения сессии при недоступности ПК (в минутах)'),
                'booking_interval' => __('Интервал между бронированиями (в минутах)'),
            ],
            'finances' => [
                'currency' => __('Валюта'),
            ],
            'guests' => [
                'show_qr_code_on_login' => __('Отображать QR-код на странице авторизации'),
                'allow_guest_self_transfer' => __('Разрешить самостоятельную пересадку гостя'),
                'allow_adding_friends' => __('Возможность добавления в друзья'),
                'allow_balance_transfer_to_friends' => __('Разрешить перевод баланса друзьям'),
            ],
        ];
    }

    private function minutes(mixed $value): int|float
    {
        if (is_numeric($value)) {
            return $this->number((float) $value);
        }

        preg_match('/[\d]+(?:[.,][\d]+)?/u', (string) $value, $matches);
        $amount = isset($matches[0]) ? (float) str_replace(',', '.', $matches[0]) : 0.0;

        return $this->number(str_contains(mb_strtolower((string) $value), 'сек') ? $amount / 60 : $amount);
    }

    private function number(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }
}
