<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\RecurringWorkShift;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveRecurringWorkShiftAction
{
    public function execute(User $actor, array $data): RecurringWorkShift
    {
        $admin = User::findOrFail($actor->id);
        Gate::forUser($admin)->authorize('create', ScheduledWorkday::class);
        abort_unless($admin->is_active, 403);
        $v = Validator::make($data, ['user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'period' => ['required', Rule::in(['manana', 'tarde'])], 'enabled' => ['required', 'boolean'],
            'expected_version_id' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($admin, $v): RecurringWorkShift {
            $user = User::whereKey($v['user_id'])->lockForUpdate()->firstOrFail();
            if (! $user->is_active || ! $user->hasAnyRole(['cajera', 'encargado'])) {
                throw ValidationException::withMessages(['user_id' => 'Selecciona una cajera o encargada activa.']);
            }
            if ($existing = RecurringWorkShift::where('request_key', $v['request_key'])->first()) {
                if ($existing->created_by !== $admin->id || $existing->user_id !== $user->id || $existing->period !== $v['period'] || $existing->enabled !== (bool) $v['enabled']) {
                    throw ValidationException::withMessages(['request_key' => 'Este envío ya fue usado con otros datos.']);
                }

                return $existing;
            }
            $latest = RecurringWorkShift::where('user_id', $user->id)->where('period', $v['period'])->latest('revision')->first();
            if ((int) $v['expected_version_id'] !== ($latest?->id ?? 0)) {
                throw ValidationException::withMessages(['period' => 'El turno cambió. Actualiza la lista antes de guardar.']);
            }

            return RecurringWorkShift::create(['user_id' => $user->id, 'period' => $v['period'], 'revision' => ($latest?->revision ?? 0) + 1,
                'enabled' => (bool) $v['enabled'], 'created_by' => $admin->id, 'request_key' => $v['request_key']]);
        });
    }
}
