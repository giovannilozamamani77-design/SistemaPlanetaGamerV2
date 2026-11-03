<div class="pg-liquidation-detail-grid">
    <article><span>Importe liquidado</span><strong>Bs {{ \App\Support\Bolivianos::format($record->amount_cents) }}</strong></article>
    <article><span>Capital de referencia del cierre</span><strong>Bs {{ \App\Support\Bolivianos::format($record->close->capital_cents) }}</strong></article>
    <section><h3>Registro de la transferencia</h3><dl><div><dt>Caja</dt><dd>{{ $record->box->name }}</dd></div><div><dt>Cajera</dt><dd>{{ $record->user->name }}</dd></div><div><dt>Fecha y hora</dt><dd>{{ $record->created_at->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</dd></div><div><dt>Turno</dt><dd>{{ $record->close->period === 'manana' ? 'Mañana' : 'Tarde' }}</dd></div></dl></section>
    <section><h3>Origen y destino registrados</h3><dl><div><dt>Origen</dt><dd>{{ match ($record->source) { 'bcp' => 'Cuenta BCP', 'yape' => 'Yape operativo', default => $record->source } }}</dd></div><div><dt>Destino</dt><dd>{{ $record->destination }}</dd></div><div><dt>Referencia de operación</dt><dd>{{ $record->operation_reference ?: 'Sin referencia' }}</dd></div><div><dt>Comprobante</dt><dd>{{ $record->receipt_path ? 'Adjuntado' : 'No adjuntado' }}</dd></div></dl></section>
    @if ($record->close->destinationAccount)@include('liquidaciones.account', ['account' => $record->close->destinationAccount])@endif
    @if ($record->close->cancelled_at)<p>El cierre asociado fue anulado. La transferencia registrada se conserva para auditoría.</p>@endif
</div>
