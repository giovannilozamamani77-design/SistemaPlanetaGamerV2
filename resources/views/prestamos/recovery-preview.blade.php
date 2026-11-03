@if(isset($quote['error']))
<p role="alert">{{ $quote['error'] }}</p>
@else
<section class="pg-loan-record-card"><h3>Mínimo obligatorio de venta</h3><strong>Bs {{ \App\Support\Bolivianos::formatCompact($quote['minimum_cents']) }}</strong>
<dl class="pg-loan-record-fields"><div><dt>Capital</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($quote['capital_cents']) }}</dd></div><div><dt>Interés pendiente</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($quote['interest_cents']) }}</dd></div><div><dt>Multa</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($quote['late_fee_cents']) }}</dd></div><div><dt>Gastos registrados</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($quote['expense_cents']) }}</dd></div>
@if(isset($quote['loan_profit_cents']))<div><dt>Ganancia del préstamo</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($quote['loan_profit_cents']) }}</dd></div>@endif
@if(isset($quote['sale_profit_cents']))<div><dt>Ganancia por venta</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($quote['sale_profit_cents']) }}</dd></div>@endif</dl></section>
@endif
