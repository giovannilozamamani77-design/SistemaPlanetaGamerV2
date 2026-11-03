<section class="pg-correction-section" aria-label="Historial de la corrección">
    <header><h3>Historial de la corrección</h3></header>
    <ol class="pg-correction-timeline">
        @foreach ($request->historyEntries() as $entry)
            <li><strong>{{ match ($entry['kind']) { 'COMPLETADA' => 'Solicitud completada', 'DECISION' => 'Respuesta de Administración', default => 'Solicitud registrada' } }} · {{ $entry['status'] }}</strong>
                <small>{{ $entry['actor_name'] ?? 'Usuario registrado' }} · {{ \Carbon\CarbonImmutable::parse($entry['at'])->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}
                    @if ($entry['legacy'] ?? false) · Registro previo al historial detallado
                    @endif
                </small>
                <p class="pg-correction-reason">{{ $entry['reason'] }}</p></li>
        @endforeach
    </ol>
</section>
