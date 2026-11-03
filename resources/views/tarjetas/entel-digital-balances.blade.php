<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        @foreach (\App\Domain\Tarjetas\Models\EntelDigitalAccount::KINDS as $kind => $label)
            <div class="pg-card-inventory-stat {{ $kind === 'CREDIT' ? 'pg-card-inventory-stat-cash' : '' }}">
                <span>Entel · {{ $label }}</span>
                <strong>{{ isset($accounts[$kind]) ? 'Bs '.\App\Support\Bolivianos::formatCompact($accounts[$kind]->balance_cents) : 'Sin saldo inicial' }}</strong>
                <small>{{ isset($accounts[$kind]) ? 'Saldo digital registrado. Las cargas aumentan únicamente este libro.' : 'Declara el saldo real disponible, incluso si es cero.' }}</small>
            </div>
        @endforeach
    </div>
    <p class="text-sm text-gray-500">Solo Administración. Crédito y Paquetes no se mezclan con tarjetas físicas ni efectivo de tienda. Los importes pagados se registran como información de compras ya realizadas; esta pantalla no ejecuta ni descuenta pagos de otras cuentas. La bonificación recibida no es ganancia realizada. Las ventas de recargas consumen el importe cobrado del tipo elegido y suman efectivo en tienda. Los bonos del cliente son informativos y no consumen saldo adicional.</p>
    {{ $this->table }}
</x-filament-panels::page>
