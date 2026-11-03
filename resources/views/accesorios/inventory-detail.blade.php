<div class="pg-pricing-card">
    <h3>{{ $product->name }} · {{ $product->code }}</h3>
    <p>Stock: <strong>{{ $product->quantity }}</strong> · Costo promedio: <strong>Bs {{ $average }}</strong> · Inversión: <strong>Bs {{ \App\Support\Bolivianos::formatCompact($product->stock_value_cents) }}</strong></p>
    <p>Precio confirmado: <strong>Bs {{ \App\Support\Bolivianos::formatCompact($product->sale_price_cents) }}</strong></p>
    @if ($suggestion)
        <p>Sugerencia según configuración: <strong>Bs {{ \App\Support\Bolivianos::formatCompact($suggestion['price_cents']) }}</strong> · Revisión {{ $suggestion['settings_revision'] }}</p>
    @endif
    @if ($warning)<p role="alert" class="text-danger-600">{{ $warning }}</p>@endif
    <p>El promedio muestra hasta seis decimales; el valor del inventario conserva sus centavos exactos. Para precios se usa el centavo superior del costo unitario, sin modificar la inversión.</p>
    @livewire('accesorios.stock-entry-history', ['productId' => $product->id], key('stock-history-'.$product->id))
</div>
