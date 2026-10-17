<?php

namespace App\Support;

/**
 * MAC of a PC's network adapter — the key of a club computer. PCs of a diskless club boot one
 * image, so the hostname, Windows ids and often the board UUID are the same on all of them;
 * the MAC is not. Stored as AA:BB:CC:DD:EE:FF.
 */
final class MacAddress
{
    /** AA:BB:CC:DD:EE:FF, or null when the value is not a usable MAC (empty, all zeros, broadcast). */
    public static function normalize(mixed $mac): ?string
    {
        if (! is_string($mac)) {
            return null;
        }

        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $mac));

        if (strlen($hex) !== 12 || count(array_unique(str_split($hex))) < 2) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /** Device id an agent reports for this MAC (MAC-AABBCCDDEEFF). */
    public static function deviceId(string $mac): string
    {
        return 'MAC-'.str_replace(':', '', $mac);
    }
}
