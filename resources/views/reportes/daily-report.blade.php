<x-filament-panels::page>
    <form wire:submit="applyDate" class="flex flex-wrap items-end gap-3">
        <div>
            <label for="report-period-mode" class="mb-1 block text-sm font-medium">Periodo</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="report-period-mode" wire:model.live="periodMode">
                    <option value="day">Día</option><option value="week">Semana</option><option value="month">Mes</option><option value="range">Rango de fechas</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
            @error('periodMode') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
        </div>
        @if ($periodMode === 'range')
            <div>
                <label for="report-from" class="mb-1 block text-sm font-medium">Desde</label>
                <x-filament::input.wrapper><x-filament::input id="report-from" type="date" wire:model="rangeFrom" min="1900-01-01" :max="now('America/La_Paz')->toDateString()" /></x-filament::input.wrapper>
                @error('rangeFrom') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="report-until" class="mb-1 block text-sm font-medium">Hasta</label>
                <x-filament::input.wrapper><x-filament::input id="report-until" type="date" wire:model="rangeUntil" min="1900-01-01" :max="now('America/La_Paz')->toDateString()" /></x-filament::input.wrapper>
                @error('rangeUntil') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>
        @else
            <div>
                <label for="report-date" class="mb-1 block text-sm font-medium">{{ match ($periodMode) { 'week' => 'Fecha de la semana', 'month' => 'Fecha del mes', default => 'Día del reporte · Bolivia' } }}</label>
                <x-filament::input.wrapper><x-filament::input id="report-date" type="date" wire:model="date" min="1900-01-01" :max="now('America/La_Paz')->toDateString()" /></x-filament::input.wrapper>
                @error('date') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>
        @endif
        <x-filament::button type="submit" icon="heroicon-o-arrow-path" wire:loading.attr="disabled">Consultar / actualizar</x-filament::button>
        <p class="text-xs text-gray-500 dark:text-gray-400">Consultado: {{ $report['checked_at'] }} · Bolivia</p>
    </form>

    <div class="grid gap-3 md:grid-cols-2">
        <x-filament::section icon="heroicon-o-shopping-bag" icon-color="info" compact>
            <p class="text-sm text-gray-500 dark:text-gray-400">Ventas registradas · {{ $report['period']['label'] }}</p>
            <div class="mt-1 flex items-center justify-between gap-2">
                <p class="text-2xl font-bold text-info-600 dark:text-info-400">Bs {{ \App\Support\Bolivianos::formatCompact($report['sales_cents']) }}</p>
                <x-filament::icon-button icon="heroicon-o-eye" color="info" label="Ver registros de Ventas" title="Ver registros de Ventas" wire:click="showDetails('sales', 'ALL')" />
            </div>
            @include('reportes.daily-comparison', ['comparison' => $report['comparison']['sales'], 'previousLabel' => $report['period']['previous_label'], 'comparisonLabel' => $report['period']['mode'] === 'day' ? 'Día anterior' : 'Periodo anterior'])
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Tarjetas, recargas, accesorios, teléfonos y objetos vendidos de Préstamos.</p>
        </x-filament::section>
        <x-filament::section icon="heroicon-o-banknotes" icon-color="success" compact>
            <p class="text-sm text-gray-500 dark:text-gray-400">Cobros registrados · {{ $report['period']['label'] }}</p>
            <div class="mt-1 flex items-center justify-between gap-2">
                <p class="text-2xl font-bold text-success-600 dark:text-success-400">Bs {{ \App\Support\Bolivianos::formatCompact($report['collections_cents']) }}</p>
                <x-filament::icon-button icon="heroicon-o-eye" color="success" label="Ver registros de Cobros" title="Ver registros de Cobros" wire:click="showDetails('collections', 'ALL')" />
            </div>
            @include('reportes.daily-comparison', ['comparison' => $report['comparison']['collections'], 'previousLabel' => $report['period']['previous_label'], 'comparisonLabel' => $report['period']['mode'] === 'day' ? 'Día anterior' : 'Periodo anterior'])
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Incluye las ventas una sola vez, pagos de Préstamos y comisiones operativas de Cajas.</p>
        </x-filament::section>
    </div>
    @if ($report['is_today'])
        <p class="text-sm text-warning-600 dark:text-warning-400">Hoy está en curso; se compara con el día anterior completo.</p>
    @elseif ($report['period']['is_current'])
        <p class="text-sm text-warning-600 dark:text-warning-400">El periodo seleccionado está en curso; se compara con el periodo anterior completo.</p>
    @endif
    <p class="text-sm text-gray-500 dark:text-gray-400">Ventas forma parte de Cobros: no sumes ambas tarjetas. Los cobros incluyen capital recuperado; no representan ganancias. Se usa la fecha del registro confirmado, desde las 00:00 del inicio hasta finalizar el último día, en Bolivia.</p>

    <x-filament::section icon="heroicon-o-rectangle-stack" icon-color="warning" compact>
        <x-slot name="heading">Actividad por módulo</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <tr><th class="py-2 pr-3">Origen</th><th class="px-3 py-2 text-right">Registros</th><th class="px-3 py-2 text-right">Ventas</th><th class="py-2 pl-3 text-right">Cobros</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach($report['rows'] as $row)
                        <tr>
                            <td class="py-2 pr-3"><p class="font-medium">{{ $row['label'] }}</p><p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['note'] }}</p></td>
                            <td class="px-3 py-2 text-right"><button type="button" class="underline decoration-dotted underline-offset-4" wire:click="showDetails('collections', '{{ $row['source'] }}')" aria-label="Ver registros de {{ $row['label'] }}">{{ $row['operations'] }}</button></td>
                            <td class="whitespace-nowrap px-3 py-2 text-right"><button type="button" class="text-info-600 underline decoration-dotted underline-offset-4 dark:text-info-400" wire:click="showDetails('sales', '{{ $row['source'] }}')" aria-label="Ver ventas de {{ $row['label'] }}">Bs {{ \App\Support\Bolivianos::formatCompact($row['sales_cents']) }}</button></td>
                            <td class="whitespace-nowrap py-2 pl-3 text-right font-medium"><button type="button" class="text-success-600 underline decoration-dotted underline-offset-4 dark:text-success-400" wire:click="showDetails('collections', '{{ $row['source'] }}')" aria-label="Ver cobros de {{ $row['label'] }}">Bs {{ \App\Support\Bolivianos::formatCompact($row['collections_cents']) }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-wallet" icon-color="success" compact>
        <x-slot name="heading">Dinero disponible ahora · fondos separados</x-slot>
        <x-slot name="description">Estos saldos son actuales aunque consultes un día anterior. No son el saldo histórico al cierre de ese día ni un saldo bancario conciliado.</x-slot>
        <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
            @foreach($report['balances'] as $balance)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <p class="text-sm font-medium">{{ $balance['label'] }}</p>
                    <p class="mt-1 text-lg font-bold">{{ $balance['amount_cents'] === null ? 'No disponible' : 'Bs '.\App\Support\Bolivianos::formatCompact($balance['amount_cents']) }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $balance['note'] }}</p>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">No se suman cuentas de distintos módulos ni saldos digitales Entel. Aportes, depósitos a Administración, liquidaciones, reposiciones y anulaciones no son ventas ni nuevos cobros de clientes.</p>
    </x-filament::section>
    <div x-on:modal-closed.window="if ($event.detail.id === 'daily-report-records' && $wire.detailOpen) $wire.closeDetails()">
        <x-filament::modal id="daily-report-records" width="7xl" heading="Detalle de registros" icon="heroicon-o-clipboard-document-list" :sticky-header="true">
            @if ($report['detail'])
                <div class="mb-3 flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$this->detailExportUrl('xlsx')" icon="heroicon-o-table-cells" size="sm">Detalle Excel</x-filament::button>
                    <x-filament::button tag="a" :href="$this->detailExportUrl('csv')" icon="heroicon-o-document-text" color="gray" size="sm">Detalle CSV</x-filament::button>
                    <span class="text-xs text-gray-500">Todos los registros filtrados, no solo esta página.</span>
                </div>
                @include('reportes.daily-records', ['detail' => $report['detail']])
            @endif
        </x-filament::modal>
    </div>
</x-filament-panels::page>
