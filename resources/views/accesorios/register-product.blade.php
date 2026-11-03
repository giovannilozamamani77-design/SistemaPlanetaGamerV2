<x-filament-panels::page>
<div class="pg-product-registration" x-on:accessory-product-ready.window="$nextTick(() => document.querySelector('[autofocus]')?.focus())">
    @if ($this->registeredProductId)<div class="pg-product-success" role="status">Accesorio #{{ $this->registeredProductId }} registrado. El formulario está listo para el siguiente.</div>@endif
    <form wire:submit="reviewProduct">
        {{ $this->form }}
        @include('accesorios.product-pricing-summary', ['component' => $this])
    </form>
</div>
</x-filament-panels::page>
