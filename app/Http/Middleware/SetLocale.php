<?php

namespace App\Http\Middleware;

use App\Services\CurrentClub;
use App\Services\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guests get their browser language. Signed-in requests use the club's language;
 * SetCurrentClub applies it too, so the result does not depend on middleware order.
 */
class SetLocale
{
    public function __construct(private LocaleResolver $locales, private CurrentClub $currentClub) {}

    public function handle(Request $request, Closure $next): Response
    {
        $club = $this->currentClub->get();
        $this->locales->apply($club !== null ? $this->locales->forClub($club) : $this->locales->forGuest($request));

        return $next($request);
    }
}
