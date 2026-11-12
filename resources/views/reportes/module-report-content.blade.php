<div class="flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $report['definition']['description'] }}</p>
        <form wire:submit="applyMonth" class="flex items-end gap-2">
            <div><label for="report-month" class="mb-1 block text-xs font-medium">Mes a consultar</label>
                <x-filament::input.wrapper><x-filament::input id="report-month" type="month" wire:model="month" min="1900-01" :max="now('America/La_Paz')->format('Y-m')" /></x-filament::input.wrapper>
            </div>
            <x-filament::button type="submit" icon="heroicon-o-funnel">Consultar</x-filament::button>
        </form>
    </div>
    @error('month') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <x-filament::badge icon="heroicon-o-calendar-days">{{ $report['period']['label'] }} · Bolivia</x-filament::badge>
        @if ($report['period']['is_current'])<x-filament::badge color="warning">Mes en curso · comparación contra el mes anterior completo</x-filament::badge>@endif
    </div>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <x-filament::section icon="heroicon-o-banknotes">
            <p class="text-sm text-gray-500">Cobrado en el mes</p>
            <p class="mt-1 text-2xl font-bold text-cyan-500">Bs {{ \App\Support\Bolivianos::formatCompact($report['collections_cents']) }}</p>
            @include('reportes.daily-comparison', ['comparison' => $report['comparison']['collections'] ?? null, 'comparisonLabel' => 'Mes anterior', 'previousLabel' => $report['period']['previous_label']])
        </x-filament::section>
        <x-filament::section icon="heroicon-o-chart-bar">
            <p class="text-sm text-gray-500">{{ $report['definition']['margin_label'] }}</p>
            <p @class(['mt-1 text-2xl font-bold', 'text-success-600' => $report['margin']['known_cents'] >= 0, 'text-danger-600' => $report['margin']['known_cents'] < 0])>Bs {{ \App\Support\Bolivianos::formatCompact($report['margin']['known_cents']) }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $report['definition']['note'] }}</p>
            @if ($report['margin']['pending'])<p class="mt-2 font-semibold text-warning-600">Parcial: {{ $report['margin']['pending'] }} registros con resultado pendiente</p>@endif
        </x-filament::section>
        <x-filament::section icon="heroicon-o-clipboard-document-list">
            @if ($report['module'] === 'LOANS')
                <p class="text-sm text-gray-500">Capital recuperado identificado</p>
                <p class="mt-1 text-2xl font-bold">Bs {{ \App\Support\Bolivianos::formatCompact($report['margin']['capital_recovered_cents']) }}</p>
                <p class="mt-1 text-xs text-gray-500">Se devuelve al fondo; no es ganancia. Solo comprobantes con desglose conservado.</p>
            @elseif ($report['module'] === 'ACCESSORIES')
                <p class="text-sm text-gray-500">Comisiones ganadas en estas ventas</p>
                <p class="mt-1 text-2xl font-bold text-warning-600">Bs {{ \App\Support\Bolivianos::formatCompact($report['margin']['commissions_cents'] ?? 0) }}</p>
                <p class="mt-1 text-xs">Tras comisiones: {{ $report['margin']['after_commissions_cents'] === null ? 'Pendiente de desglose completo' : 'Bs '.\App\Support\Bolivianos::formatCompact($report['margin']['after_commissions_cents']) }}</p>
                @if ($report['margin']['commission_pending'])<p class="text-xs text-warning-600">{{ $report['margin']['commission_pending'] }} registros sin comisión conservada</p>@endif
            @else
                <p class="text-sm text-gray-500">Registros por tipo</p><p class="mt-1 text-2xl font-bold">{{ $report['operations'] }}</p>
                <p class="mt-1 text-xs text-gray-500">Desglose y comprobantes disponibles abajo. No es una nueva operación.</p>
            @endif
        </x-filament::section>
    </div>
    <p class="text-xs text-gray-500">Estos resultados no son utilidad neta del negocio: no incluyen todos los gastos generales, sueldos y pérdidas. Los depósitos y aportes de capital no cuentan como ventas ni ganancias. Los cierres guardados conservan sus versiones originales.</p>
    <div class="grid grid-cols-1 items-start gap-3 xl:grid-cols-2">
        @livewire(\App\Filament\Widgets\Reportes\ModuleReportChart::class, ['chartData' => $report['charts']['trend'], 'chartType' => 'line'], key('trend-'.$report['module'].'-'.$report['month'].'-'.hash('sha256', json_encode($report['charts']['trend']))))
        @livewire(\App\Filament\Widgets\Reportes\ModuleReportChart::class, ['chartData' => $report['charts']['distribution'], 'chartType' => 'doughnut'], key('distribution-'.$report['module'].'-'.$report['month'].'-'.hash('sha256', json_encode($report['charts']['distribution']))))
    </div>
    <x-filament::section heading="Desglose del mes" icon="heroicon-o-table-cells">
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-gray-500 dark:border-white/10"><tr><th class="py-2">Origen</th><th class="px-3 py-2 text-right">Cobrado</th><th class="px-3 py-2 text-right">Resultado conocido</th><th class="px-3 py-2 text-right">Pendientes</th><th></th></tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($report['rows'] as $source => $row)
                    <tr><td class="py-3">{{ $row['label'] }}<p class="text-xs text-gray-500">{{ $row['operations'] }} registros</p></td>
                        <td class="px-3 py-3 text-right font-semibold">Bs {{ \App\Support\Bolivianos::formatCompact($row['collections_cents']) }}</td>
                        <td class="px-3 py-3 text-right">Bs {{ \App\Support\Bolivianos::formatCompact($report['margin']['parts'][$source]['known_cents'] ?? 0) }}</td>
                        <td class="px-3 py-3 text-right">{{ $report['margin']['parts'][$source]['pending'] ?? 0 }}</td>
                        <td class="text-right"><x-filament::icon-button icon="heroicon-o-eye" label="Ver registros" wire:click="showDetails('{{ $source }}')" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    </x-filament::section>
    <div x-on:modal-closed.window="if ($event.detail.id === 'module-report-records' && $wire.detailOpen) $wire.closeDetails()">
        <x-filament::modal id="module-report-records" width="7xl" heading="Registros del módulo" icon="heroicon-o-clipboard-document-list" :sticky-header="true">
            @if ($detail) @include('reportes.daily-records', ['detail' => $detail]) @endif
        </x-filament::modal>
    </div>
