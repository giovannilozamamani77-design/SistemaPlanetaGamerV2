<div class="pg-sale-catalogue-caption"><strong class="pg-sale-icon-heading"><x-filament::icon icon="heroicon-o-device-phone-mobile" />Teléfonos disponibles</strong><span>{{ $phones->total() }} opciones · Reserva de 5 minutos</span></div>
<div class="pg-sale-products">
@forelse($phones as $phone)
<button type="button" wire:click="addPhone({{ $phone['id'] }})" wire:loading.attr="disabled" @disabled(!$phone['sale_allowed']) aria-label="Agregar {{ $phone['name'] }} al carrito" title="Agregar al carrito: {{ $phone['name'] }} · {{ $phone['variant'] }} · {{ $phone['condition'] }} · {{ $phone['color'] }}" class="pg-sale-product pg-sale-click-product" wire:key="phone-{{ $phone['id'] }}">
@if($phone['image_url'])<img class="pg-sale-product-image" src="{{ $phone['image_url'] }}" alt="{{ $phone['name'] }}" loading="lazy">@endif
<h3>{{ $phone['name'] }}</h3><span>{{ $phone['variant'] }} · {{ $phone['condition'] }} · {{ $phone['color'] }}</span>
<span>Stock: {{ $phone['stock'] }}</span>
<strong class="pg-sale-product-price">Bs {{ \App\Support\Bolivianos::formatCompact($phone['price_cents']) }}</strong>
@if($phone['sale_allowed'])<span class="pg-sale-add-hint" aria-hidden="true"><x-filament::icon icon="heroicon-o-plus-circle" /></span>@else<p class="pg-sale-error">Revisar precio con Administración</p>@endif
</button>
@empty<div class="pg-sale-empty">No hay teléfonos disponibles para esta búsqueda.</div>@endforelse
</div><div class="pg-sale-pagination">{{ $phones->links() }}</div>
