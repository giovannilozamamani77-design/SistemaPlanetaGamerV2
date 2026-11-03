<section class="pg-cash-family pg-cash-family-shortcuts" aria-labelledby="cash-shortcuts-heading">
    <header class="pg-cash-family-heading"><span class="pg-cash-family-mark" aria-hidden="true"><x-heroicon-o-squares-2x2 class="h-4 w-4" /></span><h2 id="cash-shortcuts-heading">Accesos directos</h2></header>
    <div class="pg-cash-cards">
        <article class="pg-cash-card" aria-label="Acceso a caja de Tarjetas">
            <div class="pg-cash-card-top">
                <span class="pg-cash-card-icon" aria-hidden="true"><x-heroicon-o-device-phone-mobile /></span>
                <span class="pg-cash-state {{ $status['session'] ? 'pg-cash-state-open' : 'pg-cash-state-closed' }}"><span aria-hidden="true"></span>{{ $status['session'] ? 'Abierta' : ($status['initialized'] ? 'Cerrada' : 'Falta conteo inicial') }}</span>
            </div>
            <div class="pg-cash-card-details">
                <h3>Tarjetas</h3>
                <p class="pg-cash-card-code">TIENDA</p>
                <div class="pg-cash-account"><span>Control de turno</span><strong>Efectivo y tarjetas</strong></div>
                <p class="pg-cash-card-description">@if (! $status['initialized'])Administración debe registrar el efectivo y las cantidades iniciales una sola vez.@elseif ($status['session'])Sesión abierta · {{ $status['session']->user->name }}@elseDisponible para recibir y abrir.@endif</p>
            </div>
            <div class="pg-cash-card-footer">
                @if (! $status['initialized'])
                    @can('initialize', \App\Domain\Tarjetas\Models\CardProduct::class)
                        <x-filament::link :href="\App\Filament\Pages\Tarjetas\StoreInventory::getUrl(['action' => 'initializeStore'])" icon="heroicon-o-clipboard-document-check">Cargar inventario inicial</x-filament::link>
                    @else
                        <span class="pg-cash-card-access">Solicita el conteo inicial a Administración.</span>
                    @endcan
                @elseif (! $status['session'])
                    <span class="pg-cash-card-access"><x-heroicon-o-clipboard-document-check aria-hidden="true" />Conteo al abrir</span>
                    @can('openSession', \App\Domain\Tarjetas\Models\CardProduct::class)
                        <x-filament::link :href="\App\Filament\Pages\Tarjetas\CardStoreOpening::getUrl()" icon="heroicon-o-arrow-right">Abrir Tarjetas</x-filament::link>
                    @endcan
                @else
                    <x-filament::link :href="\App\Filament\Pages\Tarjetas\QuickCardSale::getUrl()" icon="heroicon-o-arrow-right">{{ $status['is_owner'] ? 'Continuar ventas' : 'Ver Tarjetas' }}</x-filament::link>
                    @if ($status['is_owner'])
                        @can('closeSession', \App\Domain\Tarjetas\Models\CardProduct::class)
                            <x-filament::link :href="\App\Filament\Pages\Tarjetas\CardStoreClosing::getUrl()" icon="heroicon-o-lock-closed" color="danger">Cerrar Tarjetas</x-filament::link>
                        @endcan
                    @endif
                @endif
            </div>
        </article>
    </div>
</section>
