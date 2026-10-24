{{-- Refund of one income ($operation): the button while it may be returned, the mark once it was. --}}
@if($operation->refund)
    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Возвращено') }}</span>
@elseif($canRefund && $operation->refundKind() !== null && $operation->shift?->status === \App\Models\CashShift::STATUS_OPEN)
    <form method="POST" action="{{ route('cash-register.refund', $operation) }}" class="inline" onsubmit="return confirm(@js(__('Вернуть :amount KZT по чеку №:number? Деньги выдаются из кассы.', ['amount' => number_format((float) $operation->amount, 2, ',', ' '), 'number' => $operation->id])))">@csrf
        <button type="submit" class="text-sm font-medium text-red-600 hover:underline dark:text-red-400">{{ __('Возврат') }}</button>
    </form>
@endif
