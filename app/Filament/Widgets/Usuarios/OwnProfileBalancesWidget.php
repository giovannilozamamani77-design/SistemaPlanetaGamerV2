<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\Comisiones\Queries\OwnCommissionsQuery;
use App\Domain\ControlHorario\Queries\OwnTimeHistoryQuery;
use App\Domain\Incidencias\Queries\PersonnelBalancesQuery;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Models\User;
use App\Support\Bolivianos;
use App\Support\TimeDuration;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class OwnProfileBalancesWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected $listeners = ['own-time-balance-updated' => '$refresh', 'personnel-record-updated' => '$refresh', 'commission-payments-updated' => '$refresh'];

    protected function getColumns(): int|array|null
    {
        $count = max(1, count($this->getCachedStats()));

        return ['default' => 1, 'md' => min(2, $count), 'xl' => min(4, $count)];
    }

    protected function getStats(): array
    {
        $user = User::findOrFail(auth()->id());
        Gate::forUser($user)->authorize('perfil.ver');
        $stats = [];
        if ($user->can('incidencias.ver_propios')) {
            $money = app(PersonnelBalancesQuery::class)->execute($user);
            $stats[] = Stat::make('Faltantes pendientes', 'Bs '.Bolivianos::format($money['pending']))->description('Acumulado por reponer; separado del tiempo')->icon('heroicon-o-banknotes')->color('danger');
        }
        $commissions = app(OwnCommissionsQuery::class)->overview($user);
        $stats[] = Stat::make('Comisiones pendientes', 'Bs '.Bolivianos::formatCompact($commissions['pending_cents']))->description('Ganado: Bs '.Bolivianos::formatCompact($commissions['total_cents']).' · Pagado: Bs '.Bolivianos::formatCompact($commissions['paid_cents']))->icon('heroicon-o-wallet')->color('success')->url(EditOwnProfile::getUrl(panel: 'inicio').'?profileTab=profile-commercial-commissions');
        if ($user->can('controlhorario.ver_propios')) {
            $time = app(OwnTimeHistoryQuery::class)->overview($user);
            $stats[] = Stat::make('Tiempo acumulado', TimeDuration::format($time['total']))->description('Apertura: '.TimeDuration::format($time['opening']).' · Salida anticipada: '.TimeDuration::format($time['early']))->icon('heroicon-o-clock')->color('warning');
            $stats[] = Stat::make('Crédito por apertura temprana', TimeDuration::format($time['available']))->description('Tiempo de trabajo temprano verificado, disponible para compensar')->icon('heroicon-o-sparkles')->color('success');
        }

        return $stats;
    }
}
