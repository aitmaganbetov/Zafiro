<?php

namespace App\Http\Controllers\Api\Staff;

use App\Models\ClubMembership;
use App\Services\LoyaltyProgram;
use App\Support\ClubRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customers of the club for the staff app: search by name or phone, balances and discount. */
class CustomerController extends StaffApiController
{
    public function index(Request $request, LoyaltyProgram $loyalty): JsonResponse
    {
        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:50']])['search'] ?? ''));
        // Phones are stored as +7XXXXXXXXXX: a number typed from 8 is searched from 7.
        $digits = preg_replace('/^8/', '7', preg_replace('/\D+/', '', $search));
        $like = fn (string $value): string => '%'.addcslashes($value, '\\%_').'%';

        $page = ClubMembership::query()
            ->whereNotIn('role', ClubRoles::STAFF)
            ->when(mb_strlen($search) >= 2, fn ($query) => $query->whereHas('user', function ($users) use ($search, $digits, $like): void {
                $users->where('name', 'like', $like($search));
                if (strlen($digits) >= 3) {
                    $users->orWhere('phone', 'like', $like($digits));
                }
            }))
            ->with(['user:id,name,phone', 'balance'])
            ->orderBy('user_id')
            ->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(function (ClubMembership $membership) use ($loyalty): array {
                $cumulative = $loyalty->percentFor($membership);

                return [
                    'user_id' => $membership->user_id,
                    'name' => $membership->user?->name,
                    'phone' => $membership->user?->phone,
                    'balance' => self::money($membership->balance?->balance),
                    'bonus_balance' => self::money($membership->balance?->bonus_balance),
                    'discount' => self::money(max((float) $membership->discount, $cumulative)),
                    'discount_cumulative' => $cumulative > (float) $membership->discount,
                    'total_spent' => self::money($membership->total_spent),
                ];
            })->all(),
            'meta' => self::meta($page),
        ]);
    }
}
