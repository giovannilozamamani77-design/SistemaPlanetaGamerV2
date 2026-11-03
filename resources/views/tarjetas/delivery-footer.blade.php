<div class="pg-purchase-footer">
    <div class="pg-purchase-total"><span>Entrega de Administración</span><strong>{{ $units }} tarjetas</strong><small>Solo aumenta el stock. Efectivo sin cambios.</small></div>
    <div class="pg-purchase-actions"><x-filament::button type="button" color="gray" wire:click="unmountAction">Cancelar</x-filament::button><x-filament::button type="submit" icon="heroicon-o-inbox-arrow-down" :disabled="! $ready" wire:loading.attr="disabled">Confirmar recepción</x-filament::button></div>
</div>
