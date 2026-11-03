<div class="pg-closing-summary-details">
    <section class="pg-closing-counter-card"><h3>Origen: {{ $quote['source'] }}</h3>
        <p>Importe: <strong>Bs {{ \App\Support\Bolivianos::format($quote['calculation']['amount_cents']) }}</strong></p>
        <p>Capital que queda: Bs {{ \App\Support\Bolivianos::format($quote['calculation']['capital_after_cents']) }}</p>
        @if ($quote['calculation']['shortage_cents'] > 0)<p role="alert">No transfieras: falta reponer capital.</p>
        @elseif ($quote['calculation']['amount_cents'] > 0 && !$quote['calculation']['can_liquidate'])<p role="alert">No transfieras: el origen no tiene saldo suficiente.</p>@endif
        <p>La liquidación no cancela el faltante original. Si ya transferiste, usa esa referencia; no repitas el envío.</p>
    </section>
    @include('liquidaciones.account', ['account' => $quote['account']])
</div>
