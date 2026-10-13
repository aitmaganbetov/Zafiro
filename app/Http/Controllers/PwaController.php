<?php

namespace App\Http\Controllers;

use App\Services\LocaleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Installable web app: the manifest and the offline page. Both are public and
 * contain no club data; their language follows the browser (guest locale).
 */
class PwaController extends Controller
{
    public const THEME_COLOR = '#101713';

    public function manifest(): JsonResponse
    {
        $icon = fn (string $file, int $size, string $purpose = 'any'): array => [
            'src' => asset("icons/{$file}"),
            'sizes' => "{$size}x{$size}",
            'type' => 'image/png',
            'purpose' => $purpose,
        ];
        $shortcut = fn (string $name, string $route): array => [
            'name' => $name,
            'url' => route($route, absolute: false),
            'icons' => [$icon('icon-192.png', 192)],
        ];

        return response()->json([
            'id' => '/',
            'name' => config('app.name') === 'Laravel' ? 'Zafiro' : config('app.name'),
            'short_name' => 'Zafiro',
            'description' => __('Система управления клубом'),
            'lang' => app()->getLocale(),
            'start_url' => route('dashboard', absolute: false),
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => self::THEME_COLOR,
            'theme_color' => self::THEME_COLOR,
            'icons' => [
                $icon('icon-192.png', 192),
                $icon('icon-512.png', 512),
                $icon('icon-maskable-512.png', 512, 'maskable'),
            ],
            'shortcuts' => [
                $shortcut(__('Касса'), 'cash-register.index'),
                $shortcut(__('Склад'), 'warehouse'),
                $shortcut(__('Список ПК'), 'club-computers.index'),
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=86400',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Shown by the service worker when a page cannot be loaded without a connection. */
    public function offline(LocaleResolver $locales): Response
    {
        // Every enabled language is embedded: the page is cached once and shown later in
        // the language of the club the user last worked in.
        $texts = collect($locales->enabledCodes())->mapWithKeys(fn (string $code): array => [$code => [
            'title' => __('Нет подключения', [], $code),
            'heading' => __('Нет подключения к интернету', [], $code),
            'text' => __('Zafiro работает с актуальными данными клуба, поэтому без сети страница недоступна. Проверьте подключение и повторите.', [], $code),
            'retry' => __('Повторить', [], $code),
        ]]);

        return response(view('offline', ['texts' => $texts]))->header('Cache-Control', 'no-cache');
    }
}
