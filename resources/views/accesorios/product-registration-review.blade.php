<div class="pg-product-review">
@if ($review)
    @if ($imageUrl)<img src="{{ $imageUrl }}" alt="Imagen que se guardará con el accesorio">@endif
    <h3>{{ $review['values']['name'] }}</h3><p>{{ $review['values']['code'] }} · {{ $review['category_name'] }}</p>
    @if ($review['values']['description'])<p>{{ $review['values']['description'] }}</p>@endif
    <dl><div><dt>Precio de compra</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($review['values']['unit_cost_cents']) }}</dd></div><div><dt>Precio de venta</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($review['values']['sale_price_cents']) }}</dd></div><div><dt>Stock inicial</dt><dd>{{ $review['values']['quantity'] }} unidades</dd></div><div><dt>Inversión inicial</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($review['total_cost_cents']) }}</dd></div><div><dt>Ganancia por unidad</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($review['profit_cents']) }}</dd></div><div><dt>Imagen</dt><dd>{{ $review['values']['image_hash'] ? 'Se guardará con el producto' : 'Sin imagen' }}</dd></div></dl>
@else <p>Revisa primero los datos del accesorio.</p>@endif
</div>
