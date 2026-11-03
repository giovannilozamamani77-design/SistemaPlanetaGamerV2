<div class="space-y-4">
    <x-filament::section heading="Tiempo registrado">
        <p>{{ $case->real_minutes }} minutos reales · {{ $case->nominal_minutes }} registrados · {{ $case->pending_minutes }} pendientes</p>
        <p>Tipo: {{ $case->kind === 'RETRASO' ? 'Tardanza de apertura' : \App\Filament\Support\Personal\PersonnelLabels::text($case->kind) }}</p>
        <p>Motivo: {{ \App\Domain\ControlHorario\Data\TimeReasonCodes::label($case->reason_code) }}</p>
        <p>Explicación actual: {{ $case->reason ?: 'Sin explicación' }}</p>
        <p>Respuesta: {{ $case->decision_reason ?: 'Aún no hay respuesta' }}</p>
    </x-filament::section>
    <x-filament::section heading="Historial de solicitudes y respuestas">
        <p class="text-sm text-gray-500">Últimos 50 hechos de este registro. Enviar una apelación no elimina el pendiente ni bloquea Caja.</p>
        @forelse ($events as $event)
            <div class="border-b border-gray-200 py-3 dark:border-gray-700">
                <p class="font-semibold">{{ match ($event->action) { 'MOTIVO_O_REVISION' => 'Explicación enviada', 'APELACION_ACEPTAR' => 'Apelación aceptada', 'APELACION_RECHAZAR' => 'Apelación rechazada', default => 'Resolución registrada' } }}</p>
                <p class="text-sm text-gray-500">{{ $event->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }} · {{ $event->actor?->name ?? 'Sistema' }}</p>
                <p class="whitespace-pre-line">{{ $event->details['reason'] ?? 'Sin observación adicional' }}</p>
            </div>
        @empty
            <p>Todavía no hay solicitudes registradas.</p>
        @endforelse
    </x-filament::section>
</div>
