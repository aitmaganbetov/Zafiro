<?php

namespace App\Support;

/** A Kazakhstan phone number as accounts store it: +7 and ten digits. */
final class PhoneNumber
{
    /** "+7 700 123-45-67", "8 700…", "700…" → "+77001234567"; anything else is returned as digits after +7 (fails validation). */
    public static function normalize(mixed $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, 1);
        }

        return '+7'.$digits;
    }
}
