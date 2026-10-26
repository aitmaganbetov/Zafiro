@extends('layouts.admin')
@section('page-title', __('Операции смены'))

@section('content')
<div class="mx-auto max-w-5xl space-y-4 p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('Операции смены #:id', ['id' => $shift->id]) }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Приход открытой смены. Возврат продажи вернёт товар на склад, возврат пополнения спишет сумму с баланса клиента; деньги выдаются из кассы.') }}</p>
        </div>
        <a href="{{ route('cash-register.index') }}" class="text-sm font-medium text-gray-700 hover:underline dark:text-gray-300">{{ __('← Вернуться в кассу') }}</a>
    </div>
    @if(session('status'))<div class="rounded-lg bg-green-50 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>@endif
    @error('refund')<div class="rounded-lg bg-red-50 p-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror
    @unless($canRefund)<p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('Нет права на возвраты. Его даёт владелец клуба в «Настройки → Права».') }}</p>@endunless

    <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full text-left text-sm text-gray-700 dark:text-gray-300">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr><th class="px-5 py-3">{{ __('Чек') }}</th><th class="px-5 py-3">{{ __('Время') }}</th><th class="px-5 py-3">{{ __('Операция') }}</th><th class="px-5 py-3 text-right">{{ __('Сумма') }}</th><th class="px-5 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($operations as $operation)
                    @php($operation->setRelation('shift', $shift))
                    <tr>
                        <td class="whitespace-nowrap px-5 py-3"><a href="{{ route('cash-register.receipt', $operation) }}" class="font-medium text-green-700 hover:underline dark:text-green-400">№{{ $operation->id }}</a></td>
                        <td class="whitespace-nowrap px-5 py-3">{{ $operation->created_at->format('H:i') }}</td>
                        {{-- The description of a POS sale is a marker; it is shown translated. --}}
                        <td class="px-5 py-3">{{ $operation->description === \App\Models\CashTransaction::POS_SALE ? __('Продажа товаров') : $operation->description }}@if(is_array($operation->receipt_data['items'] ?? null))<span class="block text-xs text-gray-500 dark:text-gray-400">{{ collect($operation->receipt_data['items'])->map(fn ($item) => ($item['name'] ?? '').' × '.($item['quantity'] ?? 1))->implode(', ') }}</span>@endif</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">{{ number_format((float) $operation->amount, 2, ',', ' ') }} KZT</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right">@include('cash-register.refund-button')</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500 dark:text-gray-400">{{ __('В этой смене ещё нет прихода.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $operations->links() }}
</div>
@endsection
