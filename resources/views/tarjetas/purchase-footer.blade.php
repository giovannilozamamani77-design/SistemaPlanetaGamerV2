<div @class(['pg-purchase-footer','is-insufficient'=>$insufficient])>
    <div class="pg-purchase-total"><span>Total de la compra · {{ $units }} tarjetas</span><strong>{{ $total }}</strong><small>{{ $insufficient ? 'Efectivo insuficiente para la compra. Contacta con Administración.' : ($ready ? 'El efectivo alcanza.' : 'Selecciona una empresa e ingresa las cantidades.') }}</small></div>
    <div class="pg-purchase-actions"><x-filament::button type="button" color="gray" wire:click="unmountAction">Cancelar</x-filament::button><x-filament::button type="submit" icon="heroicon-o-shopping-cart" :disabled="! $ready" wire:loading.attr="disabled">Registrar compra</x-filament::button></div>
</div>
