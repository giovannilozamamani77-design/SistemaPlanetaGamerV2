<x-filament-panels::page>
<div class="pg-product-registration" x-data="{}"
    x-on:accessory-product-ready.window="$nextTick(() => requestAnimationFrame(() => $el.querySelector('#registration-code')?.focus()))">
    @if ($this->registeredProductId)<div class="pg-product-success" role="status">Accesorio #{{ $this->registeredProductId }} registrado. El formulario está listo para el siguiente.</div>@endif
    @if ($this->confirmationError)<p role="alert" class="pg-product-error">{{ $this->confirmationError }}</p>@endif
    <form wire:submit="reviewProduct">
        {{ $this->form }}
        @include('accesorios.product-pricing-summary', ['component' => $this])
    </form>
</div>
</x-filament-panels::page>
