<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Base of the staff app API (docs/staff-api.md): money as "1500.00", times as UTC ISO 8601. */
abstract class StaffApiController extends Controller
{
    protected static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    protected static function at(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->utc()->toIso8601ZuluString();
    }

    /** @return array{current_page: int, last_page: int} */
    protected static function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()];
    }
}
