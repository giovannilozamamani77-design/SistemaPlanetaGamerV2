<div class="pg-liquidation-detail-grid">
    <article><span>Caja</span><strong>{{ $record->box->name }}</strong></article>
    <article><span>Cajera responsable</span><strong>{{ $record->user->name }}</strong></article>
    <article><span>Turno</span><strong>{{ $record->period === 'manana' ? 'Mañana' : 'Tarde' }}</strong></article>
    <article><span>Fecha y hora de cierre</span><strong>{{ $record->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }}</strong></article>
    <section><h3>Saldos posteriores</h3><dl>
        @foreach ($record->real_balances ?? [] as $code => $cents)
            <div><dt>{{ $code }}</dt><dd>Bs {{ \App\Support\Bolivianos::format($cents) }}</dd></div>
        @endforeach
    </dl></section>
    <article><span>Saldo posterior total</span><strong>Bs {{ \App\Support\Bolivianos::format($record->realTotal()) }}</strong></article>
    <article><span>Estado</span><strong>{{ $record->cancelled_at ? 'Anulado' : ($record->released_at ? 'Finalizado' : 'Pendiente de revisión') }}</strong></article>
</div>
