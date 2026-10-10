<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSettingsRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function club()
    {
        return view('settings.club', ['settings' => $this->loadSettings()]);
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
                'name' => 'Товары для мобильного приложения',
                'url' => route('api.products.index'),
                'response' => [
                    'data' => [[
                        'id' => 1,
                        'sku' => 'DRK-001',
                        'name' => 'Coca-Cola 0,5 л',
                        'category' => 'Напитки',
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

    public function save(SaveSettingsRequest $request)
    {
        $section = $request->input('section', 'club');
        $settings = $this->loadSettings();
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

        Storage::put('settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return back()->with('status', 'Настройки сохранены');
    }

    private function loadSettings(): array
    {
        if (!Storage::exists('settings.json')) {
            return $this->defaultSettings();
        }

        $contents = Storage::get('settings.json');

        $settings = array_replace_recursive(
            $this->defaultSettings(),
            json_decode($contents, true) ?: [],
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
                'session_idle_timeout' => 'Время завершения сессии при бездействии (в минутах)',
                'pc_restart_after_session_end' => 'Время перезагрузки ПК после завершения сессии (в минутах)',
                'session_auto_terminate_when_pc_unavailable' => 'Время до автоматического завершения сессии при недоступности ПК (в минутах)',
                'booking_interval' => 'Интервал между бронированиями (в минутах)',
            ],
            'finances' => [
                'currency' => 'Валюта',
            ],
            'guests' => [
                'show_qr_code_on_login' => 'Отображать QR-код на странице авторизации',
                'allow_guest_self_transfer' => 'Разрешить самостоятельную пересадку гостя',
                'allow_adding_friends' => 'Возможность добавления в друзья',
                'allow_balance_transfer_to_friends' => 'Разрешить перевод баланса друзьям',
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
