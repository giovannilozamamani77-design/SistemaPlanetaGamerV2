<div class="pg-loan-record">
    @if($import = $detail['terms_snapshot']['historical_import'] ?? null)
        <section class="pg-loan-record-card"><h3>Préstamo importado del sistema anterior</h3><p>Referencia anterior: <strong>{{ $import['reference'] }}</strong>. Importado por {{ $import['imported_by'] }} el {{ \Carbon\CarbonImmutable::parse($import['imported_at'])->timezone('America/La_Paz')->format('d/m/Y H:i') }}.</p><p>Ajuste neto registrado en el fondo: Bs {{ \App\Support\Bolivianos::format($import['fund_delta_cents']) }}. Se conservan las fechas y el cajero originales.</p></section>
    @endif
    <div class="pg-loan-record-parties">
        <section class="pg-loan-record-card">
            <h3>Cliente</h3>
            <dl class="pg-loan-record-fields pg-loan-record-client">
                <div><dt>Nombre completo</dt><dd>{{ $detail['borrower']['name'] }}</dd></div>
                <div><dt>CI</dt><dd>{{ $detail['borrower']['ci'] }}</dd></div>
                <div><dt>Teléfono / WhatsApp</dt><dd>{{ filled($detail['phone_display']) ? $detail['phone_display'] : 'Sin teléfono' }}</dd></div>
            </dl>
        </section>
        <section class="pg-loan-record-card">
            <h3>Objeto en garantía</h3>
            <dl class="pg-loan-record-fields">
                <div><dt>Tipo de objeto</dt><dd>{{ $detail['object']['type_name'] }}</dd></div>
                <div><dt>Detalle del objeto</dt><dd>{{ $detail['object']['description'] }}</dd></div>
                @foreach(['serial' => 'Serie / IMEI', 'condition' => 'Estado recibido', 'accessories' => 'Accesorios'] as $key => $label)
                    @if(filled($detail['object'][$key] ?? null))
                        <div><dt>{{ $label }}</dt><dd>{{ $detail['object'][$key] }}</dd></div>
                    @endif
                @endforeach
            </dl>
        </section>
    </div>
    <section class="pg-loan-record-card">
        <h3>Datos originales del préstamo</h3>
        <dl class="pg-loan-record-fields pg-loan-record-contract">
            <div><dt>Número de recibo</dt><dd>{{ $detail['receipt_number'] }}</dd></div>
            <div><dt>Capital prestado</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($detail['capital_cents']) }}</dd></div>
            <div><dt>Interés del periodo</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($detail['interest_cents']) }}</dd></div>
            <div data-tone="total"><dt>Total a pagar</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($detail['total_cents']) }}</dd></div>
            <div><dt>Plazo</dt><dd>{{ $detail['days'] }} días</dd></div>
            <div><dt>Fecha de inicio</dt><dd><time datetime="{{ $detail['start_date'] }}">{{ \Carbon\CarbonImmutable::parse($detail['start_date'])->format('d/m/Y') }}</time></dd></div>
            <div><dt>Vencimiento original</dt><dd><time datetime="{{ $detail['due_date'] }}">{{ \Carbon\CarbonImmutable::parse($detail['due_date'])->format('d/m/Y') }}</time></dd></div>
            <div><dt>Estado actual</dt><dd><span class="pg-loan-record-status" data-state="{{ $detail['status'] }}">{{ match($detail['status']) { 'VENDIDO' => 'Vendido', 'ANULADO' => 'Anulado', 'RECOGIDO_PAGADO' => 'Recogido y pagado', 'ACTIVO' => 'Activo', 'CON_MULTA' => 'Con multa', 'PERDIDA_TOTAL' => 'Pérdida total', default => $detail['status'] } }}</span></dd></div>
        </dl>
        <p class="pg-loan-record-note">{{ ($detail['terms_snapshot']['schedule_mode'] ?? 'AUTO') === 'MANUAL' ? 'Fechas manuales' : 'Fechas automáticas' }} · {{ ($detail['terms_snapshot']['interest_mode'] ?? 'AUTO') === 'MANUAL' ? 'Interés manual del periodo registrado' : 'Interés automático' }} · Tasa base original: {{ \App\Support\Bolivianos::formatCompact($detail['rate_bps']) }} % para 30 días. Vencimiento al finalizar el día en Bolivia.</p>
    </section>
    @if(($detail['late_fee_cents'] ?? 0) > 0 && $detail['status'] === 'CON_MULTA')
    <p role="alert" class="pg-loan-record-note">Multa fija: Bs {{ \App\Support\Bolivianos::formatCompact($detail['late_fee_cents']) }} · Total actual para recoger: <strong>Bs {{ \App\Support\Bolivianos::formatCompact($detail['collection_cents']) }}</strong>. Válido hasta el día 7 de atraso; desde el día 8 es pérdida total.</p>
    @endif
    @if(($detail['current']['revision'] ?? 0) > 0)
        <section class="pg-loan-record-card">
            <h3>Situación vigente</h3>
            <dl class="pg-loan-record-fields pg-loan-record-current">
                <div><dt>Vencimiento vigente</dt><dd><time datetime="{{ $detail['current']['due_date'] }}">{{ \Carbon\CarbonImmutable::parse($detail['current']['due_date'])->format('d/m/Y') }}</time></dd></div>
                <div><dt>Periodo actual</dt><dd>{{ $detail['current']['days'] }} días</dd></div>
                <div><dt>Interés pendiente</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($detail['current']['pending_cents']) }}</dd></div>
                @if($detail['collection_cents'] !== null)<div><dt>Total actual para recoger</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($detail['collection_cents']) }}</dd></div>@endif
            </dl>
        </section>
    @endif
    @if($detail['settlement'] ?? null)
    @include('prestamos.settlement-summary', ['summary' => $detail['settlement']])
    <p class="pg-loan-record-note">Objeto entregado · {{ $detail['settlement']['registered_by'] }} · {{ $detail['settlement']['registered_at'] }} (Bolivia). Deuda pendiente: Bs 0.</p>
    @endif
    @if($detail['annulment'] ?? null)
    <section class="pg-loan-record-card">
        <h3>Préstamo anulado</h3>
        <dl class="pg-loan-record-fields">
            <div><dt>Capital restablecido</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($detail['annulment']['capital_cents']) }}</dd></div>
            <div><dt>Anulado por</dt><dd>{{ $detail['annulment']['registered_by'] }}</dd></div>
            <div><dt>Fecha y hora</dt><dd>{{ $detail['annulment']['registered_at'] }} · Bolivia</dd></div>
            <div><dt>Motivo</dt><dd>{{ $detail['annulment']['reason'] ?: 'Sin motivo adicional' }}</dd></div>
        </dl>
    </section>
    @endif
    @if($detail['expenses'])
    <section class="pg-loan-record-card"><h3>Gastos del objeto</h3><p class="pg-loan-record-note">Pagados desde Yape Préstamos. El capital original no cambia.</p>
        <dl class="pg-loan-record-fields">@foreach($detail['expenses'] as $expense)<div><dt>{{ $expense['description'] }}</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($expense['amount_cents']) }} · {{ $expense['registered_by'] }} · {{ $expense['registered_at'] }}</dd></div>@endforeach</dl>
    </section>
    @endif
    @if($detail['sale'])
    <section class="pg-loan-record-card"><h3>Objeto vendido</h3><dl class="pg-loan-record-fields">
        <div><dt>Precio cobrado</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($detail['sale']['price_cents']) }}</dd></div>
        <div><dt>Comprador</dt><dd>{{ $detail['sale']['buyer'] ?: 'Sin nombre registrado' }}</dd></div>
        <div><dt>Registrado por</dt><dd>{{ $detail['sale']['registered_by'] }} · {{ $detail['sale']['registered_at'] }} · Bolivia</dd></div>
        @if($detail['sale']['sale_notes'] ?? null)<div><dt>Estado y observaciones</dt><dd>{{ $detail['sale']['sale_notes'] }}</dd></div>@endif
        @if(isset($detail['sale']['loan_profit_cents']))<div><dt>Ganancia del préstamo</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($detail['sale']['loan_profit_cents']) }}</dd></div><div><dt>Ganancia por venta</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($detail['sale']['sale_profit_cents']) }}</dd></div>@endif
    </dl></section>
    @endif
    <section class="pg-loan-record-card">
        <h3>Registro</h3>
        <dl class="pg-loan-record-fields">
            <div><dt>Registrado por</dt><dd>{{ $detail['registered_by'] }}</dd></div>
            <div><dt>Fecha y hora de registro</dt><dd>{{ $detail['created_at'] }} · Bolivia</dd></div>
        </dl>
    </section>
</div>
