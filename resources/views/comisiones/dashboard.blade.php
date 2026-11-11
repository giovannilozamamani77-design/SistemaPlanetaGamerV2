<x-filament-panels::page>
@php($totals=$this->overview())
<div class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
    @foreach([['Comisiones acumuladas','total_cents','heroicon-o-banknotes','success'],['Por accesorios','accessory_cents','heroicon-o-cube','warning'],['Por teléfonos','phone_cents','heroicon-o-device-phone-mobile','info'],['Descontado','deducted_cents','heroicon-o-minus-circle','danger'],['Pagado','paid_cents','heroicon-o-check-circle','success'],['Pendiente','pending_cents','heroicon-o-wallet','warning']] as [$label,$key,$icon,$color])
    <x-filament::section :icon="$icon" :icon-color="$color" compact>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
        <p class="mt-1 text-2xl font-bold">Bs {{ \App\Support\Bolivianos::formatCompact($totals[$key]) }}</p>
    </x-filament::section>
    @endforeach
</div>
<p class="text-sm text-gray-500 dark:text-gray-400">Ganado conserva las comisiones originales; Pagado acumula entregas de Administración y Descontado las reducciones confirmadas; Pendiente es lo que falta pagar. Las tarjetas incluyen todo el historial; buscar o cambiar de página no modifica sus totales. Se conserva el historial de cuentas desactivadas y antiguos responsables.</p>
{{ $this->table }}
</x-filament-panels::page>
