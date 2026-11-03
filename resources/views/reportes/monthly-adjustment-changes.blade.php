<div class="overflow-x-auto"><table class="mb-3 w-full text-left text-sm"><thead><tr><th>Origen / registro</th><th>Cambio</th><th>Antes</th><th>Después</th><th>Δ Ventas</th><th>Δ Cobros</th></tr></thead><tbody>
    @forelse ($changeRows as $change)
        @php($before = $change['before'])
        @php($after = $change['after'])
        <tr class="border-b border-gray-200 dark:border-gray-700">
            <td class="py-2">{{ $sourceLabels[$after['source'] ?? $before['source']] ?? ($after['source'] ?? $before['source']) }}<span class="block text-xs text-gray-500">{{ $change['key'] }}</span></td>
            <td>{{ match ($change['kind']) { 'ADDED' => 'Agregado', 'REMOVED' => 'Ya no incluido', default => 'Modificado' } }}</td>
            @foreach ([$before, $after] as $value)
                <td>@if ($value === null) — @else {{ $value['status'] ?? 'Registrado' }}<span class="block">{{ ($value['collections_cents'] ?? $value['amount_cents'] ?? null) === null ? 'Por revisar' : 'Bs '.\App\Support\Bolivianos::formatCompact($value['collections_cents'] ?? $value['amount_cents']) }}</span>@endif</td>
            @endforeach
            <td>Bs {{ \App\Support\Bolivianos::formatCompact($change['sales_delta_cents']) }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($change['collections_delta_cents']) }}</td>
        </tr>
    @empty<tr><td colspan="6" class="py-4 text-gray-500">Sin cambios pendientes en los registros de origen.</td></tr>@endforelse
</tbody></table></div>{{ $changeRows->links() }}
