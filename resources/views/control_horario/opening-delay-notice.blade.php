@if ($notice)
<div class="pg-delay-notice" role="status">
    <span class="pg-delay-caption">Tu retraso real de apertura</span>
    <strong class="pg-delay-minutes">{{ $notice['real'] }} <small>min</small></strong>
    <div class="pg-delay-breakdown">
        <p>Registrado según la regla: <b>{{ \App\Support\TimeDuration::format($notice['weighted']) }}</b></p>
        <p>Pendiente después de compensaciones: <b>{{ \App\Support\TimeDuration::format($notice['pending']) }}</b></p>
    </div>
    <p>Puedes continuar trabajando o explicar el motivo. Una apelación no elimina el pendiente hasta que Administración lo resuelva.</p>
</div>
@endif
