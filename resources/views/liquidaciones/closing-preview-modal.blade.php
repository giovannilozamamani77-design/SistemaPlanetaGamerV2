<div class="pg-closing-liquidation-preview" x-on:modal-closed.window="if ($event.detail.id === 'closing-liquidation-preview' && $wire.liquidationPreviewOpen) $wire.dismissLiquidationPreview()">
    <x-filament::modal id="closing-liquidation-preview" width="5xl" heading="Liquidación del turno" description="Vista previa. Volver no registra ni cierra. No revierte dinero ya transferido." :sticky-header="true" :sticky-footer="true">
        @if ($liquidationPreviewOpen)
            @php($quote = $this->liquidationPreview)
            @php($calculation = $quote['calculation'])
            @if ($declaredTransfer !== null)<p class="pg-closing-summary-warning">Transferencia ya declarada: {{ $declaredTransfer['reference'] }}. No vuelvas a enviarla.</p>@endif
            @if (!$liquidationReady)<p role="alert" class="pg-closing-summary-warning">No transfieras todavía. {{ $liquidationReadinessMessage }}</p>@endif
            <div class="pg-closing-summary-totals">
                <article class="pg-closing-summary-total"><span>Importe a liquidar</span><strong>Bs {{ \App\Support\Bolivianos::format($calculation['amount_cents']) }}</strong></article>
                <article><span>Capital operativo</span><strong>Bs {{ \App\Support\Bolivianos::format($calculation['capital_cents']) }}</strong></article>
            </div>
            <div class="pg-closing-liquidation-accounts">
            <section class="pg-closing-counter-card">
                <h2>Cuenta de origen</h2><strong>{{ $quote['source_account']['name'] }}</strong>
                @if (!empty($quote['source_account']['reference']))<p>{{ $quote['source_account']['reference'] }}</p>@endif
                <p>Disponible declarado: Bs {{ \App\Support\Bolivianos::format($calculation['source_available_cents']) }}</p>
                <p>{{ match ($calculation['status']) {
                    'DISPONIBLE' => 'El excedente saldría de esta cuenta y se conservaría el capital operativo.',
                    'SIN_EXCEDENTE' => 'No hay excedente para liquidar.',
                    'FALTANTE' => 'El total no cubre el capital operativo. Revisa los saldos; no hay importe para liquidar.',
                    default => 'La cuenta de origen no cubre el importe. Revisa los saldos o su configuración.'
                } }}</p>
                @if ($calculation['can_liquidate'] || $calculation['status'] === 'SIN_EXCEDENTE')
                    <div class="pg-closing-summary-capital"><span>Capital que quedaría en caja</span><strong>Bs {{ \App\Support\Bolivianos::format($calculation['capital_after_cents']) }}</strong></div>
                @endif
            </section>
            @include('liquidaciones.account', ['account' => $quote['account']])
            </div>
            @if (!$quote['account']?->qr_path)<p class="pg-closing-summary-warning">Sin QR configurado. Administración debe configurar el destino y su QR.</p>@endif
            <p>Si ya transferiste, registra esa referencia y no repitas el envío. Revisa el destino y realiza la transferencia fuera del sistema. Solo la confirmación final registra el cierre y tu declaración de liquidación. No se verifica automáticamente con el banco.</p>
            @if ($liquidationReady && $calculation['amount_cents'] > 0 && $calculation['can_liquidate'] && $quote['account'] && !$quote['account']->is_demo)
                <label for="liquidation-reference">Referencia de la transferencia</label>
                <x-filament::input.wrapper><x-filament::input id="liquidation-reference" wire:model="operationReference" maxlength="120" placeholder="Número de operación o referencia" /></x-filament::input.wrapper>
                <label><input type="checkbox" wire:model="transferred"> Ya realicé la transferencia al destino indicado.</label>
            @endif
            @error('closing')<p role="alert" class="pg-closing-error">{{ $message }}</p>@enderror
        @endif
        <x-slot name="footer"><x-filament::button color="gray" icon="heroicon-o-arrow-left" wire:click="dismissLiquidationPreview">Volver sin registrar</x-filament::button>
            @if ($liquidationPreviewOpen && $liquidationReady && ($this->liquidationPreview['calculation']['amount_cents'] === 0 || ($this->liquidationPreview['calculation']['can_liquidate'] && $this->liquidationPreview['account'] && !$this->liquidationPreview['account']->is_demo)))
                <x-filament::button wire:click="confirmClosing" wire:loading.attr="disabled" icon="heroicon-o-check">{{ $this->liquidationPreview['calculation']['amount_cents'] > 0 ? 'Confirmar liquidación y cierre' : 'Confirmar cierre sin importe a liquidar' }}</x-filament::button>
            @endif
        </x-slot>
    </x-filament::modal>
</div>
