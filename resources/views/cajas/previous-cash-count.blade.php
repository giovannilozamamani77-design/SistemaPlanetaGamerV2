<section class="space-y-3" aria-label="Arqueo del cierre anterior">
    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
        <div class="rounded-lg bg-primary-500/15 p-3"><p class="text-xs text-gray-500 dark:text-gray-400">Total del arqueo</p><strong class="text-2xl text-primary-600 dark:text-primary-400">Bs {{ \App\Support\Bolivianos::formatCompact($count['total_cents']) }}</strong></div>
        <div class="rounded-lg bg-gray-500/5 p-3"><p class="text-xs text-gray-500 dark:text-gray-400">Valor de piezas sueltas</p><strong>{{ isset($count['loose_total_cents']) ? 'Bs '.\App\Support\Bolivianos::formatCompact($count['loose_total_cents']) : 'Sin desglose' }}</strong></div>
        <div class="rounded-lg bg-gray-500/5 p-3"><p class="text-xs text-gray-500 dark:text-gray-400">Valor total embalado</p><strong>{{ isset($count['packed_total_cents']) ? 'Bs '.\App\Support\Bolivianos::formatCompact($count['packed_total_cents']) : 'Sin desglose' }}</strong></div>
    </div>
    <div class="grid grid-cols-1 items-start gap-3 lg:grid-cols-2">
        @foreach (['Billetes' => 'b', 'Monedas' => 'm'] as $group => $prefix)
            @php($rows = collect($count['denominations_cents'] ?? [])->filter(fn ($denomination, $key) => str_starts_with($key, $prefix) && (($count['quantities'][$key] ?? 0) > 0 || ($count['packed_amounts_cents'][$key] ?? 0) > 0)))
            @if ($rows->isNotEmpty())
                <section class="min-w-0 rounded-xl border border-gray-200 p-3 dark:border-gray-700" aria-label="{{ $group === 'Billetes' ? 'Billetes registrados' : 'Monedas registradas' }}">
                    <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold"><x-filament::icon :icon="$group === 'Billetes' ? 'heroicon-o-banknotes' : 'heroicon-o-circle-stack'" class="h-6 w-6 text-primary-500" />{{ $group }}</h3>
                    <table class="w-full text-right text-sm tabular-nums">
                        <thead class="text-xs text-gray-500 dark:text-gray-400"><tr><th class="pb-2 text-left" scope="col">Corte Bs</th><th class="pb-2" scope="col">Piezas sueltas</th><th class="pb-2" scope="col">Valor embalado</th><th class="pb-2" scope="col">Total del corte</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($rows as $key => $denomination)
                                <tr data-count-denomination="{{ $key }}"><th class="py-2 text-left" scope="row">{{ \App\Support\Bolivianos::formatCompact($denomination) }}</th><td class="py-2">{{ $count['quantities'][$key] ?? 0 }}</td><td class="py-2">Bs {{ \App\Support\Bolivianos::formatCompact($count['packed_amounts_cents'][$key] ?? 0) }}</td><td class="py-2 font-semibold">{{ isset($count['row_total_cents'][$key]) ? 'Bs '.\App\Support\Bolivianos::formatCompact($count['row_total_cents'][$key]) : '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        @endforeach
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">Solo se muestran los cortes registrados. Embalados indica su valor total en Bs, no la cantidad de paquetes.</p>
</section>
