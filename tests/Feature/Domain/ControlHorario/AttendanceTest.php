<?php

use App\Domain\ControlHorario\Actions\EnsureRecurringWorkdaysAction;
use App\Domain\ControlHorario\Actions\RegisterAttendanceAction;
use App\Domain\ControlHorario\Actions\SaveRecurringWorkShiftAction;
use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Models\Attendance;
use App\Domain\ControlHorario\Models\RecurringWorkShift;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\Usuarios\Actions\SetUserPinAction;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Filament\Pages\ControlHorario\AssignedShifts;
use App\Filament\Pages\ControlHorario\MiAsistencia;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\Pages\CreateScheduledWorkday;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\ScheduledWorkdayResource;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function attendancePeople(): array
{
    app(UsuariosPermissionsSeeder::class)->run();
    app(ControlHorarioPermissionsSeeder::class)->run();
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    app(SetUserPinAction::class)->execute($admin, $cashier, '1234');

    return [$admin, $cashier];
}
function attendanceSchedule(User $admin, User $cashier, array $change = []): ScheduledWorkday
{
    return app(SaveScheduledWorkdayAction::class)->execute($admin, array_replace([
        'user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00',
    ], $change));
}
it('supports separate morning and afternoon assignments with correct Bolivia timestamps', function () {
    [$admin,$cashier] = attendancePeople();
    $morning = attendanceSchedule($admin, $cashier);
    $afternoon = attendanceSchedule($admin, $cashier, ['period' => 'tarde', 'starts_time' => '14:00', 'ends_time' => '20:00']);
    expect($morning->starts_at->format('Y-m-d H:i'))->toBe('2026-10-14 12:00');
    expect($afternoon->ends_at->format('Y-m-d H:i'))->toBe('2026-10-15 00:00');
    expect(ScheduledWorkday::count())->toBe(2);
});
it('keeps the first PIN timestamp when repeated within one workday', function () {
    [$admin,$cashier] = attendancePeople();
    attendanceSchedule($admin, $cashier);
    $this->travelTo(Carbon::parse('2026-10-14 12:05:00', 'UTC'));
    $first = app(RegisterAttendanceAction::class)->execute($cashier, '1234');
    $this->travel(15)->minutes();
    $second = app(RegisterAttendanceAction::class)->execute($cashier, '1234');
    expect($second->id)->toBe($first->id);
    expect($second->first_pin_at->format('H:i'))->toBe('12:05');
    expect($second->status)->toBe('PRESENTE_SIN_APERTURA');
    expect(Attendance::count())->toBe(1);
    expect(DB::table('workday_audits')->where('action', 'attendance_registered')->count())->toBe(1);
});
it('associates subsequent PIN validations to afternoon at the exact shift boundary', function () {
    [$admin,$cashier] = attendancePeople();
    $morning = attendanceSchedule($admin, $cashier);
    $afternoon = attendanceSchedule($admin, $cashier, ['period' => 'tarde', 'starts_time' => '14:00', 'ends_time' => '20:00']);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
    expect(app(RegisterAttendanceAction::class)->execute($cashier, '1234')->scheduled_workday_id)->toBe($morning->id);
    $this->travelTo(Carbon::parse('2026-10-14 18:00:00', 'UTC'));
    expect(app(RegisterAttendanceAction::class)->execute($cashier, '1234')->scheduled_workday_id)->toBe($afternoon->id);
    expect(Attendance::count())->toBe(2);
});
it('records early arrival for the next assigned workday without manufacturing lateness', function () {
    [$admin,$cashier] = attendancePeople();
    $afternoon = attendanceSchedule($admin, $cashier, ['period' => 'tarde', 'starts_time' => '14:00', 'ends_time' => '20:00']);
    $this->travelTo(Carbon::parse('2026-10-14 15:00:00', 'UTC'));
    $attendance = app(RegisterAttendanceAction::class)->execute($cashier, '1234');
    expect($attendance->scheduled_workday_id)->toBe($afternoon->id);
    expect($attendance->first_pin_at->setTimezone('America/La_Paz')->format('H:i'))->toBe('11:00');
});
it('uses the local work date even after midnight UTC', function () {
    [$admin,$cashier] = attendancePeople();
    $workday = attendanceSchedule($admin, $cashier, ['period' => 'tarde', 'starts_time' => '14:00', 'ends_time' => '21:00']);
    $this->travelTo(Carbon::parse('2026-10-15 00:30:00', 'UTC'));
    expect(app(RegisterAttendanceAction::class)->execute($cashier, '1234')->scheduled_workday_id)->toBe($workday->id);
});
it('validates PIN without creating a workday or absence when nothing is assigned', function () {
    [, $cashier] = attendancePeople();
    $this->travelTo(Carbon::parse('2026-10-14 15:00:00', 'UTC'));
    expect(app(RegisterAttendanceAction::class)->execute($cashier, '1234'))->toBeNull();
    expect(Attendance::count())->toBe(0);
    expect(ScheduledWorkday::count())->toBe(0);
});
it('rejects invalid PIN without recording attendance', function () {
    [$admin,$cashier] = attendancePeople();
    attendanceSchedule($admin, $cashier);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
    expect(fn () => app(RegisterAttendanceAction::class)->execute($cashier, '0000'))->toThrow(ValidationException::class);
    expect(Attendance::count())->toBe(0);
});
it('rejects duplicate assignments and overlapping intervals', function (array $change) {
    [$admin,$cashier] = attendancePeople();
    attendanceSchedule($admin, $cashier);
    expect(fn () => attendanceSchedule($admin, $cashier, $change))->toThrow(ValidationException::class);
    expect(ScheduledWorkday::count())->toBe(1);
})->with([
    'duplicate' => [[]],
    'overlap' => [['period' => 'tarde', 'starts_time' => '13:00', 'ends_time' => '20:00']],
    'inverted' => [['period' => 'tarde', 'starts_time' => '20:00', 'ends_time' => '14:00']],
]);
it('does not permit editing historical workdays with attendance', function () {
    [$admin,$cashier] = attendancePeople();
    $workday = attendanceSchedule($admin, $cashier);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
    app(RegisterAttendanceAction::class)->execute($cashier, '1234');
    expect(fn () => app(SaveScheduledWorkdayAction::class)->execute($admin, [
        'user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '09:00', 'ends_time' => '14:00',
    ], $workday))->toThrow(ValidationException::class);
    expect($workday->fresh()->starts_at->format('H:i'))->toBe('12:00');
});
it('denies scheduling to operational roles even with a direct grant', function (string $role) {
    [$admin,$cashier] = attendancePeople();
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $actor->givePermissionTo('controlhorario.programar');
    expect(fn () => attendanceSchedule($actor, $cashier))->toThrow(AuthorizationException::class);
})->with(['cajera', 'encargado']);
it('rolls back attendance when its domain audit cannot be stored', function () {
    [$admin,$cashier] = attendancePeople();
    attendanceSchedule($admin, $cashier);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
    DB::unprepared("CREATE TRIGGER reject_attendance_audit BEFORE INSERT ON workday_audits BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    expect(fn () => app(RegisterAttendanceAction::class)->execute($cashier, '1234'))->toThrow(QueryException::class);
    expect(Attendance::count())->toBe(0);
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe(1);
});
it('enforces unique attendance at database level', function () {
    [$admin,$cashier] = attendancePeople();
    attendanceSchedule($admin, $cashier);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
    $attendance = app(RegisterAttendanceAction::class)->execute($cashier, '1234');
    expect(fn () => DB::table('attendances')->insert(['scheduled_workday_id' => $attendance->scheduled_workday_id, 'first_pin_at' => now(), 'status' => 'PRESENTE_SIN_APERTURA']))->toThrow(QueryException::class);
});
it('shows only owned workdays and allows PIN submission through Filament', function () {
    [$admin,$cashier] = attendancePeople();
    $own = attendanceSchedule($admin, $cashier);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $foreign = attendanceSchedule($admin, $other);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($cashier);
    Livewire::test(MiAsistencia::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign])
        ->callAction('registrarLlegada', data: ['pin' => '1234'])->assertHasNoActionErrors();
    expect(Attendance::count())->toBe(1);
});
it('creates planned workdays from the administrative resource and denies its direct url to cashiers', function () {
    [$admin,$cashier] = attendancePeople();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(CreateScheduledWorkday::class)->fillForm([
        'user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'tarde', 'starts_time' => '14:00', 'ends_time' => '20:00',
    ])->call('create')->assertHasNoFormErrors();
    expect(ScheduledWorkday::count())->toBe(1);
});
it('denies the administrative workday url to cashiers', function () {
    [, $cashier] = attendancePeople();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($cashier)->get(ScheduledWorkdayResource::getUrl())->assertForbidden();
});

