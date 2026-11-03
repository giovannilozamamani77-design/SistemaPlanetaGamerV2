@if (!isset($this->context['shifts'][$period ?? '']))
    <p class="pg-closing-error" role="alert">Revisa el turno seleccionado antes de continuar.</p>
@elseif (!$this->preview['valid'] || !$this->counterPreview['valid'])
    <p class="pg-closing-error" role="alert">Revisa los saldos y los contadores antes de continuar.</p>
@else
<div class="pg-closing-summary-heading"><span>{{ $this->context['shifts'][$period]['label'] }} · {{ $this->context['shifts'][$period]['start'] }}–{{ $this->context['shifts'][$period]['end'] }}</span><span class="pg-closing-summary-badge">Vista previa</span></div>
<div class="pg-closing-summary-totals" aria-live="polite">
    <article class="pg-closing-summary-total"><x-filament::icon icon="heroicon-o-wallet" /><span>Total contado</span><strong>Bs {{ \App\Support\Bolivianos::format($this->preview['total_cents'] ?? 0) }}</strong></article>
    <article><x-filament::icon icon="heroicon-o-scale" /><span>Saldo esperado</span><strong>Bs {{ \App\Support\Bolivianos::format($this->preview['expected_cents']) }}</strong></article>
    <article><x-filament::icon icon="heroicon-o-calculator" /><span>Comisiones registradas</span><strong>Bs {{ \App\Support\Bolivianos::format($this->context['commission_total']) }}</strong></article>
    <article class="is-{{ $this->preview['kind'] }}"><x-filament::icon icon="heroicon-o-arrows-right-left" /><span>{{ match ($this->preview['kind']) { 'shortage' => 'Faltante', 'surplus' => 'Sobrante', 'balanced' => 'Sin diferencia', default => 'Importes inválidos' } }}</span><strong>{{ $this->preview['valid'] ? 'Bs '.\App\Support\Bolivianos::format($this->preview['difference_absolute_cents']) : 'Revisa los saldos' }}</strong></article>
</div>
<div class="pg-closing-summary-details">
    <section class="pg-closing-counter-card"><h2><x-filament::icon icon="heroicon-o-banknotes" />Saldos declarados</h2>
        <dl class="pg-closing-summary-balances">@foreach ($this->context['accounts'] as $code => $account)<div><dt><x-filament::icon :icon="\App\Filament\Support\Cajas\CashBoxComponentIcon::for($code, $account)" />{{ $account['name'] }}</dt><dd>Bs {{ \App\Support\Bolivianos::format($this->preview['component_cents'][$code]) }}</dd></div>@endforeach</dl>
        @include('arqueos.count-details', ['count' => $acceptedCashCount])
        <div class="pg-closing-summary-capital"><span>Capital operativo</span><strong>Bs {{ \App\Support\Bolivianos::format($this->context['operating_capital']) }}</strong></div>
    </section>
    <section class="pg-closing-counter-card"><h2><x-filament::icon icon="heroicon-o-clipboard-document-check" />Verificación del turno</h2>
        @if ($this->context['family'] === 'BCP')
            <dl class="pg-closing-summary-balances">@foreach (['general' => 'Transacciones generales', 'fixed' => 'Transacciones fijas'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ $this->counterPreview['counters'][$key]['delta'] ?? 'Primera referencia' }}</dd></div>@endforeach</dl>
        @endif
        @if ($this->preview['kind'] === 'shortage')<p class="pg-closing-summary-warning">Este faltante requiere confirmación de Administración al registrar el cierre.</p>
        @else<p>No hay faltante total. Las comisiones ya están incluidas en el saldo esperado.</p>@endif
        @if ($this->preview['kind'] === 'shortage')
            <label for="closing-reason">Motivo del faltante</label>
            <x-filament::input.wrapper><x-filament::input id="closing-reason" wire:model="closingReason" placeholder="Explica lo ocurrido para Administración" /></x-filament::input.wrapper>
        @endif
        @error('closing')<p role="alert" class="pg-closing-error">{{ $message }}</p>@enderror
        @if (!$this->financialAssessment['valid'])<p role="alert" class="pg-closing-error">{{ $this->financialAssessment['message'] }}</p>
        @elseif ($this->context['shifts'][$period]['liquidates'] && $this->financialAssessment['liquidation']['shortage_cents'] > 0)
            <p role="alert" class="pg-closing-summary-warning">Antes de finalizar debes reponer Bs {{ \App\Support\Bolivianos::format($this->financialAssessment['liquidation']['shortage_cents']) }} de capital real. La liquidación no puede finalizar por debajo del capital.</p>
        @endif
        <div class="pg-closing-summary-liquidation">
            <strong>{{ $this->context['shifts'][$period]['liquidates'] ? 'Liquidación requerida' : 'Sin liquidación' }}</strong>
            <p>{{ $this->context['shifts'][$period]['liquidates'] ? 'Este turno está configurado para liquidar antes de completar el cierre.' : 'Este turno permite finalizar sin liquidar, sujeto a las validaciones del cierre.' }}</p>
            @if ($this->context['shifts'][$period]['liquidates'])<x-filament::button wire:click="openLiquidationPreview" wire:loading.attr="disabled" icon="heroicon-o-qr-code">Realizar liquidación</x-filament::button>
            @else<x-filament::button wire:click="confirmClosing" wire:loading.attr="disabled" icon="heroicon-o-check">Confirmar cierre</x-filament::button>@endif
            @error('liquidation')<p role="alert" class="pg-closing-error">{{ $message }}</p>@enderror
        </div>
    </section>
</div>
@if ($this->preview['kind'] === 'shortage' || !$this->financialAssessment['valid'] || ($this->financialAssessment['liquidation']['shortage_cents'] ?? 0) > 0)
    @include('cajas.closing-restoration')
@endif

@endif
