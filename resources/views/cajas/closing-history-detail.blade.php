<div class="pg-liquidation-detail-grid">
    <article><span>Total contado original</span><strong>Bs {{ \App\Support\Bolivianos::format($record->countedTotal()) }}</strong></article>
    <article><span>Capital operativo</span><strong>Bs {{ \App\Support\Bolivianos::format($record->capital_cents) }}</strong></article>
    <section><h3>Saldos y entrega posterior</h3><dl>
        @foreach ($record->counted_balances as $code => $cents)<div><dt>{{ $code }}</dt><dd>Contado Bs {{ \App\Support\Bolivianos::format($cents) }} · Posterior Bs {{ \App\Support\Bolivianos::format($record->real_balances[$code] ?? 0) }}</dd></div>@endforeach
    </dl></section>
    <section><h3>Resultado del cierre</h3><dl><div><dt>Diferencia total</dt><dd>Bs {{ \App\Support\Bolivianos::format($record->difference_cents) }}</dd></div><div><dt>Comisiones de la sesión</dt><dd>Bs {{ \App\Support\Bolivianos::format($record->commission_cents) }}</dd></div><div><dt>Liquidación</dt><dd>{{ $record->liquidationLabel() }}</dd></div><div><dt>Motivo</dt><dd>{{ $record->reason ?: 'Sin observaciones' }}</dd></div></dl></section>
    @if (!empty($restorations))<section><h3>Reposiciones reales</h3>
        <p>Estos aportes no eliminan el faltante original ni aumentan el capital objetivo.</p>
        <dl>@foreach ($restorations as $restoration)<div><dt>{{ $restoration->component }} · {{ $restoration->administrator }}</dt>
            <dd>Bs {{ \App\Support\Bolivianos::format($restoration->amount_cents) }} · {{ $restoration->receipt }}</dd></div>@endforeach</dl>
    </section>@endif
    @include('arqueos.count-details', ['count' => $record->count_details['cash_count'] ?? null])
    @if ($record->cancelled_at)<p>Registro histórico anulado. Se conserva para auditoría.</p>@elseif (!$record->released_at)<p>La caja espera confirmación administrativa. Si falta capital, no se inventarán saldos ni se habilitará otra apertura.</p>@endif
</div>
