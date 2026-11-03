<x-filament-panels::page>
<div class="pg-accessory-pricing">
    <x-filament-actions::modals />
    <p class="pg-pricing-note">Un solo cambio para todos los precios en USD de Accesorios y Teléfonos: <strong>API + diferencia</strong>.</p>
    <form wire:submit="saveDollar">
        <div class="pg-pricing-summary pg-pricing-exchange-flow" wire:init="loadReference" wire:poll.1800s.visible="loadReference">
            <section class="pg-pricing-card" data-tone="api"><span>Valor de la API</span><strong>{{ $quote ? 'Bs '.\App\Domain\Accesorios\Data\PricingInput::rateText($quote->rate_micros) : 'Consultando API…' }}</strong><p><a href="https://paralelo.bo" target="_blank" rel="noopener noreferrer">paralelo.bo · Referencia P2P</a></p><p>{{ $quote ? $quote->source_at->timezone('America/La_Paz')->format('d/m/Y H:i').' · Bolivia' : 'Todavía no hay una lectura válida.' }}</p><small wire:loading wire:target="loadReference">Consultando…</small></section>
            <section class="pg-pricing-card" data-tone="adjustment">{{ $this->form }}<p>Se suma a cada dólar. Se guarda una sola vez para todo el módulo.</p><x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled" :disabled="! $dollarChanged">Guardar diferencia</x-filament::button></section>
            <section class="pg-pricing-card" data-tone="final"><span>{{ $dollarError ? 'Revisa la diferencia' : ($dollarChanged ? 'Vista previa del cambio global' : 'Cambio global aplicado') }}</span><strong>{{ $dollarError ? 'Corrige la diferencia' : ($effectiveRate ? 'Bs '.\App\Domain\Accesorios\Data\PricingInput::rateText($effectiveRate).' / USD' : 'Esperando una lectura válida') }}</strong><p>{{ $dollarError ? 'El cambio guardado se mantiene sin modificaciones.' : ($dollarChanged ? 'Pulsa Guardar diferencia para aplicarlo.' : 'Este cambio se utiliza automáticamente al registrar costos en USD.') }}</p></section>
        </div>
    </form>
    @if($dollarError)<p class="pg-pricing-warning" role="alert">{{ $dollarError }}</p>@endif
    @if($this->referenceWarning)<p class="pg-pricing-warning" role="status">{{ $this->referenceWarning }}</p>@endif
    @if($quote && !$quote->source_at->timezone('America/La_Paz')->isToday())<p class="pg-pricing-warning" role="status">La lectura es de otra fecha. Actualiza el dólar antes de registrar costos en USD.</p>@endif
    <p class="pg-pricing-note">La API se consulta al abrir y cada 30 minutos. Puedes usar Actualizar dólar cuando lo necesites. Los costos y ventas ya registrados se conservan.</p>
    <details class="pg-pricing-optional" wire:ignore.self><summary>Precios de venta y porcentajes</summary>
        <section class="pg-pricing-rules" aria-label="Reglas por tipo">@foreach(\App\Domain\Accesorios\Data\PricingInput::KINDS as $kind => $label)<div><span>{{ $label }}</span><strong>{{ $settings['rules'][$kind]['mode'] === 'MANUAL' ? 'Precio manual' : \App\Support\Bolivianos::formatCompact($settings['rules'][$kind]['markup_bps']).' % sobre costo' }}</strong></div>@endforeach</section>
        <p class="pg-pricing-note">Actualización de precios automáticos: {{ ($settings['rules']['NEW_PHONE']['auto_update'] ?? false) ? 'activada' : 'pausada' }}. Los precios manuales quedan fijos.</p>
        <x-filament::button type="button" icon="heroicon-o-adjustments-horizontal" wire:click="mountAction('configure')" x-bind:disabled="$wire.mountedActions.length > 0">Editar porcentajes</x-filament::button>
    </details>
    <details class="pg-pricing-optional" wire:ignore.self><summary>Historial de cambios</summary>{{ $this->table }}</details>
</div>
</x-filament-panels::page>
