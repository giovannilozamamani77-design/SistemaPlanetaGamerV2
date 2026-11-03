<x-filament-panels::page>
<div class="pg-product-registration pg-phone-registration">
@if($this->registeredId)<div class="pg-product-success" role="status">{{ $this->registeredQuantity }} {{ $this->registeredQuantity === 1 ? 'equipo registrado' : 'equipos registrados' }}. Listo para el siguiente ingreso.</div>@endif
<form wire:submit="reviewPhone">{{ $this->form }}
<div class="pg-product-footer"><x-filament::button type="submit" icon="heroicon-o-check-circle" wire:loading.attr="disabled">Revisar y registrar teléfono</x-filament::button><span wire:loading wire:target="reviewPhone,callMountedAction">Procesando…</span></div>
</form></div>
</x-filament-panels::page>
