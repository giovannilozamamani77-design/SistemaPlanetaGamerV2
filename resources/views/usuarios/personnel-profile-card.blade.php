@php($employee = $getRecord())
<article class="pg-personnel-profile" aria-label="Perfil de {{ $employee->name }}">
    <div class="pg-profile-cover"><span>PLANETA GAMER</span></div>
    <div class="pg-profile-identity">
        <div class="pg-profile-avatar"><span>{{ \Illuminate\Support\Str::of($employee->name)->trim()->substr(0, 2)->upper() }}</span></div>
        <div class="pg-profile-name">
            <span class="pg-profile-eyebrow">{{ $employee->getRoleNames()->join(' · ') }}</span>
            <h2>{{ $employee->name }}</h2>
            <p>{{ $employee->username ? '@'.$employee->username : 'Sin nombre de usuario' }}</p>
        </div>
        <x-filament::badge :color="$employee->is_active ? 'success' : 'gray'">{{ $employee->is_active ? 'Activa' : 'Inactiva' }}</x-filament::badge>
    </div>
    <dl class="pg-personnel-profile-metrics">
        <div><dt>Tiempo pendiente</dt><dd>{{ $pendingTime }}</dd></div>
        <div><dt>Crédito de tiempo</dt><dd>{{ \App\Support\TimeDuration::format((int) $employee->available_credit) }}</dd></div>
        <div><dt>Faltantes pendientes</dt><dd>Bs {{ \App\Support\Bolivianos::format($money['pending']) }}</dd></div>
        <div><dt>En revisión</dt><dd>Bs {{ \App\Support\Bolivianos::format($money['under_review']) }}</dd></div>
    </dl>
    <div class="pg-profile-footer">
        <span>{{ $employee->today_workdays ? 'Jornada de hoy asignada' : 'Sin jornada registrada' }}</span>
        <span>Día de pago: {{ $employee->salary_pay_day ?? 'sin configurar' }}</span>
    </div>
</article>
