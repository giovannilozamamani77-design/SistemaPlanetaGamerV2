<x-filament-panels::page>
    @include('reportes.cash-box-filters', ['filterHeading' => 'Consultar transacciones por cajera'])
    <div class="grid gap-3 md:grid-cols-3">
        @foreach([['Operaciones registradas','operations'],['Transacciones generales BCP','general'],['Transacciones fijas BCP','fixed']] as [$label,$key])
            <x-filament::section compact><p class="text-sm text-gray-500">{{ $label }}</p><p class="mt-2 text-3xl font-bold">{{ $cashReport[$key] }}</p></x-filament::section>
        @endforeach
    </div>
    <p class="text-sm text-gray-500">Son fuentes independientes: los contadores BCP se obtienen de cierres válidos y las operaciones son registros vigentes del sistema. No sumes ambas fuentes ni los contadores generales y fijos. Tigo Money no utiliza contadores BCP. {{ $cashReport['baselines'] }} lecturas iniciales generales y {{ $cashReport['unknown_fixed'] }} lecturas fijas sin referencia no permiten atribuir transacciones anteriores.</p>
    <div class="grid gap-4 xl:grid-cols-2">
        @livewire(\App\Filament\Widgets\Reportes\CashBoxReportChart::class, ['label'=>'Transacciones BCP por cajera','chartType'=>'bar','chartData'=>$cashReport['charts']['bcp']], key('transactions-bcp-'.md5(json_encode([$cashReport['filters'],$cashReport['charts']['bcp']]))))
        @livewire(\App\Filament\Widgets\Reportes\CashBoxReportChart::class, ['label'=>'Operaciones registradas por cajera','chartType'=>'bar','chartData'=>$cashReport['charts']['operations']], key('transactions-ops-'.md5(json_encode([$cashReport['filters'],$cashReport['charts']['operations']]))))
    </div>
    <x-filament::section><x-slot name="heading">Transacciones por responsable</x-slot><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Cajera / responsable</th><th class="p-3">Operaciones registradas</th><th class="p-3">Generales BCP</th><th class="p-3">Fijas BCP</th></tr></thead><tbody>
    @forelse($cashReport['ranking'] as $row)<tr class="border-t border-gray-200 dark:border-white/10"><td class="p-3 font-semibold">{{ $row['name'] }}</td><td class="p-3">{{ $row['operations'] }}</td><td class="p-3">{{ $row['general'] }}</td><td class="p-3">{{ $row['fixed'] }}</td></tr>@empty<tr><td colspan="4" class="p-3">Sin transacciones en este periodo.</td></tr>@endforelse
    </tbody></table></div></x-filament::section>
    <x-filament::section collapsible><x-slot name="heading">Contadores declarados en cierres BCP</x-slot><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Fecha / Cajera / Caja</th><th class="p-3">General acumulado</th><th class="p-3">General del turno</th><th class="p-3">Fijo acumulado</th><th class="p-3">Fijo del turno</th></tr></thead><tbody>
    @forelse($cashReport['counter_records'] as $row)<tr class="border-t border-gray-200 dark:border-white/10"><td class="p-3">{{ \Carbon\CarbonImmutable::parse($row->created_at,'UTC')->timezone('America/La_Paz')->format('d/m/Y H:i') }} · {{ $row->user_name }} · {{ $row->box_name }}</td><td class="p-3">{{ $row->reading }}</td><td class="p-3">{{ $row->baseline ? 'Referencia inicial' : $row->delta }}</td><td class="p-3">{{ $row->fixed_reading ?? 'Sin lectura' }}</td><td class="p-3">{{ $row->fixed_delta ?? 'Sin referencia' }}</td></tr>@empty<tr><td colspan="5" class="p-3">Sin cierres BCP válidos en este periodo.</td></tr>@endforelse
    </tbody></table></div>{{ $cashReport['counter_records']->links() }}</x-filament::section>

    <x-filament::section collapsible><x-slot name="heading">Detalle de operaciones registradas</x-slot><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Fecha</th><th class="p-3">Cajera</th><th class="p-3">Caja</th><th class="p-3">Tipo</th><th class="p-3">Monto registrado</th></tr></thead><tbody>
    @forelse($cashReport['operation_records'] as $row)<tr class="border-t border-gray-200 dark:border-white/10"><td class="p-3">{{ \Carbon\CarbonImmutable::parse($row->created_at,'UTC')->timezone('America/La_Paz')->format('d/m/Y H:i:s') }}</td><td class="p-3">{{ $row->user_name }}</td><td class="p-3">{{ $row->box_name }}</td><td class="p-3">{{ $row->fee_type }} @if($row->bill_count) · {{ $row->bill_count }} facturas @endif</td><td class="p-3">Bs {{ \App\Support\Bolivianos::format($row->amount_cents) }}</td></tr>@empty<tr><td colspan="5" class="p-3">Sin operaciones vigentes.</td></tr>@endforelse
    </tbody></table></div>{{ $cashReport['operation_records']->links() }}</x-filament::section>
</x-filament-panels::page>
