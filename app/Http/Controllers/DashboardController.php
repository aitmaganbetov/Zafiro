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
        $now = now();
        $today = $now->copy()->startOfDay();
        $weekStart = $today->copy()->subDays(6);
        $dailyTotals = CashTransaction::query()
            ->whereBetween('created_at', [$weekStart, $now])
            ->selectRaw('DATE(created_at) as day, type, SUM(amount) as total, COUNT(*) as transaction_count')
            ->groupByRaw('DATE(created_at), type')
            ->toBase()
            ->get();
        $totalFor = fn (string $day, string $type, string $column = 'total'): float => (float) $dailyTotals
            ->where('day', $day)
            ->where('type', $type)
            ->sum($column);
        $todayKey = $today->toDateString();
        $chartLabels = [];
        $chartIncome = [];
        $chartExpenses = [];

        foreach (range(6, 0) as $daysAgo) {
            $day = $today->copy()->subDays($daysAgo);
            $chartLabels[] = $day->format('d.m');
            $chartIncome[] = $totalFor($day->toDateString(), CashTransaction::TYPE_INCOME);
            $chartExpenses[] = $totalFor($day->toDateString(), CashTransaction::TYPE_EXPENSE);
        }

        $computerStatuses = ClubComputer::query()->get(['status', 'is_reserved', 'last_seen_at'])
            ->map(fn (ClubComputer $computer): string => $computer->currentStatus());
        $settings = $this->currentClub->settings();

        return view('dashboard', [
            'todayIncome' => $totalFor($todayKey, CashTransaction::TYPE_INCOME),
            'todayExpenses' => $totalFor($todayKey, CashTransaction::TYPE_EXPENSE),
            'todayTransactionCount' => (int) $dailyTotals->where('day', $todayKey)->sum('transaction_count'),
            'todayExpenseCount' => (int) $totalFor($todayKey, CashTransaction::TYPE_EXPENSE, 'transaction_count'),
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
