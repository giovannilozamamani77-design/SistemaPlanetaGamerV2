@if ($previous)
    <details class="mb-5 rounded-xl border border-gray-200 dark:border-gray-700" wire:ignore.self>
        <summary class="flex cursor-pointer items-center gap-2 p-4 text-sm font-semibold"><x-filament::icon icon="heroicon-o-eye" class="h-5 w-5 text-primary-500" />Ver registro anterior<span class="ml-auto text-xs font-normal text-gray-500 dark:text-gray-400">Solo lectura</span></summary>
        <div class="space-y-4 border-t border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs text-gray-500 dark:text-gray-400">Saldos entregados en el cierre anterior. Estos datos no cambian al editar tu apertura.</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach ($workspace['accounts'] as $code => $account)
                    <div class="space-y-2 rounded-lg bg-gray-500/5 p-3">
                        <p class="flex items-center gap-2 text-sm"><x-filament::icon :icon="\App\Filament\Support\Cajas\CashBoxComponentIcon::for($code, $account)" class="h-7 w-7" />{{ $account['name'] }}</p>
                        <strong class="text-lg">Bs {{ \App\Support\Bolivianos::formatCompact($previous['balances'][$code] ?? 0) }}</strong>
                        @if ($code === 'efectivo')<p class="text-xs text-gray-500 dark:text-gray-400">{{ is_array($previous['cash_count']) && isset($previous['cash_count']['total_cents']) ? 'Arqueo disponible' : 'No se tiene arqueo.' }}</p>@endif
                    </div>
                @endforeach
            </div>
            @if (is_array($previous['cash_count']) && isset($previous['cash_count']['total_cents']))
                <details class="rounded-xl border border-primary-500/25 bg-primary-500/5" wire:ignore.self>
                    <summary class="flex cursor-pointer items-center gap-2 p-3 text-sm font-semibold text-primary-600 dark:text-primary-400"><x-filament::icon icon="heroicon-o-calculator" class="h-5 w-5" />Ver arqueo<span class="ml-auto text-xs">Detalle del efectivo contado</span></summary>
                    <div class="px-3 pb-3">@include('cajas.previous-cash-count', ['count' => $previous['cash_count']])</div>
                </details>
            @endif
        </div>
    </details>
@endif
