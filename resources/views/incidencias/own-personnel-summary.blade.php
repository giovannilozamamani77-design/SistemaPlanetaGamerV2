<x-filament-widgets::widget>
    @if ($section === 'primary')
        <x-filament::section>
            <div class="pg-shortage-accumulated" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:0.5rem;">
                <div style="flex:1;min-width:180px;">
                    <div style="display:flex;align-items:center;gap:0.5rem;"><x-filament::icon icon="heroicon-o-exclamation-circle" style="width:1.4rem;height:1.4rem;" /><strong>Faltante acumulado pendiente</strong></div>
                    <div style="font-size:2rem;font-weight:750;margin:0.35rem 0;">Bs {{ \App\Support\Bolivianos::format($balances['pending']) }}</div>
                    <p style="font-size:0.85rem;opacity:0.75;">Solo responsabilidad confirmada. Por revisar: Bs {{ \App\Support\Bolivianos::format($balances['under_review']) }} (no incluido).</p>
                </div>
                @if ($quote && $quote['pending'] > 0)
                    <div style="display:flex;flex-direction:column;gap:0.5rem;align-items:stretch;margin-left:auto;min-width:180px;">
                        @if ($quote['blocked'])
                            <x-filament::badge color="warning">Depósito por verificar</x-filament::badge>
                            <span style="font-size:0.85rem;">Espera la revisión para ver el saldo actualizado.</span>
                        @else
                            {{ $this->payTotalAction }}
                            {{ $this->payPartialAction }}
                        @endif
                    </div>
                @endif
            </div>
        </x-filament::section>
    @else
        {{ $this->content }}
    @endif
    <x-filament-actions::modals />
</x-filament-widgets::widget>
