<div class="pg-loan-preview" aria-label="Importes que se guardarán" role="status" aria-live="polite">
    <p class="pg-loan-rate">
        @if($terms)
            {{ $terms['type_name'] }} ·
            @if($terms['interest_mode'] === 'MANUAL')
                Interés manual para este periodo.
            @else
                Tasa base del objeto: {{ \App\Support\Bolivianos::formatCompact($terms['rate_bps']) }} % para 30 días.
            @endif
        @else
            Completa el objeto y el capital para calcular los importes.
        @endif
    </p>
    <div data-tone="capital"><span>Capital prestado</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($terms['capital_cents'] ?? 0) }}</strong></div>
    <div data-tone="interest"><span>Interés{{ $terms ? ' · '.$terms['days'].' días' : '' }}</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($terms['interest_cents'] ?? 0) }}</strong></div>
    <div data-tone="total"><span>Total a pagar</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($terms['total_cents'] ?? 0) }}</strong></div>
</div>
