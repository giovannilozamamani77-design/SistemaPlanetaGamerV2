@if(isset($quote['error']))<p role="alert">{{ $quote['error'] }}</p>@else
@if(($quote['late_fee_cents'] ?? 0) > 0)<p role="alert">Este préstamo tiene atraso. Debes cobrar el interés pendiente y una multa fija de Bs {{ \App\Support\Bolivianos::formatCompact($quote['late_fee_cents']) }}. No se multiplica por día.</p>@else<p class="pg-loan-record-note">Renovación sin multa.</p>@endif
<div class="pg-loan-preview"><div data-tone="interest"><span>A pagar hoy</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($quote['paid_cents']) }}</strong></div><div><span>Interés pendiente al nuevo vencimiento</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($quote['pending_cents']) }}</strong></div><div><span>Nuevo vencimiento · Bolivia</span><strong>{{ $quote['due_date'] }}</strong></div></div>
<p>Capital pendiente: Bs {{ \App\Support\Bolivianos::formatCompact($quote['capital_cents']) }} · {{ $quote['added_days'] }} días añadidos desde {{ $quote['previous_due_date'] }}.</p>
@endif
