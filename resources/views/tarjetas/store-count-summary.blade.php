<div class="pg-store-count-summary" aria-label="Comparación del conteo">
    <div><span>Efectivo esperado</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($expected_cash) }}</strong></div>
    @if ($comparison && $summary)
        <div><span>Efectivo contado</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($comparison['cash']) }}</strong></div>
        <div><span>Faltante efectivo</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($comparison['cash_missing']) }}</strong></div>
        <div><span>Sobrante efectivo</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($comparison['cash_surplus']) }}</strong></div>
        <div><span>Faltan tarjetas</span><strong>{{ $summary['stock']['missing'] }}</strong></div>
        <div><span>Sobran tarjetas</span><strong>{{ $summary['stock']['surplus'] }}</strong></div>
        @if ($comparison['shortage_cents'])<small>Faltante total: <b>Bs {{ \App\Support\Bolivianos::formatCompact($comparison['shortage_cents']) }}</b>. Los sobrantes se registran por separado.</small>@endif
    @else
        <small>Revisa el efectivo y las cantidades contadas.</small>
    @endif
    @if ($reserved_cash)<small>Incluye Bs {{ \App\Support\Bolivianos::formatCompact($reserved_cash) }} en reserva: cuéntalo, no está disponible para operar.</small>@endif
</div>
