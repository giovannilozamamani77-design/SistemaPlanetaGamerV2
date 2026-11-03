<article class="pg-cash-card pg-store-welcome" data-store-welcome aria-labelledby="store-welcome-heading">
    <div class="pg-cash-card-top">
        <span class="pg-cash-card-icon" aria-hidden="true"><x-heroicon-o-device-phone-mobile /></span>
        <span class="pg-cash-state {{ $cardSessionStatus['session'] ? 'pg-cash-state-open' : 'pg-cash-state-closed' }}"><span aria-hidden="true"></span>{{ $cardSessionStatus['session'] ? 'Abierta' : ($cardSessionStatus['initialized'] ? 'Cerrada' : 'Falta conteo inicial') }}</span>
    </div>
    <div class="pg-cash-card-details">
        <p class="pg-cash-card-type">TIENDA · CONTROL DE TURNO</p>
        <h2 id="store-welcome-heading">{{ $cardSessionStatus['session'] ? 'Tarjetas en atención' : 'Comienza tu turno de Tarjetas' }}</h2>
        @if ($cardSessionStatus['session'])
            <p class="pg-cash-card-description">Responsable: <strong>{{ $cardSessionStatus['session']->user->name }}</strong>. Espera el cierre de la responsable para recibir el relevo.</p>
        @elseif (! $cardSessionStatus['initialized'])
            <p class="pg-cash-card-description">Administración debe registrar el efectivo y las cantidades iniciales una sola vez antes de abrir.</p>
        @else
            <p class="pg-cash-card-description">Abre Tarjetas para verificar el efectivo y los cortes recibidos. Después podrás vender y registrar movimientos.</p>
            <div class="pg-store-welcome-steps">
                <span><x-heroicon-o-ticket aria-hidden="true" /><strong>1. Revisa las tarjetas</strong><small>Cuenta los cortes con stock, separados por empresa.</small></span>
                <span><x-heroicon-o-banknotes aria-hidden="true" /><strong>2. Cuenta el efectivo</strong><small>Verifica el monto recibido; puedes usar el arqueo.</small></span>
            </div>
        @endif
    </div>
    <div class="pg-store-welcome-footer">
        @if (! $cardSessionStatus['initialized'])
            @can('initialize', \App\Domain\Tarjetas\Models\CardProduct::class)
                <x-filament::button :href="\App\Filament\Pages\Tarjetas\StoreInventory::getUrl(['action' => 'initializeStore'])" tag="a" size="xl" icon="heroicon-o-clipboard-document-check">Cargar inventario inicial</x-filament::button>
            @else
                <p class="pg-cash-card-description">Solicita el conteo inicial a Administración.</p>
            @endcan
        @elseif (! $cardSessionStatus['session'])
            @can('openSession', \App\Domain\Tarjetas\Models\CardProduct::class)
                <x-filament::button :href="\App\Filament\Pages\Tarjetas\CardStoreOpening::getUrl()" tag="a" data-open-store size="xl" color="success" icon="heroicon-o-lock-open">Abrir Tarjetas</x-filament::button>
            @else
                <p class="pg-cash-card-description">Solicita a Administración el permiso para abrir Tarjetas.</p>
            @endcan
        @endif
        <div class="pg-store-welcome-history"><span>Consultar ventas anteriores</span>@include('tarjetas.sale-history-button')</div>
    </div>
</article>
