<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="font-semibold">{{ $detail['metric_label'] }} · {{ $detail['label'] }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $detail['period_label'] }} · Hora de Bolivia</p>
        </div>
        <div class="text-right">
            <p class="text-xs text-gray-500 dark:text-gray-400">Total de todos los registros filtrados</p>
            <p class="text-xl font-bold text-primary-600 dark:text-primary-400">Bs {{ \App\Support\Bolivianos::formatCompact($detail['total_cents']) }}</p>
        </div>
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">Importes confirmados del resumen. Las ventas mixtas se separan por tipo; sus partes no vuelven a sumar el ticket completo. Cambiar de página no modifica el total.</p>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                <tr><th class="py-2 pr-3">Fecha y hora</th><th class="px-3 py-2">Origen y referencia</th><th class="px-3 py-2">Responsable</th><th class="px-3 py-2">Detalle</th><th class="py-2 pl-3 text-right">Importe</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($detail['records'] as $record)
                    <tr wire:key="daily-record-{{ $record['key'] }}">
                        <td class="whitespace-nowrap py-3 pr-3">{{ $record['recorded_at'] }}</td>
                        <td class="px-3 py-3"><p class="font-medium">{{ $record['label'] }}</p><p class="text-xs text-gray-500 dark:text-gray-400">{{ $record['reference'] }}</p></td>
                        <td class="px-3 py-3">{{ $record['actor'] }}</td>
                        <td class="px-3 py-3">
                            @if ($record['items'])
                                <ul class="space-y-1">
                                    @foreach ($record['items'] as $item)
                                        <li>{{ $item['label'] }} × {{ $item['quantity'] }} · Bs {{ \App\Support\Bolivianos::formatCompact($item['amount_cents']) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $record['description'] }}</p>
                        </td>
                        <td class="whitespace-nowrap py-3 pl-3 text-right font-semibold">Bs {{ \App\Support\Bolivianos::formatCompact($record['amount_cents']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-500 dark:text-gray-400">{{ $detail['date'] === $detail['until_date'] ? 'Sin registros para este día y origen.' : 'Sin registros para este periodo y origen.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @error('detailPerPage') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
    <x-filament::pagination :paginator="$detail['records']" :page-options="[5, 10, 25]" current-page-option-property="detailPerPage" />
</div>
