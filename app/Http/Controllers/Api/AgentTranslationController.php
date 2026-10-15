<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LocaleResolver;
use App\Services\TranslationCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Texts of the PC agent in one language, with the superadmin's edits. The agent ships the same
 * texts (Frontend/i18n) and uses this only to pick up edits; they are not secret, so no token.
 */
class AgentTranslationController extends Controller
{
    public function __invoke(string $locale, TranslationCatalog $catalog, LocaleResolver $locales): JsonResponse
    {
        abort_unless(in_array($locale, $locales->enabledCodes(), true), 404);

        return response()->json([
            'locale' => $locale,
            'version' => $catalog->version(),
            'lines' => (object) ($catalog->lines($locale, 'agent') ?? $catalog->shippedGroup($locale, 'agent')),
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
