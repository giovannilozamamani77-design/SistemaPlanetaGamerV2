<x-filament-panels::page>
<div class="pg-accessory-sale pg-accessory-sale-workspace" x-data="{
    barcode: '', pendingScans: [], scanning: false,
    focusScanner() { this.$refs.barcode?.focus(); },
    async submitScan() {
        const value = this.barcode.trim();
        if (!value) return;
        this.barcode = ''; this.pendingScans.push(value);
        if (this.scanning) return;
        this.scanning = true;
        try { while (this.pendingScans.length) { await this.$wire.scanCode(this.pendingScans.shift()); } }
        finally { this.scanning = false; }
    }
}" x-init="$nextTick(() => focusScanner())" x-on:accessory-scan-ready.window="if (!scanning) $nextTick(() => focusScanner())">
    @if ($this->lastSaleId)
        <div class="pg-sale-success" role="status"><span><x-filament::icon icon="heroicon-o-check-circle" /><strong>Venta #{{ $this->lastSaleId }} registrada</strong></span><div>
            @if ($this->canViewReceipts())
                <a href="{{ route('accesorios.recibo', ['sale'=>$this->lastSaleId]) }}" target="_blank" rel="noopener">Ver recibo</a>
                <a href="{{ route('accesorios.recibo', ['sale'=>$this->lastSaleId, 'print'=>1]) }}" target="_blank" rel="noopener">Imprimir</a>
                <a href="{{ route('accesorios.recibo-pdf', ['sale'=>$this->lastSaleId]) }}" target="_blank" rel="noopener">PDF</a>
            @endif
            @if (!$cart && !$phoneCart)<button type="button" wire:click="newSale">Nueva venta</button>@endif
        </div></div>
    @endif
    <div class="pg-sale-layout">
        <section class="pg-sale-catalogue pg-sale-panel" aria-label="Accesorios disponibles">
            <div class="pg-sale-panel-heading"><div><h3 class="pg-sale-icon-heading"><x-filament::icon icon="heroicon-o-shopping-bag" />Agregar productos</h3><p>Haz clic en un producto para agregar una unidad o usa el escáner.</p></div><button type="button" x-on:click="focusScanner()">@include('accesorios.icons.field-image', ['name' => 'code'])Usar escáner</button></div>
            <div class="pg-sale-mode-tabs" role="group" aria-label="Catálogo">
                <button type="button" wire:click="setCatalogue('ACCESSORY')" aria-pressed="{{ $this->catalogueMode === 'ACCESSORY' ? 'true' : 'false' }}"><x-filament::icon icon="heroicon-o-cube" />Accesorios</button>
                @if($this->canViewReceipts())<button type="button" wire:click="setCatalogue('PHONE')" aria-pressed="{{ $this->catalogueMode === 'PHONE' ? 'true' : 'false' }}"><x-filament::icon icon="heroicon-o-device-phone-mobile" />Teléfonos · IMEI</button>@endif
            </div>
            <div class="pg-sale-entry-tools">
                <form class="pg-sale-scanner" x-on:submit.prevent="submitScan()">
                    <label for="accessory-barcode">{{ $this->catalogueMode === 'PHONE' ? 'IMEI del teléfono' : 'Código de barras' }}</label><div>@include('accesorios.icons.field-image', ['name' => 'code'])<input id="accessory-barcode" x-ref="barcode" x-model="barcode" placeholder="Escanea o escribe código + Enter" maxlength="60" autocomplete="off" autocapitalize="off" spellcheck="false"><button type="submit">Agregar</button></div>
                    <small><span x-show="!scanning">Lector 1D · un escaneo agrega una unidad</span><span x-show="scanning" x-cloak>Agregando productos…</span></small>
                </form>
                <label class="pg-sale-keywords">Buscar por nombre o palabra clave<div><x-filament::icon icon="heroicon-o-magnifying-glass" /><input type="search" wire:model.live.debounce.350ms="search" placeholder="Cargador, audífonos, cable USB…" maxlength="80"></div><small>Coincide con nombre, categoría, descripción o código</small></label>
            </div>
            @error('code')<p role="alert" class="pg-sale-error">{{ $message }}</p>@enderror
            @if ($this->catalogueMode === 'ACCESSORY')
            <div class="pg-sale-catalogue-caption"><strong>Accesorios disponibles</strong><span>{{ $products->total() }} resultados</span></div>
            <div class="pg-sale-products">
                @forelse ($products as $product)
                    <button type="button" wire:click="addProduct({{ $product['id'] }})" wire:loading.attr="disabled" @disabled(!$product['sale_allowed']) aria-label="Agregar una unidad de {{ $product['name'] }}" title="Agregar al carrito: {{ $product['name'] }}" class="pg-sale-product pg-sale-click-product" wire:key="accessory-{{ $product['id'] }}" data-disabled="{{ $product['sale_allowed'] ? 'false' : 'true' }}">
                        @if ($product['image_url'])<img class="pg-sale-product-image" src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" loading="lazy">@endif
                        <h3>{{ $product['name'] }}</h3><span>Stock: {{ $product['stock'] }}</span>
                        <strong class="pg-sale-product-price">Bs {{ \App\Support\Bolivianos::formatCompact($product['price_cents']) }}</strong>
                        @if ($product['sale_allowed'])
                            <span class="pg-sale-add-hint" aria-hidden="true"><x-filament::icon icon="heroicon-o-plus-circle" /></span>
                        @else <p class="pg-sale-error">Revisar precio con Administración</p>@endif
                    </button>
                @empty <div class="pg-sale-empty"><x-filament::icon icon="heroicon-o-magnifying-glass" /><strong>No hay accesorios disponibles para esta búsqueda.</strong><span>Prueba otra palabra o revisa el inventario.</span></div>@endforelse
            </div>
            <div class="pg-sale-pagination">{{ $products->links() }}</div>
            @else
                @include('accesorios.phone-sale-catalogue')
            @endif
        </section>
        <aside class="pg-sale-aside" aria-label="Venta actual">
            <section class="pg-sale-cart">
                <div class="pg-sale-cart-heading"><div><span class="pg-sale-eyebrow">RESUMEN</span><h2 class="pg-sale-icon-heading"><x-filament::icon icon="heroicon-o-shopping-cart" />Tu carrito</h2></div><button type="button" wire:click="clearCart" wire:loading.attr="disabled" @disabled(!$cart && !$phoneCart) aria-label="Vaciar carrito" title="Vaciar carrito"><x-filament::icon icon="heroicon-o-trash" /></button></div>
                @error('cart')<p role="alert" class="pg-sale-error">{{ $message }}</p>@enderror
                @if ($cartWarning)<p role="alert" class="pg-sale-error">{{ $cartWarning }}</p>@endif
                <div class="pg-sale-cart-lines">
                    @if ($summary)
                        @foreach ($summary['lines'] as $line)
                            <div wire:key="cart-{{ ($line['kind'] ?? 'ACCESSORY') === 'PHONE' ? 'phone-'.$line['phone_unit_id'] : 'accessory-'.$line['product_id'] }}"><span>{{ $line['product_name'] }}<small>{{ $line['quantity'] }} × Bs {{ \App\Support\Bolivianos::formatCompact($line['unit_price_cents']) }}@if(($line['kind'] ?? '') === 'PHONE') · IMEI {{ $line['product_code'] }}@endif</small></span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($line['total_cents']) }}</strong><span class="pg-cart-quantity" role="group" aria-label="Cantidad de {{ $line['product_name'] }}"><button type="button" wire:click="{{ ($line['kind'] ?? 'ACCESSORY') === 'PHONE' ? 'removePhone('.$line['phone_unit_id'].')' : 'decreaseProduct('.$line['product_id'].')' }}" wire:loading.attr="disabled" aria-label="Reducir una unidad de {{ $line['product_name'] }}" title="Quitar una unidad">−</button><span>{{ $line['quantity'] }}</span><button type="button" wire:click="{{ ($line['kind'] ?? 'ACCESSORY') === 'PHONE' ? 'increasePhone('.$line['phone_unit_id'].')' : 'addProduct('.$line['product_id'].')' }}" wire:loading.attr="disabled" aria-label="Agregar una unidad de {{ $line['product_name'] }}" title="Agregar una unidad">+</button></span><button type="button" wire:click="{{ ($line['kind'] ?? 'ACCESSORY') === 'PHONE' ? 'removePhone('.$line['phone_unit_id'].')' : 'removeProduct('.$line['product_id'].')' }}" aria-label="Quitar {{ $line['product_name'] }}" title="Quitar del carrito" wire:loading.attr="disabled"><x-filament::icon icon="heroicon-o-trash" /></button></div>
                        @endforeach
                    @elseif ($cart || $phoneCart)
                        @foreach ($cart as $line)<div><span>Accesorio #{{ $line['product_id'] }}</span><button type="button" wire:click="removeProduct({{ $line['product_id'] }})" aria-label="Quitar accesorio del carrito" title="Quitar y volver a agregar"><x-filament::icon icon="heroicon-o-trash" /></button></div>@endforeach
                        @foreach ($phoneCart as $line)<div><span>Teléfono #{{ $line['unit_id'] }}</span><button type="button" wire:click="removePhone({{ $line['unit_id'] }})" aria-label="Quitar teléfono del carrito" title="Quitar y volver a seleccionar"><x-filament::icon icon="heroicon-o-trash" /></button></div>@endforeach
                    @else <div class="pg-sale-empty-cart"><x-filament::icon icon="heroicon-o-shopping-cart" /><p>Tu carrito está vacío</p><small>Escanea un producto o elígelo del catálogo.</small></div>@endif
                </div>
                <div class="pg-sale-total"><span>Total a cobrar</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($summary['total_cents'] ?? 0) }}</strong><span>Pago en efectivo · Sin descuento</span><button type="button" wire:click="reviewSale" wire:loading.attr="disabled" x-bind:disabled="scanning || {{ $summary ? 'false' : 'true' }}" @disabled(!$summary)><x-filament::icon icon="heroicon-o-check-circle" />Finalizar compra</button><small>Revisa productos y cantidades antes de confirmar.</small></div>
            </section>
            <section class="pg-sale-cash-note"><x-filament::icon icon="heroicon-o-wallet" /><div><span>Efectivo registrado</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($cashCents) }}</strong><small>Fondo propio de Accesorios</small></div></section>
        </aside>
    </div>
</div>
</x-filament-panels::page>
