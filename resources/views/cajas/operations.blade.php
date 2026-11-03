<x-filament-panels::page>
    @if ($this->workspace && $this->workspace['session_id'])
        @include('cajas.workspace', ['workspace' => $this->workspace])
    @elseif ($this->workspace)
        @include('cajas.opening-form', ['workspace' => $this->workspace])
        @include('arqueos.cash-count-modal')
    @endif
    @if (!$this->workspace)
        @include('cajas.selection', $this->selection)
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
