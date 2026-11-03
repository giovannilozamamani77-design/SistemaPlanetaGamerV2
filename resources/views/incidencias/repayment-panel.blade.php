<x-filament-panels::page>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;">
        <x-filament::section>
            <div style="display:flex;gap:.5rem;align-items:center;"><x-filament::icon icon="heroicon-o-banknotes" style="width:1.4rem;height:1.4rem;color:#f87171;" /><strong>Faltantes acumulados</strong></div>
            <div style="font-size:1.9rem;font-weight:750;margin:.4rem 0;color:#f87171;">Bs {{ \App\Support\Bolivianos::format($panelOverview['pending_cents']) }}</div>
            <p style="font-size:.8rem;opacity:.7;">Deuda confirmada pendiente · Todo el personal</p>
        </x-filament::section>
        @foreach (['reports'=>['Reposiciones por verificar','heroicon-o-check-badge'],'global'=>['Reposiciones globales','heroicon-o-rectangle-stack'],'appeals'=>['Apelaciones por revisar','heroicon-o-chat-bubble-left-ellipsis']] as $section=>$item)
            @if ($section !== 'appeals' || auth()->user()->can('incidencias.confirmar'))
                <x-filament::section>
                    <div style="display:flex;gap:.5rem;align-items:center;"><x-filament::icon :icon="$item[1]" style="width:1.3rem;height:1.3rem;opacity:.8;" /><strong>{{ $item[0] }}</strong></div>
                    <div style="font-size:1.9rem;font-weight:750;margin:.4rem 0;">{{ $panelOverview[$section] }}</div>
                    <p style="font-size:.8rem;opacity:.7;">Pendientes según filtros</p>
                </x-filament::section>
            @endif
        @endforeach
    </div>
    @if (collect($this->panelFilters)->filter(fn($value)=>filled($value))->isNotEmpty())
        <p style="font-size:.85rem;opacity:.8;">Filtros activos · Se conservan al cambiar de pestaña. Usa «Filtros» para revisarlos o «Limpiar filtros» para ver todo.</p>
    @endif
    <x-filament::tabs label="Gestión de reposiciones">
        <x-filament::tabs.item :active="$this->panelSection === 'reports'" wire:click="selectPanelSection('reports')" icon="heroicon-o-check-badge">Reposiciones por verificar</x-filament::tabs.item>
        <x-filament::tabs.item :active="$this->panelSection === 'global'" wire:click="selectPanelSection('global')" icon="heroicon-o-rectangle-stack">Reposiciones globales</x-filament::tabs.item>
        @if(auth()->user()->can('incidencias.confirmar'))
            <x-filament::tabs.item :active="$this->panelSection === 'appeals'" wire:click="selectPanelSection('appeals')" icon="heroicon-o-chat-bubble-left-ellipsis">Apelaciones por revisar</x-filament::tabs.item>
        @endif
    </x-filament::tabs>
    @if ($this->panelSection === 'reports')
        @livewire(\App\Filament\Widgets\Personal\PendingRepaymentReportsWidget::class, ['panelFilters'=>$this->panelFilters], key('reports-'.md5(json_encode($this->panelFilters))))
    @elseif ($this->panelSection === 'global')
        {{ $this->table }}
    @else
        @livewire(\App\Filament\Widgets\Personal\PendingIncidentAppealsWidget::class, ['panelFilters'=>$this->panelFilters], key('appeals-'.md5(json_encode($this->panelFilters))))
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
