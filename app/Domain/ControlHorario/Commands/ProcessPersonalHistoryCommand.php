<?php

namespace App\Domain\ControlHorario\Commands;

use App\Domain\ControlHorario\Actions\PrepareMonthlyTimeDiscountsAction;
use App\Domain\ControlHorario\Actions\ProcessDueTimeHistoryAction;
use Illuminate\Console\Command;

class ProcessPersonalHistoryCommand extends Command
{
    protected $signature = 'historial:procesar {--month= : Mes calendario cerrado para vencimiento de días libres}';

    protected $description = 'Detecta ausencias y vence días libres sin confirmar sanciones ni condonar deudas';

    public function handle(): int
    {
        app(ProcessDueTimeHistoryAction::class)->execute();
        $month = $this->option('month') ?: now('America/La_Paz')->startOfMonth()->subMonth()->format('Y-m');
        app(PrepareMonthlyTimeDiscountsAction::class)->execute($month);
        $this->info('Historial procesado; las resoluciones y descuentos requieren Administración.');

        return self::SUCCESS;
    }
}
