@if ($this->context['family'] === 'BCP')
    <div class="pg-closing-counter-heading"><strong>Contadores BCP · {{ $this->counterReference['month'] }}</strong><span>Son cantidades de transacciones, no importes de dinero.</span></div>
    <div class="pg-closing-counter-grid">
        @foreach (['general' => ['Transacciones generales', 'heroicon-o-chart-bar'], 'fixed' => ['Transacciones fijas', 'heroicon-o-document-check']] as $key => [$label, $icon])
            <section class="pg-closing-counter-card">
                <h2><x-filament::icon :icon="$icon" />{{ $label }}</h2>
                <div class="pg-closing-counter-reference"><span>{{ $this->counterReference['counters'][$key]['monthly_reset'] ? 'Inicio del nuevo mes' : 'Última lectura válida' }}</span><strong>{{ $this->counterPreview['counters'][$key]['reference'] ?? 'Sin referencia' }}</strong></div>
                <label for="closing-counter-{{ $key }}">Contador acumulado al cierre</label>
                <x-filament::input.wrapper><x-filament::input :id="'closing-counter-'.$key" type="text" inputmode="numeric" autocomplete="off" placeholder="0" wire:model.live.debounce.150ms="readings.{{ $key }}" /></x-filament::input.wrapper>
                @error('readings.'.$key)<p class="pg-closing-error" role="alert">{{ $message }}</p>@enderror
                <div class="pg-closing-counter-delta" aria-live="polite"><span>Realizadas en este turno</span><strong>{{ $this->counterPreview['counters'][$key]['delta'] ?? '—' }}</strong></div>
                @if ($this->counterPreview['counters'][$key]['reference'] === null)
                    <p>Primera lectura: se tomará como referencia, sin atribuir transacciones anteriores.</p>
                @elseif ($this->counterPreview['counters'][$key]['error'] !== null && ($readings[$key] ?? '') !== '')
                    <p class="pg-closing-error">{{ $this->counterPreview['counters'][$key]['error'] }}</p>
                @endif
            </section>
        @endforeach
    </div>
@else
    <section class="pg-closing-counter-card pg-closing-no-counters"><x-filament::icon icon="heroicon-o-check-circle" /><h2>Esta caja no utiliza contadores BCP</h2><p>Las comisiones registradas durante la sesión se mostrarán en el resumen. No necesitas volver a ingresarlas.</p></section>
@endif
