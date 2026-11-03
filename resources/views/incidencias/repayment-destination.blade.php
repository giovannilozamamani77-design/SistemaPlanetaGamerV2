<div class="pg-repayment-destination">
    <p>Importe a reponer: <strong>Bs {{ \App\Support\Bolivianos::format($amount) }}</strong></p>
    @if ($account)
    <p>{{ $account->name }} · <strong>{{ $account->destination }}</strong></p>
    <p>Titular: {{ $account->holder_name ?: 'Consulta a Administración antes de transferir' }}</p>
    @else<p role="alert">{{ $message }}</p>@endif
    <p>Si ya depositaste, utiliza ese ID. No repitas el envío. Puedes avisar a Administración por WhatsApp para que revise; el sistema no envía mensajes automáticamente.</p>
</div>
