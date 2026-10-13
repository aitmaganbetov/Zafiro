<?php

namespace App\Services;

use App\Models\Club;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Picks the interface language: the club's language for signed-in requests, the
 * browser's language for guests, English when nothing matches.
 */
class LocaleResolver
{
    public const DEFAULT_GUEST_LOCALE = 'en';

    public const DEFAULT_CLUB_LOCALE = 'ru';

    /** @var array<int, list<string>> enabled codes per translations cache version */
    private array $enabled = [];

    /** @return list<string> */
    public function enabledCodes(): array
    {
        $version = (int) Cache::get('translations.version', 1);

        return $this->enabled[$version] ??= Cache::rememberForever("languages.enabled.{$version}", function (): array {
            try {
                return DB::table('languages')->where('is_enabled', true)->orderBy('sort_order')->orderBy('id')->pluck('code')->all();
            } catch (QueryException) {
                return [self::DEFAULT_CLUB_LOCALE, self::DEFAULT_GUEST_LOCALE];
            }
        });
    }

    public function forClub(Club $club): string
    {
        $code = $club->locale ?? self::DEFAULT_CLUB_LOCALE;

        return in_array($code, $this->enabledCodes(), true) ? $code : $this->fallback();
    }

    public function forGuest(Request $request): string
    {
        $enabled = $this->enabledCodes();

        foreach ($request->getLanguages() as $preferred) {
            $preferred = strtolower(str_replace('_', '-', $preferred));

            foreach ([$preferred, explode('-', $preferred)[0]] as $candidate) {
                if (in_array($candidate, $enabled, true)) {
                    return $candidate;
                }
            }
        }

        return in_array(self::DEFAULT_GUEST_LOCALE, $enabled, true) ? self::DEFAULT_GUEST_LOCALE : $this->fallback();
    }

    public function apply(string $locale): void
    {
        app()->setLocale($locale);
        Carbon::setLocale($locale);
    }

    private function fallback(): string
    {
        $enabled = $this->enabledCodes();

        return in_array(self::DEFAULT_CLUB_LOCALE, $enabled, true) ? self::DEFAULT_CLUB_LOCALE : ($enabled[0] ?? self::DEFAULT_GUEST_LOCALE);
    }
}
