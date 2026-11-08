<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\RecurringWorkShift;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\ShiftCoverage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthorizeShiftCoverageAction
{
    public function execute(User $actor, array $data): ShiftCoverage
    {
        $admin = User::findOrFail($actor->id);
        Gate::forUser($admin)->authorize('create', ScheduledWorkday::class);
        abort_unless($admin->is_active, 403);
        $v = Validator::make($data, ['source_user_id' => ['required', 'integer', Rule::exists('users', 'id')], 'user_id' => ['required', 'integer', 'different:source_user_id', Rule::exists('users', 'id')],
            'work_date' => ['required', 'date_format:Y-m-d'], 'period' => ['required', Rule::in(['manana', 'tarde'])],
            'starts_time' => ['required', 'date_format:H:i'], 'reason' => ['required', 'string', 'min:3', 'max:2000'], 'request_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($admin, $v): ShiftCoverage {
            $ids = [$v['source_user_id'], $v['user_id']];
            sort($ids);
            User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($old = ShiftCoverage::where('request_key', $v['request_key'])->with('sourceWorkday')->first()) {
                if ($old->created_by !== $admin->id || $old->user_id !== (int) $v['user_id'] || $old->sourceWorkday->user_id !== (int) $v['source_user_id'] || $old->sourceWorkday->period !== $v['period'] || $old->work_date !== $v['work_date'] || $old->starts_at->setTimezone('America/La_Paz')->format('H:i') !== $v['starts_time'] || $old->reason !== $v['reason']) {
                    throw ValidationException::withMessages(['request_key' => 'Este envío ya fue usado con otros datos.']);
                }

                return $old;
            }
            $source = app(PrepareDatedWorkdayAction::class)->execute($admin, User::findOrFail($v['source_user_id']), $v['work_date'], $v['period']);
            $receiver = User::findOrFail($v['user_id']);
            if (! $receiver->is_active || ! $receiver->hasAnyRole(['cajera', 'encargado'])) {
                throw ValidationException::withMessages(['user_id' => 'Selecciona una cajera o encargada activa.']);
            }
            $start = CarbonImmutable::parse($v['work_date'].' '.$v['starts_time'], 'America/La_Paz')->utc();
            if ($start < now('UTC')->toImmutable()->startOfMinute() || $start < $source->starts_at || $start >= $source->ends_at) {
                throw ValidationException::withMessages(['starts_time' => 'El inicio debe estar dentro del turno y no puede ser retroactivo.']);
            }
            if (ShiftCoverage::where('source_workday_id', $source->id)->exists()) {
                throw ValidationException::withMessages(['source_user_id' => 'Este turno ya tiene una cobertura autorizada.']);
            }
            if (ShiftCoverage::where('user_id', $receiver->id)->where('starts_at', '<', $source->ends_at)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'La cajera ya tiene una cobertura que se superpone.']);
            }
            $day = ScheduledWorkday::where('user_id', $receiver->id)->where('work_date', $v['work_date'])->where('period', $v['period'])->first();
            $regular = RecurringWorkShift::where('user_id', $receiver->id)->where('period', $v['period'])->current()->where('enabled', true)->exists();
            if (! $day && $regular) {
                $day = app(PrepareDatedWorkdayAction::class)->execute($admin, $receiver, $v['work_date'], $v['period']);
            }
            $credit = null;
            if (! $day) {
                if (ScheduledWorkday::where('user_id', $receiver->id)->where('starts_at', '<', $source->ends_at)->where('ends_at', '>', $start)->exists()) {
                    throw ValidationException::withMessages(['user_id' => 'Hay un horario excepcional que se superpone; no se modificará su historial.']);
                }
                $day = ScheduledWorkday::create(['user_id' => $receiver->id, 'created_by' => $admin->id, 'work_date' => $v['work_date'], 'period' => $v['period'], 'starts_at' => $start, 'ends_at' => $source->ends_at]);
                if ($v['period'] === 'manana' && RecurringWorkShift::where('user_id', $receiver->id)->where('period', 'tarde')->current()->where('enabled', true)->exists()) {
                    $credit = app(PrepareDatedWorkdayAction::class)->execute($admin, $receiver, $v['work_date'], 'tarde');
                    if (! $source->ends_at->equalTo($credit->starts_at)) {
                        throw ValidationException::withMessages(['period' => 'El horario cubierto no es contiguo al de tarde. Revisa la configuración.']);
                    }
                }
            } elseif ($day->starts_at > $start || $day->ends_at < $source->ends_at) {
                throw ValidationException::withMessages(['user_id' => 'El turno existente no cubre ese intervalo y no se reescribirá.']);
            }
            if (ShiftCoverage::where('workday_id', $day->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Ese turno de la sustituta ya está relacionado con otra cobertura.']);
            }
            $coverage = ShiftCoverage::create(['source_workday_id' => $source->id, 'workday_id' => $day->id, 'credit_workday_id' => $credit?->id, 'user_id' => $receiver->id, 'created_by' => $admin->id, 'work_date' => $v['work_date'], 'starts_at' => $start, 'ends_at' => $source->ends_at, 'reason' => $v['reason'], 'request_key' => $v['request_key']]);
            DB::table('workday_audits')->insert(['actor_user_id' => $admin->id, 'scheduled_workday_id' => $day->id, 'action' => 'coverage_authorized', 'changes' => json_encode(['coverage_id' => $coverage->id, 'source_workday_id' => $source->id, 'credit_workday_id' => $credit?->id, 'reason' => $v['reason']], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $coverage;
        });
    }
}
