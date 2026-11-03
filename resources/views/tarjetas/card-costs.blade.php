<x-filament-panels::page>
    <p class="text-sm text-gray-500">Solo Administración. El costo promedio usa lo que queda y el costo real de cada entrada. Si falta un costo, las ventas continúan y su margen queda pendiente. El catálogo de precios no sustituye ese costo.</p>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        @foreach (['PHYSICAL' => 'Tarjetas y Cabina Viva', 'CREDIT' => 'Entel · Crédito', 'PACKAGES' => 'Entel · Paquetes'] as $kind => $label)
            <div class="pg-card-inventory-stat">
                <span>{{ $label }} · margen bruto conocido</span>
                <strong>Bs {{ \App\Support\Bolivianos::formatCompact($margins[$kind]['known_margin_cents']) }}</strong>
                <small>{{ $margins[$kind]['pending'] }} registros con margen pendiente · {{ $margins[$kind]['records'] }} registros históricos</small>
            </div>
        @endforeach
    </div>
    <p class="text-sm text-gray-500">Acumulado histórico parcial: excluye los registros pendientes de costo. Es margen bruto, antes de gastos y comisiones. Las ventas antiguas no se recalculan.</p>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        @foreach ($books as $book)
            <div class="pg-card-inventory-stat pg-card-inventory-stat-cash">
                <span>Entel · {{ \App\Domain\Tarjetas\Models\EntelDigitalAccount::KINDS[$book->kind] }}</span>
                <strong>Saldo Bs {{ \App\Support\Bolivianos::formatCompact($book->balance_cents) }}</strong>
                <small>Costo restante: {{ $book->balance_cents === 0 ? 'Bs 0' : ($book->cost_value_cents === null ? 'Pendiente de costo' : 'Bs '.\App\Support\Bolivianos::formatCompact($book->cost_value_cents)) }}</small>
            </div>
        @endforeach
    </div>
    {{ $this->table }}
</x-filament-panels::page>
