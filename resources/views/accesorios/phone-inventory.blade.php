<x-filament-panels::page>
<div class="pg-phone-summary" aria-label="Equipos disponibles">
    <span class="pg-tone-cyan"><x-filament::icon icon="heroicon-o-device-phone-mobile" />Disponibles <strong>{{ $this->summary['total'] }}</strong></span>
    <span class="pg-tone-green"><x-filament::icon icon="heroicon-o-sparkles" />Nuevos <strong>{{ $this->summary['new'] }}</strong></span>
    <span class="pg-tone-gold"><x-filament::icon icon="heroicon-o-arrow-path" />Usados <strong>{{ $this->summary['used'] }}</strong></span>
</div>
<div class="pg-accessory-inventory">{{ $this->table }}</div>
</x-filament-panels::page>
