<x-filament-panels::page>
    <section class="pg-closing-start" x-data="{}" x-on:pageshow.window="if ($event.persisted) window.location.reload()">
        <header class="pg-closing-start-header">
            <div><p>Cerrar caja · {{ $this->context['box'] }}</p><h1>{{ match ($step) { 1 => 'Selecciona el turno', 2 => 'Saldos finales', 3 => 'Operaciones del turno', default => 'Resumen del cierre' } }}</h1><span>{{ match ($step) { 1 => 'Elige el turno que estás cerrando. Tu caja sigue abierta.', 2 => 'Ingresa los saldos reales. Vacío equivale a cero.', 3 => 'Registra las lecturas acumuladas al finalizar la atención.', default => 'Revisa los datos antes de confirmar. Tu caja sigue abierta.' } }}</span></div>
            <x-filament::button color="danger" icon="heroicon-o-x-mark" wire:click="cancelClosing" wire:loading.attr="disabled" wire:target="cancelClosing" title="Descarta estos datos y vuelve a registrar comisiones en tu caja">Cancelar cierre</x-filament::button>
        </header>
        <ol class="pg-closing-start-progress" aria-label="Pasos del cierre">
            @foreach ([1 => ['Turno', 'heroicon-o-clock'], 2 => ['Saldos', 'heroicon-o-wallet'], 3 => ['Operaciones', 'heroicon-o-chart-bar'], 4 => ['Resumen', 'heroicon-o-clipboard-document-check']] as $number => [$label, $icon])
                <li class="{{ $number < $step ? 'is-complete' : '' }}" aria-current="{{ $step === $number ? 'step' : 'false' }}">
                    <span class="pg-closing-step-icon"><x-filament::icon :icon="$number < $step ? 'heroicon-o-check' : $icon" /></span>
                    <span class="pg-closing-step-label"><small>Paso {{ $number }}</small><strong>{{ $label }}</strong></span>
                </li>
            @endforeach
        </ol>
        @if ($step === 1)
            <fieldset class="pg-closing-start-shifts"><legend class="sr-only">Turno de cierre</legend>
                @foreach ($this->context['shifts'] as $key => $shift)
                    <label class="pg-closing-start-shift {{ $period === $key ? 'is-selected' : '' }}">
                        <input type="radio" wire:model.live="period" value="{{ $key }}" name="closing-period">
                        <x-filament::icon :icon="$key === 'manana' ? 'heroicon-o-sun' : 'heroicon-o-moon'" />
                        <strong>{{ $shift['label'] }}</strong><small>{{ $shift['liquidates'] ? 'Con liquidación' : 'Sin liquidación' }}</small><span>{{ $shift['start'] }}–{{ $shift['end'] }}</span>
                    </label>
                @endforeach
            </fieldset>
            @error('period')<p role="alert">{{ $message }}</p>@enderror
            <div class="pg-closing-start-footer"><p class="pg-closing-start-note" aria-live="polite">{{ isset($this->context['shifts'][$period ?? '']) ? 'Turno seleccionado: '.$this->context['shifts'][$period]['label'].'.' : 'Selecciona mañana o tarde para este cierre.' }}</p>
                <x-filament::button size="lg" icon="heroicon-o-arrow-right" icon-position="after" wire:click="nextStep" wire:loading.attr="disabled" :disabled="!isset($this->context['shifts'][$period ?? ''])">Siguiente</x-filament::button>
            </div>
        @elseif ($step === 2)
            <div class="pg-closing-balances-layout">
                <div class="pg-closing-balances-fields">
                    @foreach ($this->context['accounts'] as $code => $account)
                        <div class="pg-closing-balance-field {{ $code === 'efectivo' ? 'is-cash' : '' }}">
                            <label for="closing-balance-{{ $code }}"><x-filament::icon :icon="\App\Filament\Support\Cajas\CashBoxComponentIcon::for($code, $account)" />{{ $code === 'efectivo' ? 'Efectivo final' : $account['name'].' final' }}</label>
                            <div class="pg-closing-cash-input-row">
                                <x-filament::input.wrapper prefix="Bs"><x-filament::input :id="'closing-balance-'.$code" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" :aria-label="$code === 'efectivo' ? 'Efectivo final' : $account['name'].' final'" wire:model.live.debounce.150ms="balances.{{ $code }}" /></x-filament::input.wrapper>
                                @if ($code === 'efectivo')<x-filament::button size="sm" icon="heroicon-o-calculator" wire:click="openCashCount" wire:loading.attr="disabled" wire:target="openCashCount">Realizar arqueo</x-filament::button>@endif
                            </div>
                            @if ($code === 'efectivo')<small class="pg-closing-count-status">{{ $acceptedCashCount !== null ? 'Con arqueo. Si cambias el efectivo, se descarta este detalle.' : 'Sin arqueo · Opcional, puedes ingresar el efectivo directamente.' }}</small>@endif
                        </div>
                    @endforeach
                </div>
                <section class="pg-closing-balances-preview" aria-label="Total y diferencia" aria-live="polite">
                    @if (!$this->preview['valid'])
                        <p role="alert">Revisa los importes: usa números no negativos con hasta dos decimales.</p>
                    @else
                        <div class="pg-closing-balances-total"><span>Total contado</span><strong>Bs {{ \App\Support\Bolivianos::format($this->preview['total_cents']) }}</strong></div>
                        <div class="pg-closing-balances-result"><div><span>Saldo esperado</span><strong>Bs {{ \App\Support\Bolivianos::format($this->preview['expected_cents']) }}</strong></div>
                            <div class="is-{{ $this->preview['kind'] }}"><span>{{ match($this->preview['kind']) { 'shortage' => 'Faltante', 'surplus' => 'Sobrante', default => 'Sin diferencia' } }}</span><strong>Bs {{ \App\Support\Bolivianos::format($this->preview['difference_absolute_cents']) }}</strong></div>
                        </div>
                        <p>Las comisiones y los movimientos registrados ya están incluidos en el saldo esperado.</p>
                    @endif
                </section>
            </div>
            @error('balances')<p role="alert" class="pg-closing-error">{{ $message }}</p>@enderror
            <div class="pg-closing-start-footer"><x-filament::button color="gray" size="lg" icon="heroicon-o-arrow-left" wire:click="previousStep">Atrás</x-filament::button><x-filament::button size="lg" icon="heroicon-o-arrow-right" icon-position="after" wire:click="nextStep" wire:loading.attr="disabled">Siguiente</x-filament::button></div>
        @elseif ($step === 3)
            @include('cajas.closing-operations')
            @error('readings')<p role="alert" class="pg-closing-error">{{ $message }}</p>@enderror
            <div class="pg-closing-start-footer"><x-filament::button color="gray" size="lg" icon="heroicon-o-arrow-left" wire:click="previousStep">Atrás</x-filament::button><x-filament::button size="lg" icon="heroicon-o-arrow-right" icon-position="after" wire:click="nextStep" wire:loading.attr="disabled">Ver resumen</x-filament::button></div>
        @else
            @include('cajas.closing-summary')
            <div class="pg-closing-start-footer"><x-filament::button color="gray" size="lg" icon="heroicon-o-arrow-left" wire:click="previousStep">Atrás</x-filament::button><p class="pg-closing-start-note">Revisa los datos. Solo el botón de confirmación registra el cierre definitivo.</p></div>
        @endif
        @include('arqueos.cash-count-modal')
        @include('liquidaciones.closing-preview-modal')
    </section>
</x-filament-panels::page>
