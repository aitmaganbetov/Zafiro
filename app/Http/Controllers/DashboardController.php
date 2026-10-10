<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $now = now();
        $today = $now->copy()->startOfDay();
        $weekStart = $today->copy()->subDays(6);
        $transactions = CashTransaction::query()
            ->whereBetween('created_at', [$weekStart, $now])
            ->get(['type', 'amount', 'created_at']);
        $todayTransactions = $transactions->filter(fn (CashTransaction $transaction): bool => $transaction->created_at->isSameDay($today));
        $chartLabels = [];
        $chartIncome = [];
        $chartExpenses = [];

        foreach (range(6, 0) as $daysAgo) {
            $day = $today->copy()->subDays($daysAgo);
            $dayTransactions = $transactions->filter(fn (CashTransaction $transaction): bool => $transaction->created_at->isSameDay($day));
            $chartLabels[] = $day->format('d.m');
            $chartIncome[] = (float) $dayTransactions->where('type', CashTransaction::TYPE_INCOME)->sum('amount');
            $chartExpenses[] = (float) $dayTransactions->where('type', CashTransaction::TYPE_EXPENSE)->sum('amount');
        }

        $computerStatuses = ClubComputer::query()->get(['status', 'is_reserved', 'last_seen_at'])
            ->map(fn (ClubComputer $computer): string => $computer->currentStatus());
        $settings = Storage::exists('settings.json') ? json_decode(Storage::get('settings.json'), true) : [];

        return view('dashboard', [
            'todayIncome' => (float) $todayTransactions->where('type', CashTransaction::TYPE_INCOME)->sum('amount'),
            'todayExpenses' => (float) $todayTransactions->where('type', CashTransaction::TYPE_EXPENSE)->sum('amount'),
            'todayTransactionCount' => $todayTransactions->count(),
            'todayExpenseCount' => $todayTransactions->where('type', CashTransaction::TYPE_EXPENSE)->count(),
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
            'userCount' => User::query()->count(),
            'recentTransactions' => CashTransaction::query()
                ->latest('created_at')
                ->limit(6)
                ->get(['id', 'type', 'amount', 'description', 'created_at']),
        ]);
    }
}
