<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Calculators\TimeDiscountCalculator;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyTimeDiscountAction
{
    public function __construct(private TimeDiscountCalculator $calculator) {}

    public function execute(User $actor, TimeIncident $target, int $minutes, string $salaryPeriod, string $receipt, string $key, bool $exception = false): void
    {
        app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar', $target->user_id, $exception, $receipt);
        if ($minutes <= 0 || ! preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $salaryPeriod) || trim($receipt) === '' || trim($key) === '') {
            throw ValidationException::withMessages(['receipt' => 'Registra minutos, periodo y constancia de aplicación salarial real.']);
        }
        DB::transaction(function () use ($actor, $target, $minutes, $salaryPeriod, $receipt, $key): void {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            if (TimeMovement::where('event_key', 'salary:'.$key)->exists()) {
                return;
            }
            $case = TimeIncident::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($case->status !== 'CONFIRMADA' || $minutes > $case->pending_minutes) {
                throw ValidationException::withMessages(['minutes' => 'El descuento no puede superar el tiempo confirmado pendiente.']);
            }
            $cents = $this->calculator->cents($minutes);
            TimeMovement::create(['user_id' => $case->user_id, 'time_incident_id' => $case->id, 'actor_user_id' => $actor->id, 'bucket' => $case->kind === 'RETRASO' ? 'RETRASO' : 'FALTA_REAL', 'delta_minutes' => -$minutes, 'event_key' => 'salary:'.$key, 'metadata' => ['kind' => 'SALARIO_APLICADO', 'salary_period' => $salaryPeriod, 'receipt' => $receipt, 'amount_cents' => $cents, 'rate_cents' => 5000, 'equivalent_minutes' => 360], 'created_at' => now()]);
            $case->pending_minutes -= $minutes;
            $case->save();
            app(TimeHistoryAudit::class)->record($case->user_id, $actor->id, 'DESCUENTO_HORARIO_APLICADO', 'salary:'.$key, ['incident_id' => $case->id, 'minutes' => $minutes, 'amount_cents' => $cents, 'period' => $salaryPeriod, 'receipt' => $receipt]);
        });
    }
}
