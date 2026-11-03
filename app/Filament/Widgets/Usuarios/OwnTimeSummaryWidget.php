<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\ControlHorario\Queries\OwnTimeHistoryQuery;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Support\TimeDuration;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OwnTimeSummaryWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Mi saldo de tiempo';

    protected $listeners = ['own-time-balance-updated' => '$refresh'];

    protected function getDescription(): ?string
    {
        return (app(ScheduleConfiguration::class)->enabled() ? 'Control de Cajas habilitado. ' : 'Controles de Cajas pausados. ').'Incluye pendientes anteriores; el cambio de mes no borra tus saldos. Se muestra en horas y minutos.';
    }

    protected function getStats(): array
    {
        $summary = app(OwnTimeHistoryQuery::class)->summary(auth()->user());

        return [
            Stat::make('Retrasos pendientes', TimeDuration::format($summary['pending']))->description('Por compensar o resolver')->descriptionIcon('heroicon-o-clock')->color('warning'),
            Stat::make('Crédito disponible', TimeDuration::format($summary['available']))->description('Trabajo verificado aún sin utilizar')->descriptionIcon('heroicon-o-sparkles')->color('success'),
            Stat::make('Crédito utilizado', TimeDuration::format($summary['used']))->description('Aplicaciones históricas; las devoluciones figuran abajo')->descriptionIcon('heroicon-o-arrows-right-left')->color('primary'),
        ];
    }
}
