<x-filament-panels::page>
    @if (! $cardSessionStatus['is_owner'])
        @include('tarjetas.store-welcome')
    @endif
    @if ($cardSessionStatus['is_owner'])
    <div @class(['pg-sale-workspace pg-sale-compact', 'pg-sale-wholesale' => ($data['mode'] ?? 'NORMAL') === 'WHOLESALE'])>
        <div class="pg-quick-sale-layout">
            <section class="pg-card-sale-catalog" aria-label="Seleccionar tarjetas">
                <div class="pg-sale-select-header"><div><small>REGISTRO RÁPIDO</small><h2>1. Selecciona las tarjetas vendidas</h2></div>
                    <div class="pg-sale-select-actions">
                        @include('tarjetas.sale-history-button')
                    <div class="pg-sale-mode" role="group" aria-label="Tipo de precio">
                        <button type="button" wire:click="chooseMode('NORMAL')" @class(['is-active' => ($data['mode'] ?? 'NORMAL') === 'NORMAL']) aria-pressed="{{ ($data['mode'] ?? 'NORMAL') === 'NORMAL' ? 'true' : 'false' }}">Por unidad</button>
                        <button type="button" wire:click="chooseMode('WHOLESALE')" @class(['is-active' => ($data['mode'] ?? 'NORMAL') === 'WHOLESALE']) aria-pressed="{{ ($data['mode'] ?? 'NORMAL') === 'WHOLESALE' ? 'true' : 'false' }}">Por mayor</button>
                    </div>
                    </div>
                </div>
                @if (($data['mode'] ?? 'NORMAL') === 'WHOLESALE')
                    <section class="pg-recharge-compact" aria-label="Recargas mayoristas Entel">
                        <header>@include('tarjetas.company-logo', ['company' => 'ENTEL', 'logoClass' => 'pg-sale-company-logo'])<div><small>VENTA DIGITAL</small><h2>Recargas mayoristas Entel</h2></div></header>
                        @if (! in_array($recharge['kind'] ?? null, ['CREDIT', 'PACKAGES'], true))
                            <div class="pg-recharge-choices" role="group" aria-label="Elige la recarga mayorista">
                                <button type="button" wire:click="chooseRechargeKind('PACKAGES')" class="pg-recharge-choice" data-kind="PACKAGES"><x-filament::icon icon="heroicon-o-cube" class="h-6 w-6" /><span>Paquetes mayoristas</span></button>
                                <button type="button" wire:click="chooseRechargeKind('CREDIT')" class="pg-recharge-choice" data-kind="CREDIT"><x-filament::icon icon="heroicon-o-device-phone-mobile" class="h-6 w-6" /><span>Crédito mayorista</span></button>
                            </div>
                        @else
                        <form wire:submit="registerRecharge">
                            <div class="pg-recharge-selected"><strong>{{ $recharge['kind'] === 'PACKAGES' ? 'Paquetes mayoristas' : 'Crédito mayorista' }}</strong><button type="button" wire:click="clearRecharge">← Cambiar servicio</button></div>
                            <div class="pg-recharge-row">
                                <label for="recharge-amount">Monto recargado<div class="pg-recharge-amount"><span>Bs</span><input id="recharge-amount" type="text" inputmode="decimal" placeholder="Mínimo 100" wire:model.live.debounce.350ms="recharge.amount" /></div></label>
                                <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled" wire:target="registerRecharge" :disabled="! $cardSessionStatus['is_owner']">Registrar recarga</x-filament::button>
                            </div>
                            <div class="pg-recharge-note"><span>{{ $rechargeAvailability }}</span>@if ($rechargePreview)<span>Cliente recibe Bs {{ \App\Support\Bolivianos::formatCompact($rechargePreview['customer_total_cents']) }}</span>@endif</div>
                            <label class="pg-recharge-confirm"><input type="checkbox" wire:model="recharge.external_confirmed" /> Verifiqué la recarga en Entel</label>
                            @foreach (['kind', 'amount', 'external_confirmed'] as $field) @error('recharge.'.$field)<p class="pg-sale-error">{{ $message }}</p>@enderror @endforeach
                            <details class="pg-recharge-help"><summary>Ayuda y bonificación</summary><p>Mínimo Bs 100. Registra después de verificar el SMS de éxito o la disminución exacta en Entel. Si falla, devuelve el dinero. Si hay una diferencia, no repitas: consulta a Administración. La bonificación la da Entel y no es ingreso adicional de tienda.</p><button type="button" wire:click="clearRecharge">Limpiar sin registrar</button></details>
                        </form>
                        @endif
                    </section>
                @endif
                @if (($data['mode'] ?? 'NORMAL') === 'WHOLESALE' && $groups->isEmpty())
                    <p class="pg-sale-help">No hay cortes disponibles para venta por mayor. Consulta a Administración.</p>
                @endif
                @if (($data['mode'] ?? 'NORMAL') === 'NORMAL' && $groups->isEmpty())
                    <p class="pg-sale-help">No hay tarjetas con stock disponible. Recibe tarjetas o registra una compra.</p>
                @endif
                <div class="pg-sale-company-list">
                @foreach (\App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES as $company => $label)
                    @continue(empty($groups[$company]))
                    <section class="pg-sale-company" data-company="{{ $company }}" wire:key="sale-company-{{ $company }}">
                        <header>@include('tarjetas.company-logo', ['company' => $company, 'logoClass' => 'pg-sale-company-logo'])<div><h3>{{ $label }}</h3><small>{{ ($groups[$company] ?? collect())->sum('quantity') }} unidades disponibles</small></div></header>
                        <div class="pg-sale-cuts">
                        @forelse (($groups[$company] ?? []) as $id => $product)
                            @php
                                $key = 'product_'.$id;
                                $ineligible = ($data['mode'] ?? 'NORMAL') === 'WHOLESALE' && ! $product['wholesale_enabled'];
                                $unavailable = $product['quantity'] === 0 || $ineligible;
                                $price = $product[($data['mode'] ?? 'NORMAL') === 'WHOLESALE' ? 'wholesale_price_cents' : 'normal_price_cents'];
                            @endphp
                            <article @class(['pg-sale-cut', 'is-unavailable' => $unavailable]) wire:key="sale-cut-{{ $id }}">
                                <div class="pg-sale-cut-title"><span class="pg-sale-cut-brand">@include('tarjetas.company-logo', ['company' => $company, 'logoClass' => 'pg-sale-cut-logo']) Corte</span><strong>{{ $product['cut_label'] }}</strong></div>
                                <div class="pg-sale-cut-meta"><span>{{ $ineligible ? 'No habilitado para mayorista' : ($product['quantity'] ? 'Stock: '.$product['quantity'] : 'Agotado') }}</span><span>Venta: Bs {{ \App\Support\Bolivianos::formatCompact($price) }}</span></div>
                                <div class="pg-sale-counter">
                                    <button type="button" wire:click="adjustQuantity({{ $id }}, -1)" aria-label="Quitar una unidad de {{ $product['label'] }}" @disabled(! ($quantities[$key] ?? 0))>−</button>
                                    <input type="text" data-sale-quantity placeholder="0" inputmode="numeric" autocomplete="off" wire:keydown.enter.prevent="registerQuickSale({{ $id }}, $event.target.value, {{ \Illuminate\Support\Js::from($data['request_key'] ?? '') }})" wire:model.live.debounce.350ms="quantities.{{ $key }}" aria-label="Cantidad de {{ $product['label'] }}" @disabled($unavailable) wire:loading.attr="disabled" wire:target="registerSale,registerQuickSale" />
                                    <button type="button" wire:click="adjustQuantity({{ $id }}, 1)" aria-label="Agregar una unidad de {{ $product['label'] }}" @disabled($unavailable || (int) ($quantities[$key] ?? 0) >= $product['quantity'])>+</button>
                                </div>
                                @error('quantities.'.$key)<p class="pg-sale-error">{{ $message }}</p>@enderror
                                @error('data.lines.'.$key.'.quantity')<p class="pg-sale-error">{{ $message }}</p>@enderror
                                @error('data.lines.'.$key.'.product_id')<p class="pg-sale-error">{{ $message }}</p>@enderror
                            </article>
                        @empty
                            <p class="pg-sale-help">Sin cortes activos registrados.</p>
                        @endforelse
                        </div>
                    </section>
                @endforeach
                </div>
            </section>
            <form wire:submit="registerSale" class="pg-quick-sale-cart" aria-label="Venta actual">
                <header><div><small>2. REVISA Y COBRA</small><h2>{{ $preview['total_quantity'] ?? 0 }} unidades</h2></div><button type="button" wire:click="clearCart" class="pg-sale-clear" aria-label="Vaciar venta actual" title="Vaciar venta actual"><x-filament::icon icon="heroicon-o-trash" class="h-5 w-5" /></button></header>
                <div class="pg-sale-cash"><span>Efectivo esperado</span><strong>{{ $summary['cash_cents'] === null ? 'Sin conteo inicial' : 'Bs '.\App\Support\Bolivianos::formatCompact($summary['cash_cents']) }}</strong><small>Ventas en efectivo incluidas · Atiende: {{ auth()->user()->name }}</small></div>
                <div class="pg-card-sale-cart-lines">
                    @forelse ($cart as $key => $item)
                        <div class="pg-sale-cart-line" wire:key="sale-line-{{ $key }}">
                            <strong>{{ $item['product']['label'] }}</strong><span>{{ $item['line']['quantity'] }} unidades</span>
                            <label for="sale-price-{{ $key }}">Precio aplicado por unidad (Bs)</label>
                            <input id="sale-price-{{ $key }}" type="text" inputmode="decimal" wire:model.live.debounce.350ms="data.lines.{{ $key }}.unit_price" wire:loading.attr="disabled" wire:target="registerSale,registerQuickSale" />
                            <strong>{{ $item['preview'] ? 'Bs '.\App\Support\Bolivianos::formatCompact($item['preview']['total_cents']) : 'Revisa cantidad y precio' }}</strong>
                            @error('data.lines.'.$key.'.quantity')<p class="pg-sale-error">{{ $message }}</p>@enderror
                            @error('data.lines.'.$key.'.product_id')<p class="pg-sale-error">{{ $message }}</p>@enderror
                            @error('data.lines.'.$key.'.unit_price')<p class="pg-sale-error">{{ $message }}</p>@enderror
                        </div>
                    @empty
                        <div class="pg-sale-empty"><x-filament::icon icon="heroicon-o-cursor-arrow-rays" class="h-8 w-8" /><p>Tu venta está vacía. Agrega tarjetas con + o escribe una cantidad.</p></div>
                    @endforelse
                </div>
                <div class="pg-sale-payment"><h3>Método de pago</h3><span><x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5" /> Efectivo</span></div>
                <footer><span>Total a cobrar</span><strong class="pg-card-sale-total">Bs {{ \App\Support\Bolivianos::formatCompact($preview['total_cents'] ?? 0) }}</strong><span class="sr-only">{{ $preview ? 'Total a cobrar: Bs '.\App\Support\Bolivianos::formatCompact($preview['total_cents']) : '' }}</span>
                    @error('data.lines')<p class="pg-sale-error">{{ $message }}</p>@enderror
                    @error('data.mode')<p class="pg-sale-error">{{ $message }}</p>@enderror
                    @error('data.payment_method')<p class="pg-sale-error">{{ $message }}</p>@enderror
                    @if (! $summary['initialized'])<p class="pg-sale-error">Registra el inventario inicial antes de vender.</p>@endif
                    <x-filament::button type="submit" size="lg" icon="heroicon-o-check-circle" wire:loading.attr="disabled" wire:target="registerSale,registerQuickSale" :disabled="! $summary['initialized'] || ! $preview || ! $cardSessionStatus['is_owner']">Registrar venta</x-filament::button>
                </footer>
            </form>
        </div>
    </div>
    @endif
    @php($this->hasActionsModalRendered = true)
    <x-filament::modal id="card-sale-history" data-sale-history-dialog width="7xl" heading="Historial de ventas" :sticky-header="true">
        @include('tarjetas.sale-turn-summary')
  {{ $this->table }}
    </x-filament::modal>
    @php($this->hasActionsModalRendered = false)
    <x-filament-actions::modals />
</x-filament-panels::page>
