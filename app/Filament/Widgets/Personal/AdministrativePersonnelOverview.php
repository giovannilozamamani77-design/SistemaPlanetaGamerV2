<?php

namespace App\Filament\Widgets\Personal;

use App\Domain\ControlHorario\Queries\AdministrativeTimeOverviewQuery;
use App\Domain\Incidencias\Queries\AdministrativeEconomicOverviewQuery;
use App\Support\Bolivianos;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class AdministrativePersonnelOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    #[Locked]
    public string $section = 'jornadas';

    #[On('personnel-record-updated')]
    public function refreshOverview(): void
    {
        $this->cachedStats = null;
    }

    protected function getStats(): array
    {
        $totals = in_array($this->section, ['jornadas', 'retrasos', 'permisos'], true)
            ? app(AdministrativeTimeOverviewQuery::class)->execute(auth()->user(), $this->section)
            : app(AdministrativeEconomicOverviewQuery::class)->execute(auth()->user(), $this->section);
        $titles = match ($this->section) {
            'jornadas' => ['Registros diarios', 'Programados para hoy', 'Próximos registros'],
            'retrasos' => ['Incidencias registradas', 'Por revisar', 'Minutos pendientes'],
            'permisos' => ['Solicitudes registradas', 'Esperando decisión', 'Permisos aprobados'],
            'faltantes' => ['Faltantes registrados', 'Por revisar', 'Deuda pendiente'],
            'reposiciones' => ['Declaraciones globales', 'Por verificar', 'Recepciones verificadas'],
        };

        return [
            Stat::make($titles[0], $totals['total'])->description('Todas las cajeras · historial completo')->icon('heroicon-o-users'),
            Stat::make($titles[1], $totals['queue'])->description($this->section === 'jornadas' ? 'Según la fecha de Bolivia' : 'Gestión administrativa')->color('warning')->icon('heroicon-o-clock'),
            Stat::make($titles[2], $this->section === 'faltantes' ? 'Bs '.Bolivianos::format($totals['third']) : $totals['third'])->description('Los filtros se aplican al listado inferior')->color('primary')->icon('heroicon-o-clipboard-document-check'),
        ];
    }
}
