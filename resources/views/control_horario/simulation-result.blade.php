<div class="pg-schedule-simulation">
    <p>Solo simulación · Reglas guardadas de mañana</p>
    @if ($preview['valid'])
        <dl>
            <div><dt>Sin retraso hasta</dt><dd>{{ $preview['tolerance_until'] }}</dd></div>
            <div><dt>Retraso real / acumulado</dt><dd>{{ $preview['real'] }} / {{ $preview['weighted'] }} min</dd></div>
            <div><dt>Cierre normal desde</dt><dd>{{ $preview['normal_closing_from'] }}</dd></div>
            <div><dt>Salida</dt><dd>{{ $preview['early'] ? 'Requeriría autorización' : 'Dentro del horario normal' }}</dd></div>
        </dl>
    @else
        <p>{{ $preview['message'] }}</p>
    @endif
</div>