it('allows audited edits only before a workday starts', function () {
    [$admin,$cashier] = attendancePeople();
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'UTC'));
    $workday = attendanceSchedule($admin, $cashier);
    $changed = app(SaveScheduledWorkdayAction::class)->execute($admin, [
        'user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:30', 'ends_time' => '14:00',
    ], $workday);
    expect($changed->starts_at->format('H:i'))->toBe('12:30');
    expect(DB::table('workday_audits')->where('action', 'rescheduled')->count())->toBe(1);
});
it('blocks attendance when the account is disabled immediately after PIN verification', function () {
    [$admin,$cashier] = attendancePeople();
    attendanceSchedule($admin, $cashier);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
    $verifier = new class extends VerifyPersonalPinAction
    {
        public function execute(User $actor, string $pin): void
        {
            parent::execute($actor, $pin);
            DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        }
    };
    expect(fn () => (new RegisterAttendanceAction($verifier))->execute($cashier, '1234'))->toThrow(AuthorizationException::class);
    expect(Attendance::count())->toBe(0);
});
it('rejects inactive staff and unsupported workday roles', function (string $kind) {
    [$admin,$cashier] = attendancePeople();
    if ($kind === 'inactive') {
        $cashier->is_active = false;
        $cashier->save();
    } else {
        $cashier->syncRoles(['administrador']);
    }
    expect(fn () => attendanceSchedule($admin, $cashier))->toThrow(ValidationException::class);
    expect(ScheduledWorkday::count())->toBe(0);
})->with(['inactive', 'administrador']);
it('does not carry a PIN registration into a future day or finished workday', function () {
    [$admin,$cashier] = attendancePeople();
    attendanceSchedule($admin, $cashier);
    attendanceSchedule($admin, $cashier, ['work_date' => '2026-10-15']);
    $this->travelTo(Carbon::parse('2026-10-14 23:00:00', 'UTC'));
    expect(app(RegisterAttendanceAction::class)->execute($cashier, '1234'))->toBeNull();
    expect(Attendance::count())->toBe(0);
});

