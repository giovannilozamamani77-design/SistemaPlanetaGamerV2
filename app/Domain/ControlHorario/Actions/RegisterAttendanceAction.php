<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\Attendance;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SensitiveParameter;

class RegisterAttendanceAction
{
    public function __construct(private VerifyPersonalPinAction $verifyPin) {}

    public function execute(User $actor, #[SensitiveParameter] string $pin): ?Attendance
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('controlhorario.asistencia.registrar');
        $this->verifyPin->execute($user, $pin);
        $instant = Date::now('UTC')->toImmutable();
        $date = $instant->setTimezone('America/La_Paz')->format('Y-m-d');

        return DB::transaction(function () use ($user, $instant, $date): ?Attendance {
            $currentUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($currentUser)->authorize('controlhorario.asistencia.registrar');
            app(EnsureRecurringWorkdaysAction::class)->execute($currentUser, $instant);
            $workday = ScheduledWorkday::where('user_id', $user->id)->where('work_date', $date)
                ->where('ends_at', '>', $instant)->orderBy('starts_at')->orderBy('id')->lockForUpdate()->first();
            if (! $workday) {
                return null;
            }
            $attendance = Attendance::firstOrCreate(['scheduled_workday_id' => $workday->id], [
                'first_pin_at' => $instant, 'status' => 'PRESENTE_SIN_APERTURA',
            ]);
            if ($attendance->wasRecentlyCreated) {
                DB::table('workday_audits')->insert([
                    'actor_user_id' => $user->id, 'scheduled_workday_id' => $workday->id, 'action' => 'attendance_registered',
                    'changes' => json_encode(['first_pin_at' => $instant->toIso8601String(), 'status' => $attendance->status], JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
            }

            return $attendance;
        });
    }
}
