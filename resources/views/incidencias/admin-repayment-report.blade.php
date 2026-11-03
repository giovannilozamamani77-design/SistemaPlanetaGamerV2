<div class="pg-repayment-destination">
    <p>{{ $payment->economicIncident->user->name }} · {{ $payment->economicIncident->box_label ?: 'Otro origen' }}</p>
    <p>ID: <strong>{{ $payment->operation_reference ?: 'Constancia interna' }}</strong></p>
    <p>Depósito informado: Bs {{ \App\Support\Bolivianos::format($payment->amount_cents) }} · Destino {{ $payment->destination ?: 'Ver constancia' }}</p>
    <p>Observación original: {{ $payment->economicIncident->reason }}</p>
    @if ($payment->economicIncident->receipt_justification)<p>Explicación o apelación: {{ $payment->economicIncident->receipt_justification }}</p>@endif
    <p>Verifica el depósito por su ID. Esto acredita el dinero recibido; no es una nueva aprobación del faltante activo. Rechazar el ID conserva la deuda y permite informar el ID correcto.</p>
    @if (in_array($payment->economicIncident->status, ['DETECTADA', 'EN_REVISION'], true))<p>Este es un registro anterior aún por revisar. Se conservan sus comprobaciones originales.</p>@endif
</div>
