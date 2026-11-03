<x-filament-panels::page>
    <div class="pg-liquidation-overview">
<section class="pg-liquidation-capital-card"><x-filament::icon icon="heroicon-o-qr-code" /><h2>Destinos configurados por caja</h2><p>Cada registro conserva la cuenta usada al liquidar. Administración configura los destinos desde la tuerca.</p></section>
        <section class="pg-liquidation-capital-card"><x-filament::icon icon="heroicon-o-scale" /><h2>La base es el capital operativo</h2><p>Se mantiene el capital de trabajo y se liquida únicamente el excedente disponible. Los fondos temporales modifican esa base.</p><strong>El historial conserva cada transferencia confirmada.</strong></section>
    </div>
    <section class="pg-liquidation-flow" aria-label="Flujo de liquidación"><div><x-filament::icon icon="heroicon-o-building-storefront" /><strong>Caja y cuenta de origen</strong><span>Configurada por Administración</span></div><x-filament::icon icon="heroicon-o-arrow-right" /><div><x-filament::icon icon="heroicon-o-banknotes" /><strong>Excedente disponible</strong><span>Conserva el capital operativo</span></div><x-filament::icon icon="heroicon-o-arrow-right" /><div><x-filament::icon icon="heroicon-o-qr-code" /><strong>Cuenta de destino</strong><span>Registro con cajera, fecha y turno</span></div></section>
    <section class="pg-liquidation-table">{{ $this->table }}</section>
</x-filament-panels::page>
