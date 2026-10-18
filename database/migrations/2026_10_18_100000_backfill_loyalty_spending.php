<?php

use App\Services\AgentTariffPurchaseService;
use App\Services\LoyaltyProgram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cumulative discount: total_spent of each customer from the activity log (what was paid from
 * the main balance for tariffs, time and agent orders, minus cancelled orders), and the finance
 * settings of the first draft (loyalty_rules {threshold, discount}, tax_rate) in the final form.
 */
return new class extends Migration
{
    public function up(): void
    {
        $spent = [];
        DB::table('activity_logs')
            ->whereIn('event', ['tariff.purchased', 'time.purchased', 'shop.purchased', 'shop.order_cancelled'])
            ->select(['id', 'club_id', 'user_id', 'subject_id', 'event', 'details'])
            ->lazyById(1000)
            ->each(function (object $log) use (&$spent): void {
                $details = json_decode((string) $log->details, true) ?: [];
                // A cancelled order is logged by the staff; the customer is the subject.
                [$userId, $amount, $sign] = match ($log->event) {
                    'shop.order_cancelled' => [$log->subject_id, $details['amount'] ?? 0, -1],
                    'shop.purchased' => [$log->user_id, $details['amount'] ?? 0, 1],
                    default => [$log->user_id, $details['main_balance_amount'] ?? $details['amount'] ?? 0, 1],
                };
                if ($userId !== null && is_numeric($amount)) {
                    $key = $log->club_id.':'.$userId;
                    $spent[$key] = ($spent[$key] ?? 0) + $sign * AgentTariffPurchaseService::amountToCents((string) $amount);
                }
            });

        DB::transaction(function () use ($spent): void {
            DB::table('club_memberships')->update(['total_spent' => 0]);
            foreach ($spent as $key => $cents) {
                [$clubId, $userId] = explode(':', $key);
                DB::table('club_memberships')->where('club_id', $clubId)->where('user_id', $userId)
                    ->update(['total_spent' => AgentTariffPurchaseService::formatCents(max(0, $cents))]);
            }

            DB::table('clubs')->whereNotNull('settings')->orderBy('id')->each(function (object $club): void {
                $settings = json_decode((string) $club->settings, true);
                $finances = $settings['finances'] ?? null;
                if (! is_array($finances) || ! array_intersect_key($finances, array_flip(['loyalty_rules', 'loyalty_type', 'tax_rate']))) {
                    return;
                }
                $rules = is_array($finances['loyalty_rules'] ?? null) ? $finances['loyalty_rules'] : [];
                $finances['loyalty_levels'] ??= LoyaltyProgram::normalize(array_map(
                    fn (mixed $rule): array => ['spent' => $rule['threshold'] ?? null, 'percent' => $rule['discount'] ?? null],
                    array_filter($rules, 'is_array'),
                ));
                $finances['loyalty_enabled'] = (bool) ($finances['loyalty_enabled'] ?? false);
                unset($finances['loyalty_rules'], $finances['loyalty_type'], $finances['tax_rate']);
                $settings['finances'] = $finances;
                DB::table('clubs')->where('id', $club->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
            });
        });
    }

    public function down(): void {}
};
