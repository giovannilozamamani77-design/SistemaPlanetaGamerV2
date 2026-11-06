<x-filament-panels::page>
    @php
        $state = $this->settingsState;
        $s = $state['settings'];
        $shifts = $this->savedShiftSummary;
    @endphp
    <div class="pg-schedule-console">
        <header class="pg-schedule-overview">
            <div class="flex items-center gap-3">
                <span class="pg-schedule-symbol">@svg('heroicon-o-clock', 'size-6')</span>
                <div><h2>{{ $state['paused'] ? 'Controles de Cajas pausados' : 'Controles de Cajas activos' }}</h2><p>Configura cada sección desde su ventana. Hora del servidor: Bolivia.</p></div>
            </div>
            <x-filament::badge :color="$state['paused'] ? 'warning' : 'success'">{{ $state['paused'] ? 'Pausado' : 'Activo' }}</x-filament::badge>
        </header>

        <div class="pg-schedule-grid">
            <article class="pg-schedule-card">
                <div class="pg-schedule-card-title"><span class="pg-schedule-symbol">@svg('heroicon-o-power', 'size-5')</span><h3>Control general</h3></div>
                <strong>{{ $state['paused'] ? 'En pausa' : 'En funcionamiento' }}</strong>
                <p>Activa o pausa las verificaciones de apertura y salida.</p>
                <footer>{{ $this->configureControlAction }}</footer>
            </article>
            @foreach (['manana' => ['Mañana', 'heroicon-o-sun'], 'tarde' => ['Tarde', 'heroicon-o-moon']] as $period => [$label, $icon])
                <article class="pg-schedule-card">
                    <div class="pg-schedule-card-title"><span class="pg-schedule-symbol">@svg($icon, 'size-5')</span><h3>{{ $label }}</h3><span class="pg-schedule-small-state">{{ $shifts[$period]['enabled'] ? 'Habilitado' : 'Desactivado' }}</span></div>
                    <strong>{{ $shifts[$period]['start'] }} — {{ $shifts[$period]['end'] }}</strong>
                    <div class="pg-schedule-turn-values"><span>Sin retraso hasta <b>{{ $shifts[$period]['arrival_until'] }}</b></span><span>Cierre desde <b>{{ $shifts[$period]['closing_from'] }}</b></span></div>
                    <footer>{{ $period === 'manana' ? $this->configureMorningAction : $this->configureAfternoonAction }}</footer>
                </article>
            @endforeach
            <article class="pg-schedule-card">
                <div class="pg-schedule-card-title"><span class="pg-schedule-symbol">@svg('heroicon-o-adjustments-horizontal', 'size-5')</span><h3>Retrasos y créditos</h3></div>
                <strong>Factor ×{{ $s['delay_multiplier'] }} <small>{{ $s['multiplier_minutes'] ? 'durante '.$s['multiplier_minutes'].' min' : 'en todo el retraso' }}</small></strong>
                <p>Crédito verificado: {{ $s['automatic_credit'] ? 'compensación automática' : 'aplicación manual' }}.</p>
                <footer>{{ $this->configureDelayAction }}</footer>
            </article>
            <article class="pg-schedule-card">
                <div class="pg-schedule-card-title"><span class="pg-schedule-symbol">@svg('heroicon-o-building-storefront', 'size-5')</span><h3>Cajas incluidas</h3></div>
                <strong>{{ count($s['schedule_cash_box_ids']) }} caja(s) seleccionada(s)</strong>
                <p>Solo las cajas seleccionadas.</p>
                <details class="pg-schedule-box-details">
                    <summary>Ver cajas y estado</summary>
                <ul class="pg-schedule-box-list" aria-label="Control de horario por caja">
                    @forelse ($state['box_controls'] as $box)
                        <li>
                            <div><b>{{ $box['name'] }}</b><small>{{ $box['code'] }}</small></div>
                            <x-filament::badge :color="$box['enabled'] ? 'success' : ($box['selected'] ? 'warning' : 'gray')">{{ $box['enabled'] ? 'Con control' : ($box['selected'] ? 'Seleccionada · en pausa' : 'Sin control') }}</x-filament::badge>
                        </li>
                    @empty
                        <li>No hay cajas creadas todavía.</li>
                    @endforelse
                </ul>
                </details>
                <footer>{{ $this->configureBoxesAction }}</footer>
            </article>
            <article class="pg-schedule-card pg-schedule-card-example">
                <div class="pg-schedule-card-title"><span class="pg-schedule-symbol">@svg('heroicon-o-play', 'size-5')</span><h3>Simulación</h3></div>
                <strong>Prueba antes de activar</strong>
                <p>Revisa un ejemplo de llegada y salida sin registrar datos.</p>
                <footer>{{ $this->simulateScheduleAction }}</footer>
            </article>
        </div>

        <section class="pg-schedule-flow" aria-labelledby="schedule-flow-title">
            <div class="pg-schedule-flow-heading"><h2 id="schedule-flow-title">Cómo funciona</h2><p>Configurar no asigna personal: usa Personal → Turnos asignados para asignar cajera y turno de lunes a sábado.</p></div>
            <ol>
                <li><span>1</span><div><b>PIN</b><small>Marca la llegada</small></div></li>
                <li><span>2</span><div><b>Abrir caja</b><small>Verifica el retraso</small></div></li>
                <li><span>3</span><div><b>Trabajar</b><small>Crédito solo verificado</small></div></li>
                <li><span>4</span><div><b>Cerrar caja</b><small>Verifica hora o permiso</small></div></li>
            </ol>
        </section>
    </div>
</x-filament-panels::page>
