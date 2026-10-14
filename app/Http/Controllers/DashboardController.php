<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\ClubComputer;
use App\Models\ClubMembership;
use App\Models\ClubZone;
use App\Models\Product;
use App\Services\CurrentClub;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function __invoke(): View
    {
        // Days are the club's local days; stored times are UTC, so each local day becomes
        // a UTC range and the sums are made in one SQL query.
        $today = now($this->currentClub->timezone())->startOfDay();
        $days = collect(range(6, 0))->map(fn (int $daysAgo) => $today->copy()->subDays($daysAgo));
        $utc = fn ($moment): string => $moment->copy()->utc()->format('Y-m-d H:i:s');
        $columns = [];
        $bindings = [];

        foreach ($days as $i => $day) {
            $range = [$utc($day), $utc($day->copy()->addDay())];
            $columns[] = "SUM(CASE WHEN created_at >= ? AND created_at < ? THEN amount ELSE 0 END) as total_{$i}";
            $columns[] = "SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as count_{$i}";
            array_push($bindings, ...$range, ...$range);
        }

        $totals = CashTransaction::query()
            ->where('created_at', '>=', $utc($days->first()))
            ->where('created_at', '<', $utc($today->copy()->addDay()))
            ->selectRaw('type, '.implode(', ', $columns), $bindings)
            ->groupBy('type')
            ->toBase()
            ->get()
            ->keyBy('type');
        $totalFor = fn (int $day, string $type, string $column = 'total'): float => (float) ($totals[$type]->{"{$column}_{$day}"} ?? 0);
        $todayKey = 6;
        $chartLabels = $days->map(fn ($day): string => $day->format('d.m'))->all();
        $chartIncome = $days->keys()->map(fn (int $i): float => $totalFor($i, CashTransaction::TYPE_INCOME))->all();
        $chartExpenses = $days->keys()->map(fn (int $i): float => $totalFor($i, CashTransaction::TYPE_EXPENSE))->all();

        $computerStatuses = ClubComputer::query()->get(['status', 'is_reserved', 'last_seen_at'])
            ->map(fn (ClubComputer $computer): string => $computer->currentStatus());
        $settings = $this->currentClub->settings();

        return view('dashboard', [
            'todayIncome' => $totalFor($todayKey, CashTransaction::TYPE_INCOME),
            'todayExpenses' => $totalFor($todayKey, CashTransaction::TYPE_EXPENSE),
            'todayTransactionCount' => (int) ($totalFor($todayKey, CashTransaction::TYPE_INCOME, 'count') + $totalFor($todayKey, CashTransaction::TYPE_EXPENSE, 'count')),
            'todayExpenseCount' => (int) $totalFor($todayKey, CashTransaction::TYPE_EXPENSE, 'count'),
            'currency' => data_get($settings ?: [], 'finances.currency', 'KZT'),
            'chartLabels' => $chartLabels,
            'chartIncome' => $chartIncome,
            'chartExpenses' => $chartExpenses,
            'productCount' => Product::query()->count(),
            'lowStockCount' => Product::query()->where('quantity', '<', 5)->count(),
            'zoneCount' => ClubZone::query()->count(),
            'computerCount' => $computerStatuses->count(),
            'onlineComputerCount' => $computerStatuses->filter(fn (string $status): bool => $status === 'online')->count(),
            'reservedComputerCount' => $computerStatuses->filter(fn (string $status): bool => $status === 'reserved')->count(),
            'userCount' => ClubMembership::query()->count(),
            'recentTransactions' => CashTransaction::query()
                ->latest('created_at')
                ->limit(6)
                ->get(['id', 'type', 'amount', 'description', 'created_at']),
        ]);
    }
}
