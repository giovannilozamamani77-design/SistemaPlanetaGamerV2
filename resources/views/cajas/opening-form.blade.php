<x-filament::section :heading="$workspace['box']" :description="$workspace['first_count'] ? 'Completa al menos el capital configurado. Un sobrante requiere confirmación.' : 'Respeta la entrega anterior y el capital operativo. Un sobrante requiere confirmación.'">
    @include('cajas.previous-handover', ['previous' => $this->previousHandover])
    <div class="space-y-5" x-on:keydown.enter.prevent.stop>
        <h2 class="text-sm font-semibold">Saldos de apertura</h2>
        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:col-span-2">
                @foreach ($workspace['accounts'] as $code => $account)
                    <div class="space-y-2" wire:key="opening-{{ $this->accessId }}-{{ $code }}">
                        <label for="opening-{{ $code }}" class="text-sm font-semibold">{{ $account['name'] }}</label>
                        <div class="flex items-center gap-2">
                            <x-filament::input.wrapper class="min-w-0 flex-1" :prefix-icon="\App\Filament\Support\Cajas\CashBoxComponentIcon::for($code, $account)" prefix="Bs">
                                <x-filament::input id="opening-{{ $code }}" inputmode="decimal" placeholder="0" wire:model.live.debounce.150ms="openingBalances.{{ $code }}" />
                            </x-filament::input.wrapper>
                            @if ($code === 'efectivo')
                                <x-filament::button type="button" size="sm" color="gray" icon="heroicon-o-calculator" wire:click="openCashCount" wire:loading.attr="disabled" wire:target="openCashCount">Realizar arqueo</x-filament::button>
                            @endif
                        </div>
                        @if ($code === 'efectivo')
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $acceptedCashCount ? 'Con arqueo · Puedes volver a revisarlo.' : 'Opcional · También puedes ingresar el efectivo manualmente.' }}</p>
                        @endif
                        @error('openingBalances.'.$code)<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
            <div class="rounded-xl bg-primary-500/10 p-5 ring-1 ring-primary-500/30">
                <h2 class="text-sm font-semibold">Total contado</h2>
                <p class="mt-2 text-sm">Total requerido: <strong>Bs {{ \App\Support\Bolivianos::format($workspace['opening_required_total']) }}</strong></p>
                <p class="mt-2 text-3xl font-bold text-primary-600 dark:text-primary-400" aria-live="polite">
                    {{ $this->openingPreview['valid'] ? 'Bs '.\App\Support\Bolivianos::formatCompact($this->openingPreview['total_cents']) : 'Revisa los importes' }}
                </p>
            </div>
        </div>
        @if ($workspace['opening_reference_below_capital'])<p class="text-sm text-danger-600" role="alert">La entrega anterior está por debajo del capital operativo. Administración debe rectificarla antes de abrir; completarla no es un sobrante.</p>@endif
        @if ($errors->any())
            <p class="text-sm text-danger-600" role="alert">{{ $errors->first() }}</p>
        @endif
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="button" wire:click="confirmOpening" x-on:keydown.enter.prevent.stop :disabled="! $this->openingPreview['valid'] || $this->openingPreview['total_cents'] < $workspace['opening_required_total'] || $workspace['opening_reference_below_capital']" wire:loading.attr="disabled" wire:target="confirmOpening">Abrir mi caja</x-filament::button>
            {{ $this->salirDeVistaAction }}
        </div>
    </div>
</x-filament::section>
