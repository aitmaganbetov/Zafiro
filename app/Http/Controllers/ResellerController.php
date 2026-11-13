<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Reseller;
use App\Models\ResellerPayout;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The reseller cabinet: the clubs that entered the reseller's code and what they brought (a percent
 * of each subscription payment). The superadmin creates resellers and sets their percent.
 */
class ResellerController extends Controller
{
    public function dashboard(Request $request): View
    {
        // Signed in on the main site, or no longer a reseller: the cabinet is not theirs.
        $reseller = Reseller::query()->where('user_id', $request->user()->getKey())->first();
        abort_if($reseller === null, 403, __('Этот аккаунт не реселлер Zafiro.'));

        $paid = fn () => SubscriptionInvoice::query()->withoutGlobalScope('club')->where('reseller_id', $reseller->getKey())->where('status', 'paid');
        $earned = (float) $paid()->sum('reseller_amount');
        $paidOut = (float) ResellerPayout::query()->where('reseller_id', $reseller->getKey())->sum('amount');

        return view('reseller.dashboard', [
            'reseller' => $reseller,
            'clubs' => Club::query()->where('reseller_id', $reseller->getKey())
                ->with('subscription.plan:id,name')
                ->orderByDesc('reseller_attached_at')
                ->paginate(30, ['id', 'name', 'city', 'reseller_attached_at'], 'clubs_page'),
            'earnings' => $paid()->with('club:id,name')->latest('paid_at')->latest('id')
                ->paginate(20, ['id', 'club_id', 'months', 'amount', 'reseller_percent', 'reseller_amount', 'paid_at'], 'earnings_page'),
            'payouts' => ResellerPayout::query()->where('reseller_id', $reseller->getKey())->latest('id')->limit(20)->get(['amount', 'note', 'created_at']),
            'earned' => $earned,
            'paidOut' => $paidOut,
        ]);
    }
}
