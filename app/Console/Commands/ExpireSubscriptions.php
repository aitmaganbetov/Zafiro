<?php

namespace App\Console\Commands;

use App\Models\ClubSubscription;
use App\Services\PlatformLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('subscriptions:expire')]
#[Description('Mark paid club subscriptions whose paid period is over as expired')]
class ExpireSubscriptions extends Command
{
    /**
     * Access already ends at the paid date (ClubSubscription::isActive); this marks the status,
     * so the cabinet, billing page and notifications say "expired", and logs it.
     */
    public function handle(PlatformLogger $platformLog): int
    {
        $expired = 0;
        ClubSubscription::query()->with('club:id,name')
            ->where('status', 'active')
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', now())
            ->each(function (ClubSubscription $subscription) use ($platformLog, &$expired): void {
                DB::transaction(function () use ($subscription, $platformLog): void {
                    $subscription->forceFill(['status' => 'expired'])->save();
                    $platformLog->record('subscription.expired', __('Оплаченная подписка клуба :club закончилась :date', [
                        'club' => $subscription->club?->name ?? '#'.$subscription->club_id,
                        'date' => $subscription->current_period_ends_at->format('d.m.Y'),
                    ]), null, $subscription->club, $subscription);
                });
                $expired++;
            });

        $this->line('expired: '.$expired);

        return self::SUCCESS;
    }
}
