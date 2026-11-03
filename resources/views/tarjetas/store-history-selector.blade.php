<x-filament-panels::page>
    <div class="pg-store-history-options" role="group" aria-label="Selecciona el historial">
        <button type="button" wire:click="selectHistory('CLOSE')" wire:loading.attr="disabled" class="pg-store-history-choice {{ $historyKind === 'CLOSE' ? 'is-active' : '' }}" data-kind="CLOSE" aria-pressed="{{ $historyKind === 'CLOSE' ? 'true' : 'false' }}" aria-controls="store-history-results">
            <span class="pg-store-history-icon"><x-heroicon-o-lock-closed aria-hidden="true" /></span>
            <span><strong>Historial de cierres</strong><small>Lo que cada cajera dejó al terminar su turno.</small></span>
            <x-heroicon-o-chevron-right class="pg-store-history-arrow" aria-hidden="true" />
        </button>
        <button type="button" wire:click="selectHistory('OPEN')" wire:loading.attr="disabled" class="pg-store-history-choice {{ $historyKind === 'OPEN' ? 'is-active' : '' }}" data-kind="OPEN" aria-pressed="{{ $historyKind === 'OPEN' ? 'true' : 'false' }}" aria-controls="store-history-results">
            <span class="pg-store-history-icon"><x-heroicon-o-lock-open aria-hidden="true" /></span>
            <span><strong>Historial de aperturas</strong><small>Lo que cada cajera recibió al comenzar su turno.</small></span>
            <x-heroicon-o-chevron-right class="pg-store-history-arrow" aria-hidden="true" />
        </button>
    </div>
    <section id="store-history-results" aria-label="{{ $historyKind === 'CLOSE' ? 'Registros de cierres' : 'Registros de aperturas' }}">
        {{ $this->table }}
    </section>
</x-filament-panels::page>
