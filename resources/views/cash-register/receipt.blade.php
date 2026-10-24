@extends('layouts.admin')
@section('page-title', __('Чек №:number', ['number' => $transaction->id]))


@section('content')
<div class="receipt-page"><div class="receipt-wrap">
    @if(session('status'))<div class="mb-3 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>@endif
    <div class="receipt-actions"><a href="{{ route('cash-register.index') }}">{{ __('В кассу') }}</a><button type="button" onclick="window.print()">{{ __('Распечатать чек') }}</button>@include('cash-register.refund-button', ['operation' => $transaction])</div>
    @error('refund')<div class="mb-3 rounded-lg bg-red-50 p-3 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror
    <article class="receipt">
        <div class="receipt-logo"><strong>ZAFIRO</strong><span>{{ __('Кассовый чек') }}</span></div><div class="receipt-dash"></div>
        <div class="receipt-row"><span>{{ __('Чек') }}</span><strong>№{{ $transaction->id }}</strong></div><div class="receipt-row"><span>{{ __('Смена') }}</span><strong>№{{ $transaction->cash_shift_id }}</strong></div><div class="receipt-row"><span>{{ __('Дата') }}</span><strong>{{ $transaction->created_at->format('d.m.Y H:i') }}</strong></div><div class="receipt-dash"></div>
        @forelse(data_get($transaction->receipt_data, 'items', []) as $item)<div class="receipt-item"><div class="receipt-item-name">{{ $item['name'] }}</div><div class="receipt-item-detail"><span>{{ $item['quantity'] }} × {{ number_format($item['price'],2,',',' ') }}</span><strong>{{ number_format($item['total'],2,',',' ') }} KZT</strong></div></div>@empty<div class="receipt-row"><span>{{ $transaction->description }}</span><strong>{{ number_format((float)$transaction->amount,2,',',' ') }} KZT</strong></div>@endforelse
        <div class="receipt-dash"></div><div class="receipt-total"><span>{{ __('ИТОГО') }}</span><span>{{ number_format((float)$transaction->amount,2,',',' ') }} KZT</span></div><div class="receipt-thanks">{{ __('Спасибо за покупку!') }}<br>{{ __('Сохраняйте чек до конца обслуживания.') }}</div>
    </article>
</div></div>
@endsection
