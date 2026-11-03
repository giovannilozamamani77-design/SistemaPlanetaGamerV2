<x-filament-panels::page>
    <div class="pg-card-inventory-summary">
        <div class="pg-card-inventory-stat pg-card-inventory-stat-cash">
            <span>Efectivo esperado en Tarjetas</span>
            <strong>{{ $summary['cash'] }}</strong>
            <small>{{ $summary['initialized'] ? 'Se actualiza al registrar compras; no pertenece a BCP ni a Tigo Money.' : 'Administración debe declarar el efectivo y las tarjetas reales una sola vez.' }}</small>
        </div>
        <div class="pg-card-inventory-stat"><span>Unidades registradas</span><strong>{{ $summary['units'] }}</strong><small>Conteo inicial más las entradas registradas.</small></div>
        <div class="pg-card-inventory-stat"><span>Productos con stock</span><strong>{{ $summary['stocked_products'] }}</strong><small>Separados por empresa y corte.</small></div>
    </div>
    @if (! $summary['initialized'])
        <x-filament::section icon="heroicon-o-clipboard-document-check" heading="Prepara el inventario real">
            <p class="text-sm">Agrega los productos y sus tres precios. Después, Administración registra el conteo inicial. Hasta entonces no se confirman compras ni entregas.</p>
        </x-filament::section>
    @endif
    @if ($priceSettings)
        <p class="text-sm text-gray-500">Precios de compra, venta normal y mayorista. Los cambios solo se aplican a operaciones nuevas; el historial conserva sus precios.</p>
        {{ $this->table }}
    @else
    <x-filament::tabs label="Empresa de tarjetas">
        @foreach (\App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES as $code => $label)
            <x-filament::tabs.item :active="$company === $code" wire:click="selectCompany('{{ $code }}')" wire:loading.attr="disabled">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
    <div class="pg-inventory-panel" data-company="{{ $company }}">
    <div class="pg-inventory-company" data-company="{{ $company }}">
        @include('tarjetas.company-logo', ['company' => $company, 'logoClass' => 'pg-inventory-company-logo'])
        <div><small>STOCK DISPONIBLE</small><h2>{{ \App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES[$company] ?? 'Entel' }}</h2></div>
    </div>
    {{ $this->table }}
    </div>
    @endif
</x-filament-panels::page>
