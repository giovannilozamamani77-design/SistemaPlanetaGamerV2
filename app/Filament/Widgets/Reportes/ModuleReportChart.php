<?php

namespace App\Filament\Widgets\Reportes;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

class ModuleReportChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    #[Locked]
    public array $chartData = [];

    #[Locked]
    public string $chartType = 'line';

    public function boot(): void
    {
        Gate::authorize('viewDailyReports');
    }

    public static function canView(): bool
    {
        return auth()->check() && Gate::allows('viewDailyReports');
    }

    public function getHeading(): string
    {
        return $this->chartType === 'doughnut' ? 'Distribución de cobros' : 'Evolución diaria del mes';
    }

    protected function getType(): string
    {
        return $this->chartType;
    }

    protected function getData(): array
    {
        Gate::authorize('viewDailyReports');

        return $this->chartData;
    }

    protected function getOptions(): array
    {
        return ['maintainAspectRatio' => false, 'plugins' => ['legend' => ['position' => 'bottom']]];
    }
}
