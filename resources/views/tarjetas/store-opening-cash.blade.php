<div class="pg-store-count-summary" aria-label="Efectivo de apertura">
    <div><span>Efectivo esperado</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($expected_cash) }}</strong></div>
    @if ($reserved_cash)<small>Incluye Bs {{ \App\Support\Bolivianos::formatCompact($reserved_cash) }} en reserva; cuéntalo por separado del disponible.</small>@endif
</div>