it('C133 repeats an assigned shift Monday to Saturday without manual dates or Sunday workdays', function (string $role) {
    [$admin, $cashier] = attendancePeople();
    $cashier->syncRoles([$role]);
    config(['cajas.schedule_controls_enabled' => true]);
    $this->travelTo(Carbon::parse('2026-10-19 11:00:00', 'UTC'));
    app(SaveRecurringWorkShiftAction::class)->execute($admin, [
        'user_id' => $cashier->id, 'period' => 'manana', 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid(),
    ]);
    foreach (['2026-10-19', '2026-10-20', '2026-10-24', '2026-10-26'] as $date) {
        $this->travelTo(Carbon::parse($date.' 12:00:00', 'UTC'));
        $first = app(RegisterAttendanceAction::class)->execute($cashier, '1234');
        expect($first)->not->toBeNull();
        expect(app(RegisterAttendanceAction::class)->execute($cashier, '1234')->id)->toBe($first->id);
    }
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    expect(app(RegisterAttendanceAction::class)->execute($cashier, '1234'))->toBeNull();
    expect(ScheduledWorkday::count())->toBe(4)->and(Attendance::count())->toBe(4);
})->with(['cajera', 'encargado']);

it('C133 preserves an existing dated exception and does not generate past days or work while paused', function () {
    [$admin, $cashier] = attendancePeople();
    config(['cajas.schedule_controls_enabled' => true]);
    $this->travelTo(Carbon::parse('2026-10-19 11:00:00', 'UTC'));
    $exception = attendanceSchedule($admin, $cashier, ['work_date' => '2026-10-19', 'starts_time' => '09:00']);
    $save = app(SaveRecurringWorkShiftAction::class);
    $data = ['user_id' => $cashier->id, 'period' => 'manana', 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()];
    $version = $save->execute($admin, $data);
    expect($save->execute($admin, $data)->id)->toBe($version->id);
    $ensure = app(EnsureRecurringWorkdaysAction::class);
    $ensure->execute($cashier, now()->toImmutable());
    expect(ScheduledWorkday::count())->toBe(1)->and($exception->fresh()->starts_at->format('H:i'))->toBe('13:00');
    $ensure->execute($cashier, CarbonImmutable::parse('2026-10-18 12:00:00', 'UTC'));
    expect(ScheduledWorkday::count())->toBe(1);
    config(['cajas.schedule_controls_enabled' => false]);
    $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
    $ensure->execute($cashier, now()->toImmutable());
    expect(ScheduledWorkday::count())->toBe(1);
    expect(fn () => $save->execute($cashier, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect(fn () => $save->execute($admin, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
});

it('C133 shows recurring assignments without a date field and protects both direct access and history', function () {
    [$admin,$cashier] = attendancePeople();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(AssignedShifts::class)
        ->assertSee('lunes a sábado')->mountAction('assignShift')->assertActionMounted('assignShift')
        ->assertMountedActionModalDontSee('Fecha del turno')
        ->setActionData(['user_id' => $cashier->id, 'period' => 'manana'])
        ->callMountedAction()->assertHasNoActionErrors();
    expect(RecurringWorkShift::count())->toBe(1)->and(ScheduledWorkday::count())->toBe(0);
    $record = RecurringWorkShift::first();
    Livewire::test(AssignedShifts::class)->callAction(TestAction::make('toggleShift')->table($record))->assertHasNoActionErrors();
    expect(RecurringWorkShift::count())->toBe(2)
        ->and(RecurringWorkShift::current()->first()->enabled)->toBeFalse()
        ->and($record->fresh()->enabled)->toBeTrue();
    $this->actingAs($cashier)->get(AssignedShifts::getUrl())->assertForbidden();
});

it('C133 does not precreate afternoon shifts and preserves continuity without asking for another PIN', function () {
    [$admin,$cashier] = attendancePeople();
    config(['cajas.schedule_controls_enabled' => true]);
    $this->travelTo(Carbon::parse('2026-10-19 11:00:00', 'UTC'));
    foreach (['manana', 'tarde'] as $period) {
        app(SaveRecurringWorkShiftAction::class)->execute($admin, ['user_id' => $cashier->id, 'period' => $period, 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()]);
    }
    $ensure = app(EnsureRecurringWorkdaysAction::class);
    $ensure->execute($cashier, now()->toImmutable());
    expect(ScheduledWorkday::count())->toBe(1)->and(ScheduledWorkday::first()->period)->toBe('manana');
    $this->travelTo(Carbon::parse('2026-10-19 18:00:00', 'UTC'));
    $ensure->execute($cashier, now()->toImmutable());
    $ensure->execute($cashier, now()->toImmutable());
    expect(ScheduledWorkday::count())->toBe(2)->and(Attendance::count())->toBe(0);
});

it('C133 prepares only due weekdays for absences and never invents a prior partial shift', function () {
    [$admin,$cashier] = attendancePeople();
    config(['cajas.schedule_controls_enabled' => true]);
    $this->travelTo(Carbon::parse('2026-10-19 13:00:00', 'UTC')); // Monday09:00: shift already started.
    app(SaveRecurringWorkShiftAction::class)->execute($admin, ['user_id' => $cashier->id, 'period' => 'manana', 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()]);
    $ensure = app(EnsureRecurringWorkdaysAction::class);
    $this->travelTo(Carbon::parse('2026-10-19 19:00:00', 'UTC'));
    $ensure->todayForAbsences();
    expect(ScheduledWorkday::count())->toBe(0);
    $this->travelTo(Carbon::parse('2026-10-20 13:00:00', 'UTC'));
    $ensure->todayForAbsences();
    expect(ScheduledWorkday::count())->toBe(0); // Not due yet.
    $this->travelTo(Carbon::parse('2026-10-20 18:00:00', 'UTC'));
    $ensure->todayForAbsences();
    $ensure->todayForAbsences();
    expect(ScheduledWorkday::count())->toBe(1)->and(ScheduledWorkday::first()->work_date)->toBe('2026-10-20');
    $this->travelTo(Carbon::parse('2026-10-25 19:00:00', 'UTC'));
    $ensure->todayForAbsences();
    expect(ScheduledWorkday::count())->toBe(1);
});

it('C133 rolls back an interrupted daily audit and retries without losing or duplicating the day', function () {
    [$admin,$cashier] = attendancePeople();
    config(['cajas.schedule_controls_enabled' => true]);
    $this->travelTo(Carbon::parse('2026-10-19 11:00:00', 'UTC'));
    app(SaveRecurringWorkShiftAction::class)->execute($admin, ['user_id' => $cashier->id, 'period' => 'manana', 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()]);
    $fail = true;
    DB::listen(function ($query) use (&$fail): void {
        if ($fail && str_starts_with($query->sql, 'insert into "workday_audits"')) {
            $fail = false;
            throw new RuntimeException('test interruption');
        }
    });
    $ensure = app(EnsureRecurringWorkdaysAction::class);
    expect(fn () => $ensure->execute($cashier, now()->toImmutable()))->toThrow(RuntimeException::class);
    expect(ScheduledWorkday::count())->toBe(0)->and(DB::table('workday_audits')->count())->toBe(0);
    $ensure->execute($cashier, now()->toImmutable());
    $ensure->execute($cashier, now()->toImmutable());
    expect(ScheduledWorkday::count())->toBe(1)->and(DB::table('workday_audits')->count())->toBe(1);
});

it('C133 uses a newly configured future one minute shift on the same day', function () {
    [$admin,$cashier] = attendancePeople();
    config(['cajas.schedule_controls_enabled' => true]);
    $this->travelTo(Carbon::parse('2026-10-19 13:00:00', 'UTC'));
    DB::table('personnel_rule_versions')->insert(['effective_from' => now(), 'values' => json_encode(['morning_start' => '10:00', 'morning_end' => '10:01', 'tolerance_minutes' => 0, 'morning_closing_minutes' => 0]), 'created_by' => $admin->id, 'request_key' => (string) Str::uuid(), 'reason' => 'Prueba aislada de un minuto', 'created_at' => now()]);
    app(SaveRecurringWorkShiftAction::class)->execute($admin, ['user_id' => $cashier->id, 'period' => 'manana', 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()]);
    $this->travelTo(Carbon::parse('2026-10-19 14:00:00', 'UTC'));
    app(RegisterAttendanceAction::class)->execute($cashier, '1234');
    $day = ScheduledWorkday::sole();
    expect($day->starts_at->setTimezone('America/La_Paz')->format('H:i'))->toBe('10:00')
        ->and($day->ends_at->setTimezone('America/La_Paz')->format('H:i'))->toBe('10:01');
});
