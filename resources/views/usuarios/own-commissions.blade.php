<x-filament-widgets::widget>
@php($totals=$this->overview())
<div class="space-y-4">
    <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-5">
        @foreach([['Comisiones acumuladas','total_cents','heroicon-o-banknotes','success'],['Por accesorios','accessory_cents','heroicon-o-cube','warning'],['Por teléfonos','phone_cents','heroicon-o-device-phone-mobile','info'],['Pagado','paid_cents','heroicon-o-check-circle','success'],['Pendiente','pending_cents','heroicon-o-wallet','warning']] as [$label,$key,$icon,$color])
        <x-filament::section :icon="$icon" :icon-color="$color" compact>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
            <p class="mt-1 text-2xl font-bold">Bs {{ \App\Support\Bolivianos::formatCompact($totals[$key]) }}</p>
        </x-filament::section>
        @endforeach
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400">Ganado conserva tus comisiones originales. Pagado muestra lo entregado por Administración y Pendiente lo que falta pagar. Los totales incluyen todo tu historial, aunque cambies de página o filtres por tipo.</p>
    {{ $this->table }}
</div>
</x-filament-widgets::widget>
