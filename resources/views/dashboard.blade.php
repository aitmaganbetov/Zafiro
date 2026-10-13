@extends('layouts.admin')

@section('page-title', 'Админпанель')

@section('content')
<div class="p-6 lg:p-8">
                <!-- Welcome Section -->
                <div class="mb-8 animate-slide">
                    <h3 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Добро пожаловать, {{ Auth::user()->name }}!</h3>
                    <p class="text-gray-600 dark:text-gray-400">
                        Сводка по кассе, залу и складу за сегодня
                    </p>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Sales -->
                    <div class="stat-card bg-white dark:bg-gray-900 rounded-xl p-6 border border-gray-200 dark:border-gray-800 animate-slide animation-delay-100">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Доход сегодня</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($todayIncome, 0, ',', ' ') }} {{ $currency }}</p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-br from-purple-100 to-purple-200 dark:from-purple-900 dark:to-purple-800 rounded-lg flex items-center justify-center">
                                <i class="fas fa-shopping-cart text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-green-600 text-sm">
                            <i class="fas fa-arrow-up"></i>
                            <span>{{ $todayTransactionCount }} операций сегодня</span>
                        </div>
                    </div>

                    <!-- Total Expense -->
                    <div class="stat-card bg-white dark:bg-gray-900 rounded-xl p-6 border border-gray-200 dark:border-gray-800 animate-slide animation-delay-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Расходы сегодня</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($todayExpenses, 0, ',', ' ') }} {{ $currency }}</p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-br from-blue-100 to-blue-200 dark:from-blue-900 dark:to-blue-800 rounded-lg flex items-center justify-center">
                                <i class="fas fa-credit-card text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-red-600 text-sm">
                            <i class="fas fa-arrow-down"></i>
                            <span>{{ $todayExpenseCount }} расходных операций</span>
                        </div>
                    </div>

                    <!-- Total Orders -->
                    <div class="stat-card bg-white dark:bg-gray-900 rounded-xl p-6 border border-gray-200 dark:border-gray-800 animate-slide animation-delay-300">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Чистый итог</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($todayIncome - $todayExpenses, 0, ',', ' ') }} {{ $currency }}</p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-br from-green-100 to-green-200 dark:from-green-900 dark:to-green-800 rounded-lg flex items-center justify-center">
                                <i class="fas fa-boxes text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-green-600 text-sm">
                            <i class="fas fa-arrow-up"></i>
                            <span>Доходы минус расходы за сегодня</span>
                        </div>
                    </div>

                    <!-- Total Revenue -->
                    <div class="stat-card bg-white dark:bg-gray-900 rounded-xl p-6 border border-gray-200 dark:border-gray-800 animate-slide animation-delay-400">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Пользователи</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($userCount, 0, ',', ' ') }}</p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-br from-orange-100 to-orange-200 dark:from-orange-900 dark:to-orange-800 rounded-lg flex items-center justify-center">
                                <i class="fas fa-chart-line text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-green-600 text-sm">
                            <i class="fas fa-arrow-up"></i>
                            <span>Зарегистрировано в системе</span>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Main Chart -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl p-6 border border-gray-200 dark:border-gray-800 animate-slide animation-delay-500">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-6">Доходы и расходы за 7 дней</h3>
                        <canvas id="chart" class="dashboard-chart"></canvas>
                    </div>

                    <!-- Quick Stats -->
                    <div class="bg-white dark:bg-gray-900 rounded-xl p-6 border border-gray-200 dark:border-gray-800 animate-slide animation-delay-600">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-6">Состояние клуба</h3>
                        <div class="space-y-5">
                            <a href="{{ route('club-map.index') }}" class="flex items-center justify-between gap-3 text-sm text-gray-700 dark:text-gray-300"><span><i class="fas fa-desktop mr-2 text-green-600"></i>ПК онлайн</span><strong class="text-gray-900 dark:text-white">{{ $onlineComputerCount }} / {{ $computerCount }}</strong></a>
                            <a href="{{ route('club-map.index') }}" class="flex items-center justify-between gap-3 text-sm text-gray-700 dark:text-gray-300"><span><i class="fas fa-bookmark mr-2 text-amber-500"></i>ПК забронировано</span><strong class="text-gray-900 dark:text-white">{{ $reservedComputerCount }}</strong></a>
                            <a href="{{ route('club-map.index') }}" class="flex items-center justify-between gap-3 text-sm text-gray-700 dark:text-gray-300"><span><i class="fas fa-map-location-dot mr-2 text-blue-600"></i>Зоны</span><strong class="text-gray-900 dark:text-white">{{ $zoneCount }}</strong></a>
                            <a href="{{ route('warehouse') }}" class="flex items-center justify-between gap-3 text-sm text-gray-700 dark:text-gray-300"><span><i class="fas fa-boxes-stacked mr-2 text-rose-500"></i>Товары с низким остатком</span><strong class="text-gray-900 dark:text-white">{{ $lowStockCount }}</strong></a>
                            <a href="{{ route('warehouse') }}" class="flex items-center justify-between gap-3 text-sm text-gray-700 dark:text-gray-300"><span><i class="fas fa-box mr-2 text-gray-500"></i>Всего товаров</span><strong class="text-gray-900 dark:text-white">{{ $productCount }}</strong></a>
                        </div>
                    </div>
                </div>

                <!-- Recent Invoice -->
                <div class="bg-white dark:bg-gray-900 rounded-xl p-6 border border-gray-200 dark:border-gray-800 animate-slide animation-delay-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Последние операции</h3>
                        <a href="{{ route('cash-register.index') }}" class="brand-link text-sm font-medium">Открыть кассу →</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-200 dark:border-gray-800">
                                <tr>
                                    <th class="text-left py-3 font-semibold text-gray-700 dark:text-gray-300">Операция</th>
                                    <th class="text-left py-3 font-semibold text-gray-700 dark:text-gray-300">Описание</th>
                                    <th class="text-left py-3 font-semibold text-gray-700 dark:text-gray-300">Дата</th>
                                    <th class="text-right py-3 font-semibold text-gray-700 dark:text-gray-300">Сумма</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentTransactions as $transaction)
                                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                                        <td class="py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $transaction->type === 'income' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400' }}">{{ $transaction->type === 'income' ? 'Доход' : 'Расход' }}</span></td>
                                        <td class="py-4 text-gray-700 dark:text-gray-300">{{ $transaction->description }}</td>
                                        <td class="py-4 text-gray-600 dark:text-gray-400">{{ $transaction->created_at->format('d.m.Y H:i') }}</td>
                                        <td class="py-4 text-right font-semibold {{ $transaction->type === 'income' ? 'text-green-700 dark:text-green-400' : 'text-rose-700 dark:text-rose-400' }}">{{ $transaction->type === 'expense' ? '−' : '+' }}{{ number_format((float) $transaction->amount, 0, ',', ' ') }} {{ $currency }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">Кассовых операций пока нет.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
    const chartElement = document.getElementById('chart');
    if (chartElement) {
        const ctx = chartElement.getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: @json($chartLabels),
                datasets: [
                    {
                        label: 'Доход',
                        data: @json($chartIncome),
                        backgroundColor: 'rgba(34, 197, 94, 0.75)',
                        borderColor: 'rgba(22, 163, 74, 1)',
                        borderWidth: 1,
                        borderRadius: 8
                    },
                    {
                        label: 'Расход',
                        data: @json($chartExpenses),
                        backgroundColor: 'rgba(244, 63, 94, 0.7)',
                        borderColor: 'rgba(225, 29, 72, 1)',
                        borderWidth: 1,
                        borderRadius: 8
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            color: '#6b7280',
                            font: {
                                size: 12,
                                weight: 'bold'
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#9ca3af',
                            callback: (value) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value),
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: 'rgba(209, 213, 219, 0.1)'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#9ca3af',
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
</script>
@endpush
