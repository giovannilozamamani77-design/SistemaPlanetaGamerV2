<div class="pg-correction-detail">
    <section class="pg-correction-section" aria-label="Registro original">
        <header><h3>Registro original</h3><span>Operación #{{ $request->operation->id }} · {{ $request->operation->status }}</span></header>
        <dl class="pg-correction-grid">
            <div><dt>Caja</dt><dd>{{ $request->session->box->name }}</dd></div>
            <div><dt>Cajera titular</dt><dd>{{ $request->session->user->name }}</dd></div>
            <div><dt>Registrada</dt><dd>{{ $request->operation->created_at->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</dd></div>
            <div><dt>Tipo</dt><dd>{{ match ($request->operation->fee_type) { 'ACH' => 'ACH / otros bancos', 'FACTURAS' => 'Pago de servicios', 'INTERNACIONAL' => 'Margen internacional', default => 'Movimiento' } }}</dd></div>
            <div><dt>Importe registrado</dt><dd>Bs {{ \App\Support\Bolivianos::format($request->operation->amount_cents) }}</dd></div>
            <div><dt>Comisión original</dt><dd>Bs {{ \App\Support\Bolivianos::format($request->operation->fee_cents) }}</dd></div>
            @if ($request->operation->movement)<div><dt>Movimiento</dt><dd>{{ $request->operation->movement === 'DEPOSITO' ? 'Depósito' : 'Retiro' }}</dd></div>@endif
            @if ($request->operation->source)<div><dt>Origen</dt><dd>{{ $request->session->configuration->accounts[$request->operation->source]['name'] ?? $request->operation->source }}</dd></div>@endif
            @if ($request->operation->destination)<div><dt>Destino</dt><dd>{{ $request->session->configuration->accounts[$request->operation->destination]['name'] ?? $request->operation->destination }}</dd></div>@endif
        </dl>
        @if ($request->operation->commission_only)<p class="pg-correction-note">El importe es informativo: esta operación registró únicamente la comisión.</p>@endif
        @if ($request->operation->reason)<p class="pg-correction-reason">{{ $request->operation->reason }}</p>@endif
    </section>
    <section class="pg-correction-section" aria-label="Motivo de la solicitud">
        <header><h3>Motivo de la solicitud</h3><span>{{ $request->status }} · {{ $request->created_at->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</span></header>
        <p class="pg-correction-reason">{{ $request->reason }}</p>
    </section>
    @if ($request->decided_at)
        <section class="pg-correction-section" aria-label="Última decisión registrada">
            <header><h3>Última decisión registrada</h3><span>{{ $request->reviewer?->name }} · {{ $request->decided_at->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</span></header>
            <p class="pg-correction-reason">{{ $request->decision_reason }}</p>
        </section>
    @endif
    @include('cajas.correction-history', ['request' => $request])
    <p class="pg-correction-note">La revisión conserva el registro original. No elimina comisiones ni realiza transferencias bancarias.</p>
</div>
