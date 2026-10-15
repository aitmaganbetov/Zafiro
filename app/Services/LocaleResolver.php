<?php

namespace App\Services;

use App\Models\Club;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Picks the interface language: the club's language for signed-in requests, the
 * browser's language for guests, English when nothing matches.
 */
class LocaleResolver
{
    public const DEFAULT_GUEST_LOCALE = 'en';

    public const DEFAULT_CLUB_LOCALE = 'ru';

    public function __construct(private TranslationCatalog $catalog) {}

    /** @return list<string> */
    public function enabledCodes(): array
    {
        return $this->catalog->enabledCodes();
    }

    public function forClub(Club $club): string
    {
        $code = $club->locale ?? self::DEFAULT_CLUB_LOCALE;

        return in_array($code, $this->enabledCodes(), true) ? $code : $this->fallback();
    }

    /**
     * The PC agent sends the language the customer picked on the PC (X-Agent-Locale);
     * otherwise the club language applies.
     */
    public function forAgentRequest(Request $request, Club $club): string
    {
        $requested = strtolower((string) $request->header('X-Agent-Locale'));

        return $requested !== '' && in_array($requested, $this->enabledCodes(), true) ? $requested : $this->forClub($club);
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
