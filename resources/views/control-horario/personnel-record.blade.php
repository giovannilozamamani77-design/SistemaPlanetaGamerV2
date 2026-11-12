<x-filament-panels::page>
    <section class="pg-admin-profile-header" aria-label="Perfil de {{ $employee->name }}">
        <div class="pg-profile-cover"><span>PLANETA GAMER</span></div>
        <div class="pg-profile-identity">
            <div class="pg-profile-avatar"><span>{{ \Illuminate\Support\Str::of($employee->name)->trim()->substr(0, 2)->upper() }}</span></div>
            <div class="pg-profile-name">
                <span class="pg-profile-eyebrow">EXPEDIENTE DEL PERSONAL</span>
                <h2>{{ $employee->name }}</h2>
                <p>{{ $employee->username ? '@'.$employee->username.' · ' : '' }}{{ $employee->getRoleNames()->join(' · ') }}</p>
            </div>
            <x-filament::badge :color="$employee->is_active ? 'success' : 'gray'">{{ $employee->is_active ? 'Cuenta activa' : 'Cuenta inactiva' }}</x-filament::badge>
        </div>
        <div class="pg-profile-footer">
            <span>Historial y resoluciones administrativas</span>
            <span>Día de pago: {{ $employee->salary_pay_day ?? 'sin configurar' }}</span>
        </div>
    </section>
    <x-filament::section>
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
