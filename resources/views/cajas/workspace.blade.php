<div class="pg-workspace" wire:key="cash-box-clock-{{ $workspace['session_id'] }}"
    data-elapsed-seconds="{{ $workspace['elapsed_seconds'] }}"
    x-data="{
        elapsed: '',
        timer: null,
        baseline: 0,
        initialSeconds: 0,
        init() {
            this.initialSeconds = Number(this.$el.dataset.elapsedSeconds);
            this.baseline = Date.now();
            this.resume();
        },
        update() {
            const seconds = Math.max(0, this.initialSeconds + (Date.now() - this.baseline) / 1000);
            const minutes = Math.floor(seconds / 60);
            this.elapsed = Math.floor(minutes / 60) + ' h ' + (minutes % 60) + ' min';
        },
        pause() {
            clearInterval(this.timer);
            this.timer = null;
        },
        resume() {
            this.pause();
            this.update();
            if (!document.hidden) this.timer = setInterval(() => this.update(), 1000);
        },
        destroy() { this.pause(); }
    }"
    x-on:visibilitychange.document="document.hidden ? pause() : resume()">

    <header class="pg-workspace-heading pg-workspace-heading-compact">

        <div class="pg-workspace-identity"><h2>{{ $workspace['box'] }}</h2><p>{{ $workspace['type'] === 'BCP' ? 'Agente BCP' : 'Tigo Money' }}@if($workspace['bcp_account_reference']) · Cuenta BCP: <strong>{{ $workspace['bcp_account_reference'] }}</strong>@endif</p></div>

        <div class="pg-workspace-status"><span>Abierta</span><strong>{{ $workspace['cashier'] }}</strong><div class="pg-workspace-buttons">{{ $this->detalleAperturaAction }}</div></div>

    </header>

    <div class="pg-workspace-metrics pg-workspace-metrics-with-funds">

        @foreach (['opening_total' => ['Saldo inicial', 'Registrado al abrir', 'heroicon-o-banknotes'], 'expected_total' => ['Saldo esperado', 'Tras los movimientos registrados', 'heroicon-o-arrow-path'], 'commission_and_surplus_total' => ['Comisiones y sobrantes', 'Comisiones de servicio y sobrante de apertura', 'heroicon-o-calculator'], 'elapsed_label' => ['Tiempo abierta', $workspace['opened_at'], 'heroicon-o-clock']] as $key => [$label, $hint, $icon])

            <article class="pg-workspace-metric pg-workspace-metric-{{ $key }}"><span class="pg-workspace-icon">@svg($icon)</span><div><p>{{ $label }}</p><strong @if ($key === 'elapsed_label') x-text="elapsed" @endif>{{ $key === 'elapsed_label' ? $workspace[$key] : 'Bs '.\App\Support\Bolivianos::format($workspace[$key]) }}</strong><small>{{ $hint }}</small></div></article>

        @endforeach

        <article class="pg-workspace-funds-card"><h3>Fondos temporales</h3><p>Dinero de Administración para operar.</p><div>{{ $this->depositoTemporalAction }} {{ $this->retiroTemporalAction }}</div></article>

    </div>

    @if (array_sum($workspace['reserved_funds']) > 0)<p class="pg-workspace-custody">Fondos separados: <strong>Bs {{ \App\Support\Bolivianos::format(array_sum($workspace['reserved_funds'])) }}</strong>. Se conservan fuera del capital operativo y la liquidación.</p>@endif

    <div class="pg-workspace-layout">

        <div class="pg-workspace-main">

            <section class="pg-workspace-card"><header><div><h3>Registrar comisión</h3><p>Completa y registra directamente desde tu caja.</p></div>@if (config('envios.enabled', false) && auth()->user()->can('envios.registrar')){{ $this->enviarBrasilAction }}@endif</header>

                <div class="pg-workspace-quick-grid">

                    @if ($workspace['type'] === 'BCP')

                        @include('cajas.quick-commission', ['kind' => 'ACH', 'path' => 'quickAch', 'title' => 'ACH / otros bancos'])

                    @endif

                    @include('cajas.quick-commission', ['kind' => 'FACTURAS', 'path' => 'quickServices', 'title' => 'Pago de servicios'])

                </div>

            </section>

            <section class="pg-workspace-card"><header><div><h3>Operaciones de esta sesión</h3><p>5 por página, de más recientes a más antiguas. Puedes consultar las anteriores con los botones de abajo.</p></div><div class="pg-workspace-buttons"><livewire:cajas.correction-alerts :access-id="$this->accessId" :initial-alerts="$workspace['correction_alerts']" :key="'cash-box-alerts-'.$this->accessId" /></div></header>

                <div class="pg-workspace-table-wrap"><table class="pg-workspace-table"><thead><tr><th>Operación</th><th>Importe</th><th>Comisión</th><th>Estado</th><th>Hora</th><th>Acciones</th></tr></thead><tbody>

                    @forelse ($workspace['operations'] as $operation)

                        <tr><td><strong>{{ $operation['label'] }}</strong><small>Operación #{{ $operation['id'] }}{{ $operation['movement'] ? ' · '.($operation['movement'] === 'DEPOSITO' ? 'Depósito' : 'Retiro') : '' }}</small>@if ($operation['source_label'] || $operation['destination_label'])<small>{{ $operation['source_label'] ?? '—' }} → {{ $operation['destination_label'] ?? '—' }}</small>@endif</td><td>Bs {{ \App\Support\Bolivianos::format($operation['amount_cents']) }}</td><td>Bs {{ \App\Support\Bolivianos::format($operation['fee_cents']) }}</td><td>{{ $operation['status'] === 'VIGENTE' ? 'Vigente' : $operation['status'] }}</td><td>{{ $operation['time'] }}</td><td>

                            @if ($operation['status'] === 'VIGENTE' && $operation['fee_type'] !== 'INTERNACIONAL' && in_array($operation['correction_status'], [null, 'DEVUELTA'], true))

                                <x-filament::icon-button size="sm" :color="$operation['correction_status'] === 'DEVUELTA' ? 'warning' : 'gray'" icon="heroicon-o-pencil-square" :label="$operation['correction_status'] === 'DEVUELTA' ? 'Completar solicitud' : 'Solicitar corrección'" :tooltip="$operation['correction_status'] === 'DEVUELTA' ? 'Completar solicitud' : 'Solicitar corrección'" wire:click="mountAction('solicitarCorreccion', { operation_id: {{ $operation['id'] }} })" wire:loading.attr="disabled" />

                            @elseif ($operation['correction_status'] === 'PENDIENTE')

                                <span class="pg-operation-review" role="status">Corrección pendiente</span>

                            @elseif ($operation['correction_status'] === 'RECHAZADA')

                                <small>Solicitud rechazada</small>

                            @else

                                <small>Sin acciones</small>

                            @endif

                        </td></tr>

                    @empty<tr><td colspan="6" class="pg-workspace-empty">Todavía no hay operaciones registradas.</td></tr>@endforelse

                </tbody></table></div>
                    <nav class="pg-workspace-pagination" aria-label="Páginas de operaciones">
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-left" wire:click="newerOperations" wire:loading.attr="disabled" wire:target="newerOperations,olderOperations" :disabled="!$workspace['operations_page']['has_previous']">Ver más recientes</x-filament::button>
                        <span>Página {{ $workspace['operations_page']['page'] }}</span>
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-right" icon-position="after" wire:click="olderOperations" wire:loading.attr="disabled" wire:target="newerOperations,olderOperations" :disabled="!$workspace['operations_page']['has_next']">Ver anteriores</x-filament::button>
                    </nav>


            </section>

        </div>

        <aside class="pg-workspace-sidebar">

            <section class="pg-workspace-card"><header><h3>Mis cajas abiertas</h3></header><div class="pg-workspace-own">

                @foreach ($workspace['own_boxes'] as $ownBox)

                    <article class="{{ $ownBox->id === $workspace['box_id'] ? 'is-current' : '' }}"><small>{{ $ownBox->type }}</small><strong>{{ $ownBox->name }}</strong>@if ($ownBox->id === $workspace['box_id'])<span>Estás aquí</span>@else<x-filament::button color="gray" size="sm" wire:click="resumeBox({{ $ownBox->id }})" wire:loading.attr="disabled" wire:target="resumeBox">Continuar</x-filament::button>@endif</article>

                @endforeach

            </div></section>

            <section class="pg-workspace-card"><header><h3>Acciones de la caja</h3></header><div class="pg-workspace-actions">
                {{ $this->iniciarCierreAction }}




                @if ($workspace['receipt_classification'] === 'FALTANTE')<p>El faltante de recepción se registró a la titular anterior. Puedes continuar operando.</p>
                @elseif ($workspace['receipt_classification'] === 'SOBRANTE')<p>Sobrante de apertura: <strong>Bs {{ \App\Support\Bolivianos::format($workspace['opening_surplus']) }}</strong>. Incluido una sola vez para liquidación; el capital no cambia.</p>
                @elseif ($workspace['receipt_classification'] === 'RECTIFICADO')<p>El sobrante de la apertura inicial fue rectificado por Administración. Se conservan los saldos y las comisiones reales.</p>
                @elseif ($workspace['has_observation'])<p>La diferencia de recepción quedó registrada sin atribuir una deuda a una persona desconocida.</p>@endif

            </div></section>

            <section class="pg-workspace-card"><header><h3>Información de la sesión</h3></header><div class="pg-workspace-information"><p>Abierta por <strong>{{ $workspace['cashier'] }}</strong></p><p>Apertura: <strong>{{ $workspace['opened_at'] }}</strong></p>
                <p>Tiempo abierta: <strong x-text="elapsed">{{ $workspace['elapsed_label'] }}</strong></p>
                @if ($workspace['session_timing']['status'] === 'PAUSED')
                    <p>Control horario desactivado durante las pruebas.</p>
                @elseif ($workspace['session_timing']['status'] === 'TURN_DISABLED')
                    <p>Control de horario desactivado para este turno.</p>
                @elseif ($workspace['session_timing']['status'] === 'DISABLED')
                    <p>Control de horario desactivado para esta caja.</p>
                @elseif ($workspace['session_timing']['status'] === 'PENDING')
                    <p role="status">Evaluación horaria pendiente. Puedes continuar operando; no cambia los saldos.</p>
                @elseif ($workspace['session_timing']['status'] === 'NO_SCHEDULE')
                    <p>Control activo, sin jornada vinculada: Administración debe revisar el turno asignado a esta cajera y su permiso de asistencia. Activar horarios no asigna turnos. No hay evaluación de retraso.</p>
                @else
                    <p>Jornada: <strong>{{ $workspace['session_timing']['scheduled_start'] }} — {{ $workspace['session_timing']['scheduled_end'] }}</strong></p>
                    @if ($workspace['session_timing']['continuity'] ?? false)
                        <p>Continuidad de jornada con la misma caja y el PIN original.</p>
                    @endif
                    @if ($workspace['session_timing']['status'] === 'NOT_EVALUATED')
                        <p>Retraso aún no evaluado por Control de horario.</p>
                    @else
                        <p role="status"><strong>{{ $workspace['session_timing']['delay_minutes'] > 0 ? 'Retraso registrado: '.$workspace['session_timing']['delay_minutes'].' min' : 'Sin retraso registrado' }}</strong></p>
                        <p>Referencia de jornada: {{ $workspace['session_timing']['reference_opened_at'] }}.
                            @if ($workspace['session_timing']['continuity'] ?? false)
                                Continuidad compartida de la jornada; no se ha abierto otra sesión.
                            @else
                                {{ $workspace['session_timing']['reference_is_this_box'] ? 'Primera apertura BCP en esta caja.' : 'Primera apertura BCP de la jornada; no es un retraso nuevo de esta caja.' }}
                            @endif
                        </p>
                        <p>Minutos según Control de horario, con su tolerancia aplicada. Justificaciones y compensaciones se gestionan en Personal.</p>
                    @endif
                @endif
                <p>Salir de esta vista mantiene tu caja abierta.</p></div></section>

        </aside>

    </div>

</div>

