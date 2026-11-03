<?php

namespace App\Domain\ControlHorario\Commands;

use App\Domain\ControlHorario\Actions\ProcessCashBoxContinuityAction;
use App\Domain\ControlHorario\Actions\ProcessCashBoxScheduleEventAction;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\CashBoxWorkdayContinuity;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use Illuminate\Console\Command;

class RetryCashBoxScheduleEventsCommand extends Command
{
    protected $signature = 'horario:reintentar-aperturas {--limit=100 : Maximo de eventos por ejecucion}';

    protected $description = 'Reintenta solo hechos horarios persistidos, sin abrir cajas ni modificar saldos.';

    public function handle(ProcessCashBoxScheduleEventAction $processor): int
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            $this->info('Controles de Cajas pausados. No se procesaron eventos.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('El limite debe estar entre 1 y 1000.');

            return self::FAILURE;
        }
        $ids = CashBoxScheduleEvent::whereIn('status', ['PENDING', 'FAILED'])->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $processor->safely($id);
        }
        $continuities = CashBoxWorkdayContinuity::whereIn('status', ['PENDING', 'FAILED'])->orderBy('id')->limit(max(0, $limit - $ids->count()))->pluck('id');
        foreach ($continuities as $id) {
            app(ProcessCashBoxContinuityAction::class)->safely($id);
        }
        $remaining = CashBoxScheduleEvent::whereIn('status', ['PENDING', 'FAILED'])->count() + CashBoxWorkdayContinuity::whereIn('status', ['PENDING', 'FAILED'])->count();
        $this->info('Eventos revisados: '.($ids->count() + $continuities->count()).'. Pendientes: '.$remaining.'.');

        return $remaining ? self::FAILURE : self::SUCCESS;
    }
}
