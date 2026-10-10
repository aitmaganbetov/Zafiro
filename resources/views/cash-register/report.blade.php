@extends('layouts.admin')
@section('page-title', $reportType.'-отчёт')


@section('content')
<div class="report-page"><div class="report-wrap">
    @if($errors->any())<div class="report-error">{{ $errors->first() }}</div>@endif
    <div class="report-toolbar">
        <a class="report-back" href="{{ route('cash-register.index') }}">← Вернуться в кассу</a>
        <div class="report-actions">
            <button type="button" id="print-report" class="report-btn primary">Распечатать {{ $reportType }}-отчёт</button>
            @if($reportType === 'Z' && $shift->status === \App\Models\CashShift::STATUS_OPEN)
                <form method="POST" action="{{ route('cash-register.shifts.close', $shift) }}">@csrf<button id="close-shift" class="report-btn danger" disabled>Закрыть смену</button></form>
            @endif
        </div>
    </div>
    <article class="report-paper">
        <header class="report-head"><div><span class="report-type">{{ $reportType }} REPORT</span><h1>{{ $reportType }}-отчёт</h1><p>{{ $reportType === 'X' ? 'Промежуточный отчёт без закрытия смены' : 'Итоговый отчёт кассовой смены' }}</p></div><span class="report-state">{{ $shift->status === 'open' ? 'Смена открыта' : 'Смена закрыта' }}</span></header>
        <div class="report-body">
            <div class="report-info"><div><span>Номер смены</span><strong>#{{ $shift->id }}</strong></div><div><span>Открыта</span><strong>{{ $shift->opened_at->format('d.m.Y H:i') }}</strong></div><div><span>{{ $shift->closed_at ? 'Закрыта' : 'Сформирован' }}</span><strong>{{ ($shift->closed_at ?? now())->format('d.m.Y H:i') }}</strong></div></div>
            <div class="report-metrics"><div class="report-metric"><span>Начальный остаток</span><strong>{{ number_format((float)$shift->opening_balance,2,',',' ') }} KZT</strong></div><div class="report-metric income"><span>Приход</span><strong>+ {{ number_format($totals['income'],2,',',' ') }} KZT</strong></div><div class="report-metric expense"><span>Расход</span><strong>− {{ number_format($totals['expense'],2,',',' ') }} KZT</strong></div></div>
            <div class="report-total"><span>Итог в кассе</span><strong>{{ number_format($totals['balance'],2,',',' ') }} KZT</strong></div>
            @if($reportType === 'Z' && $shift->status === 'open')<div class="report-note">Смена останется открытой, пока Z-отчёт не будет отправлен на печать. После завершения диалога печати кнопка закрытия станет активной.</div>@endif
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
        if (response.ok && closeButton) { closeButton.disabled = false; printButton.textContent = 'Z-отчёт распечатан'; }
    });
    @endif
})();
</script>
@endpush
