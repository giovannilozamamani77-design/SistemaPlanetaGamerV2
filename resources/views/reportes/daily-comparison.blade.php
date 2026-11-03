@if ($comparison !== null)
    <div class="mt-2 border-t border-gray-200 pt-2 dark:border-white/10">
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $comparisonLabel }} · {{ $previousLabel }} · Bs {{ \App\Support\Bolivianos::formatCompact($comparison['previous_cents']) }}</p>
        <p @class(['mt-1 flex items-center gap-1 text-sm font-semibold', 'text-success-600 dark:text-success-400' => $comparison['direction'] === 'up', 'text-danger-600 dark:text-danger-400' => $comparison['direction'] === 'down', 'text-gray-500 dark:text-gray-400' => $comparison['direction'] === 'equal'])>
            <x-filament::icon :icon="match ($comparison['direction']) { 'up' => 'heroicon-o-arrow-trending-up', 'down' => 'heroicon-o-arrow-trending-down', default => 'heroicon-o-minus' }" class="h-4 w-4" />
            {{ $comparison['label'] }}
        </p>
    </div>
@else
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Sin comparación: {{ $comparisonLabel === 'Día anterior' ? 'el día anterior' : 'el periodo anterior' }} está fuera del rango admitido.</p>
@endif
