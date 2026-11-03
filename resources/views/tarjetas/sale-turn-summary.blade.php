<section class="mb-4" aria-label="Resumen del turno abierto">
    <h3 class="font-semibold">Resumen del turno abierto</h3>
    @if ($turnCompanySummary)
        <p class="text-sm text-gray-500">{{ $turnCompanySummary['responsible'] }} · Todo el turno, independiente de los filtros del historial.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left"><th class="p-2">Empresa</th><th class="p-2">Tarjetas vendidas</th><th class="p-2">Cobrado por tarjetas</th><th class="p-2">Total cobrado</th></tr></thead>
                <tbody>
                @foreach ($turnCompanySummary['companies'] as $company => $row)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <th class="p-2 text-left">{{ \App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES[$company] ?? 'Sin empresa registrada' }}</th>
                        <td class="p-2">{{ $row['quantity'] }}</td>
                        <td class="p-2">{{ \App\Support\Bolivianos::format($row['total_cents']) }}</td>
                        <td class="p-2 font-semibold">{{ \App\Support\Bolivianos::format($row['collected_cents']) }}
                            @if ($company === 'ENTEL')
                                <span class="block text-xs font-normal">Crédito: {{ \App\Support\Bolivianos::format($row['credit_cents']) }} · Paquetes: {{ \App\Support\Bolivianos::format($row['packages_cents']) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-sm text-gray-500">Sin turno abierto. El historial conserva los registros anteriores.</p>
    @endif
</section>
