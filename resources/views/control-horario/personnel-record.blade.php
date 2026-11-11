<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $employee->name }}</x-slot>
        <x-slot name="description">{{ $employee->getRoleNames()->join(' · ') }} · {{ $employee->is_active ? 'Cuenta activa' : 'Cuenta inactiva' }} · Día de pago: {{ $employee->salary_pay_day ?? 'sin configurar' }}</x-slot>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div><p>Tiempo pendiente</p><strong>{{ \App\Support\TimeDuration::format($time['pending']) }}</strong></div>
            <div><p>Crédito disponible</p><strong>{{ \App\Support\TimeDuration::format($credit) }}</strong></div>
            <div><p>Faltantes confirmados pendientes</p><strong>Bs {{ \App\Support\Bolivianos::format($money['pending']) }}</strong></div>
            <div><p>Faltantes en revisión</p><strong>Bs {{ \App\Support\Bolivianos::format($money['under_review']) }}</strong></div>
        </div>
    </x-filament::section>
    <div class="flex flex-wrap gap-2">
        @foreach ($this->sections() as $key => $label)
            <x-filament::button wire:click="selectSection('{{ $key }}')" :color="$section === $key ? 'primary' : 'gray'" wire:loading.attr="disabled">{{ $label }}</x-filament::button>
        @endforeach
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400">Selecciona un registro para ver su detalle o resolverlo. Cada decisión conserva el historial; las faltas se muestran con la clasificación existente del sistema.</p>
    <div wire:key="personnel-section-{{ $section }}">{{ $this->table }}</div>
</x-filament-panels::page>
