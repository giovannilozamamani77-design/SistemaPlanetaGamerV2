<x-filament-panels::page>
    <p>{{ $this->isOpeningCount() ? 'Cuenta las tarjetas y el efectivo que recibes.' : 'Cuenta las tarjetas y el efectivo que dejas al terminar el turno.' }} Los saldos digitales Entel se administran por separado.</p>
    <form wire:submit="confirmCount" class="space-y-4">
        {{ $this->form }}
        <div class="flex gap-3">
            <x-filament::button type="submit" :color="$this->isOpeningCount() ? 'success' : 'danger'" wire:loading.attr="disabled" wire:target="confirmCount">{{ $this->isOpeningCount() ? 'Confirmar apertura' : 'Confirmar cierre' }}</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="cancelCount">{{ $this->isOpeningCount() ? 'Cancelar apertura' : 'Cancelar cierre y volver a ventas' }}</x-filament::button>
        </div>
    </form>
    @include('arqueos.cash-count-modal')
</x-filament-panels::page>
