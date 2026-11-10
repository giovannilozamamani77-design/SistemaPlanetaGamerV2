<div class="pg-product-pricing-summary">
        <div class="pg-product-prices">
        @if ($component->data['cost_pending'] ?? false)
            <div><span>Compra por unidad</span><strong>Pendiente</strong></div>
            <div><span>Venta por unidad</span><strong>Bs {{ $component->data['sale_price'] ?? '' }}</strong></div>
            <div data-tone="gain"><span>Ganancia por unidad</span><strong>Pendiente</strong></div>
        @else
            <div><span>Compra por unidad</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($component->quote['cost_cents'] ?? 0) }}</strong></div>
            <div><span>Venta por unidad</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($component->quote['price_cents'] ?? 0) }}</strong></div>
            <div data-tone="gain"><span>Ganancia por unidad</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($component->quote['profit_cents'] ?? 0) }}</strong></div>
        @endif
        </div>
        @if ($component->quote && $component->quote['suggested_price_cents'] !== null)<p class="pg-product-hint">Sugerencia configurada: Bs {{ \App\Support\Bolivianos::formatCompact($component->quote['suggested_price_cents']) }} · Recargo {{ \App\Support\Bolivianos::formatCompact($component->quote['configured_markup_bps']) }} % sobre costo. <button type="button" wire:click="refreshQuote(true)">Usar sugerencia</button></p>@endif
        @if ($component->quoteError)<p class="pg-product-error" role="alert">{{ $component->quoteError }}</p>@endif
        <div class="pg-product-footer"><x-filament::button type="submit" icon="heroicon-o-check-circle" wire:loading.attr="disabled">Revisar y crear accesorio</x-filament::button><span wire:loading wire:target="reviewProduct,callMountedAction">Procesando registro…</span></div>
</div>
