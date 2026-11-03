<div class="space-y-4">
    @isset($origin)
        <div class="rounded-lg border p-3 space-y-2">
            <p class="font-semibold">{{ $origin['label'] }}</p>
            <p>{{ $origin['explanation'] }}</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <p>Responsable: <strong>{{ $origin['responsible'] ?? 'Sin dato registrado' }}</strong></p>
                <p>Detectado por: <strong>{{ $origin['detected_by'] ?? 'Sin dato registrado' }}</strong></p>
                <p>Detección: {{ $origin['detected_at'] }}</p>
                <p>{{ $origin['shift_caption'] }}: {{ $origin['shift'] ?? 'Sin turno registrado' }}</p>
            </div>
        </div>
    @endisset
    @isset($record)<div class="rounded-lg border p-3">
        <p>{{ $record->occurred_at->timezone('America/La_Paz')->format('d/m/Y H:i') }} · {{ $record->box_label ?: $record->source_module }}</p>
        <p>Faltante original: Bs {{ \App\Support\Bolivianos::format($record->amount_cents) }}</p>
        <p>Observación: {{ $record->reason }}</p>
        @if ($record->receipt_justification)<p>Tu explicación: {{ $record->receipt_justification }}</p>@endif
        @if ($record->decision)<p>Respuesta administrativa: {{ $record->decision }}</p>@endif
    </div>@endisset
    <h3 class="font-semibold">Aportes y verificaciones</h3>
    @forelse ($payments as $payment)
        <div class="rounded-lg border p-3">
            <p>{{ $payment->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }} · {{ $payment->kind }} · {{ $payment->method }} · Bs {{ \App\Support\Bolivianos::format($payment->amount_cents) }}</p>
            <p>{{ $payment->rejected_at ? 'Reporte rechazado: '.$payment->rejection_reason : ($payment->confirmed_at ? 'Verificado por Administración' : 'Pendiente de verificación; todavía no reduce deuda') }}</p>
            <p>Entrega: {{ $payment->payer?->name ?? "Responsable" }} · Verifica: {{ $payment->receiver?->name ?? "Pendiente" }}</p><p>{{ $payment->note }}</p>
            @if ($payment->operation_reference)<p>Operación: {{ $payment->operation_reference }} · Destino: {{ $payment->destination }}</p>@endif
            @if ($payment->salary_period)<p>Periodo salarial: {{ $payment->salary_period }}</p>@endif
        </div>
    @empty
        <p>No hay aportes registrados.</p>
    @endforelse
    <h3 class="font-semibold">Decisiones del caso</h3>
    @foreach ($events as $event)
        <div class="rounded-lg border p-3">
            <p>{{ \Carbon\CarbonImmutable::parse($event->created_at, "UTC")->setTimezone("America/La_Paz")->format("d/m/Y H:i") }} · {{ $event->action }}</p>
            <p>{{ $event->details['reason'] ?? $event->details['text'] ?? $event->details['receipt'] ?? '' }}</p>
        </div>
    @endforeach
</div>

