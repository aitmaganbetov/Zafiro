<?php

namespace App\Support;

/**
 * Colour themes of the PC agent (the guest shell and the sign-in screen), club settings → "PC agent".
 * The heartbeat sends the colours themselves, so a new theme here needs no agent release.
 * "standard" sends nothing: the agent keeps its built-in look.
 */
final class ShellThemes
{
    public const DEFAULT = 'standard';

    /**
     * accent — buttons and the active menu item (white text on it); accent_2 — highlights and the
     * tariff button (dark text on it); soft — small headings and links; highlight — the shop (dark
     * text on it); bg_0 and bg_1 — the dark background from top to bottom.
     *
     * @var array<string, array<string, string>|null>
     */
    private const COLORS = [
        'standard' => null,
        'blue' => ['accent' => '#2563eb', 'accent_2' => '#38bdf8', 'soft' => '#93c5fd', 'highlight' => '#7dd3fc', 'bg_0' => '#03060c', 'bg_1' => '#071226'],
        'green' => ['accent' => '#16a34a', 'accent_2' => '#4ade80', 'soft' => '#86efac', 'highlight' => '#bef264', 'bg_0' => '#030805', 'bg_1' => '#061a0e'],
        'red' => ['accent' => '#dc2626', 'accent_2' => '#fb7185', 'soft' => '#fca5a5', 'highlight' => '#fda4af', 'bg_0' => '#0a0304', 'bg_1' => '#1f0709'],
        'orange' => ['accent' => '#ea580c', 'accent_2' => '#fbbf24', 'soft' => '#fdba74', 'highlight' => '#fcd34d', 'bg_0' => '#0a0603', 'bg_1' => '#1f1005'],
        'teal' => ['accent' => '#0d9488', 'accent_2' => '#2dd4bf', 'soft' => '#5eead4', 'highlight' => '#99f6e4', 'bg_0' => '#020909', 'bg_1' => '#051c1c'],
        'pink' => ['accent' => '#db2777', 'accent_2' => '#f472b6', 'soft' => '#f9a8d4', 'highlight' => '#fbcfe8', 'bg_0' => '#0a0308', 'bg_1' => '#1f0716'],
    ];

    /** @return array<string, string> key => name for the settings page */
    public static function names(): array
    {
        return [
            'standard' => __('Стандартная (фиолетовая)'),
            'blue' => __('Синяя'),
            'green' => __('Зелёная'),
            'red' => __('Красная'),
            'orange' => __('Оранжевая'),
            'teal' => __('Бирюзовая'),
            'pink' => __('Розовая'),
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::COLORS);
    }

    /** Colours for the swatch on the settings page: the standard theme shows the agent's own. */
    public static function swatch(string $key): array
    {
        return self::COLORS[$key] ?? ['accent' => '#8b5cf6', 'accent_2' => '#5eb7ff', 'bg_1' => '#071018'];
    }

    /** @return array<string, string>|null what the heartbeat sends; null — the agent's own look */
    public static function colors(mixed $key): ?array
    {
        return is_string($key) ? self::COLORS[$key] ?? null : null;
    }
}
