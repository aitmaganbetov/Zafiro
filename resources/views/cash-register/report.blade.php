@extends('layouts.admin')
@section('page-title', __(':type-отчёт', ['type' => $reportType]))


@section('content')
<div class="report-page"><div class="report-wrap">
    @if($errors->any())<div class="report-error">{{ $errors->first() }}</div>@endif
    <div class="report-toolbar">
        <a class="report-back" href="{{ route('cash-register.index') }}">{{ __('← Вернуться в кассу') }}</a>
        <div class="report-actions">
            <button type="button" id="print-report" class="report-btn primary">{{ __('Распечатать :type-отчёт', ['type' => $reportType]) }}</button>
            @if($reportType === 'Z' && $shift->status === \App\Models\CashShift::STATUS_OPEN)
                <form method="POST" action="{{ route('cash-register.shifts.close', $shift) }}">@csrf<button id="close-shift" class="report-btn danger" disabled>{{ __('Закрыть смену') }}</button></form>
            @endif
        </div>
    </div>
    <article class="report-paper">
        <header class="report-head"><div><span class="report-type">{{ $reportType }} REPORT</span><h1>{{ __(':type-отчёт', ['type' => $reportType]) }}</h1><p>{{ $reportType === 'X' ? __('Промежуточный отчёт без закрытия смены') : __('Итоговый отчёт кассовой смены') }}</p></div><span class="report-state">{{ $shift->status === 'open' ? __('Смена открыта') : __('Смена закрыта') }}</span></header>
        <div class="report-body">
            <div class="report-info"><div><span>{{ __('Номер смены') }}</span><strong>#{{ $shift->id }}</strong></div><div><span>{{ __('Открыта') }}</span><strong>{{ $shift->opened_at->format('d.m.Y H:i') }}</strong></div><div><span>{{ $shift->closed_at ? __('Закрыта') : __('Сформирован') }}</span><strong>{{ ($shift->closed_at ?? now(app(\App\Services\CurrentClub::class)->timezone()))->format('d.m.Y H:i') }}</strong></div><div><span>{{ __('Кассир') }}</span><strong>{{ $cashierName ?? '—' }}</strong></div>@if($closedByName)<div><span>{{ __('Закрыл смену') }}</span><strong>{{ $closedByName }}</strong></div>@endif<div><span>{{ __('Оператор') }}</span><strong>{{ $operatorName }}</strong></div></div>
            <div class="report-metrics"><div class="report-metric"><span>{{ __('Начальный остаток') }}</span><strong>{{ number_format((float)$shift->opening_balance,2,',',' ') }} KZT</strong></div><div class="report-metric income"><span>{{ __('Приход') }}</span><strong>+ {{ number_format($totals['income'],2,',',' ') }} KZT</strong></div><div class="report-metric expense"><span>{{ __('Расход') }}</span><strong>− {{ number_format($totals['expense'],2,',',' ') }} KZT</strong></div></div>
            <div class="report-total"><span>{{ __('Итог в кассе') }}</span><strong>{{ number_format($totals['balance'],2,',',' ') }} KZT</strong></div>
            {{-- What the shift consists of: income by kind, goods sold, staff. --}}
            <section class="report-section"><h2>{{ __('Приход по видам') }}</h2>
                <table class="report-table"><thead><tr><th>{{ __('Вид') }}</th><th class="num">{{ __('Операций') }}</th><th class="num">{{ __('Сумма') }}</th></tr></thead><tbody>
                @foreach($details['kinds'] as $kind)<tr><td>{{ $kind['label'] }}</td><td class="num">{{ $kind['count'] }}</td><td class="num">{{ number_format($kind['amount'],2,',',' ') }} KZT</td></tr>@endforeach
                </tbody></table>
            </section>
            <section class="report-section"><h2>{{ __('Продано товаров') }}</h2>
                @if($details['items'] === [])
                    <p class="report-empty">{{ __('За смену товары не продавались.') }}</p>
                @else
                    <table class="report-table"><thead><tr><th>{{ __('Товар') }}</th><th class="num">{{ __('Кол-во') }}</th><th class="num">{{ __('Сумма') }}</th></tr></thead><tbody>
                    @foreach($details['items'] as $item)<tr><td>{{ $item['name'] }}</td><td class="num">{{ $item['quantity'] }}</td><td class="num">{{ number_format($item['amount'],2,',',' ') }} KZT</td></tr>@endforeach
                    </tbody><tfoot><tr><td>{{ __('Всего') }}</td><td class="num">{{ array_sum(array_column($details['items'], 'quantity')) }}</td><td class="num">{{ number_format($details['items_total'],2,',',' ') }} KZT</td></tr>
                    @if($details['discount'] > 0)<tr><td colspan="2">{{ __('Скидки') }}</td><td class="num">− {{ number_format($details['discount'],2,',',' ') }} KZT</td></tr>@endif
                    </tfoot></table>
                @endif
            </section>
            @if(count($details['staff']) > 0)
            <section class="report-section"><h2>{{ __('Сотрудники') }}</h2>
                <table class="report-table"><thead><tr><th>{{ __('Сотрудник') }}</th><th class="num">{{ __('Операций') }}</th><th class="num">{{ __('Приход') }}</th><th class="num">{{ __('Расход') }}</th></tr></thead><tbody>
                @foreach($details['staff'] as $person)<tr><td>{{ $person['name'] }}</td><td class="num">{{ $person['count'] }}</td><td class="num">{{ number_format($person['income'],2,',',' ') }} KZT</td><td class="num">{{ number_format($person['expense'],2,',',' ') }} KZT</td></tr>@endforeach
                </tbody></table>
            </section>
            @endif
            @if($reportType === 'Z' && $shift->status === 'open')<div class="report-note">{{ __('Смена останется открытой, пока Z-отчёт не будет отправлен на печать. После завершения диалога печати кнопка закрытия станет активной.') }}</div>@endif
        </div>
    </article>
</div></div>
@endsection

@push('scripts')
<script>
(() => {
    const printButton = document.getElementById('print-report');
    const closeButton = document.getElementById('close-shift');
    let printRequested = false;
    printButton?.addEventListener('click', () => { printRequested = true; window.print(); });
    @if($reportType === 'Z' && $shift->status === \App\Models\CashShift::STATUS_OPEN)
    window.addEventListener('afterprint', async () => {
        if (!printRequested) return;
        printRequested = false;
        const response = await fetch(@json(route('cash-register.z-report.printed', $shift)), {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':@json(csrf_token()),'Accept':'application/json'},body:JSON.stringify({nonce:@json($printNonce)})});
        if (response.ok && closeButton) { closeButton.disabled = false; printButton.textContent = @json(__('Z-отчёт распечатан')); }
    });
    @endif
})();
</script>
@endpush
