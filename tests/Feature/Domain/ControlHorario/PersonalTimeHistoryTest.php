<?php

use App\Domain\ControlHorario\Actions\ApplyTimeCreditAction;
use App\Domain\ControlHorario\Actions\ApplyTimeDiscountAction;
use App\Domain\ControlHorario\Actions\ApproveLeaveAction;
use App\Domain\ControlHorario\Actions\DetectTimeIncidentAction;
use App\Domain\ControlHorario\Actions\PrepareMonthlyTimeDiscountsAction;
use App\Domain\ControlHorario\Actions\ProcessDueTimeHistoryAction;
use App\Domain\ControlHorario\Actions\RegisterAttendanceAction;
use App\Domain\ControlHorario\Actions\RegisterLinkedAttendanceAction;
use App\Domain\ControlHorario\Actions\RequestLeaveAction;
use App\Domain\ControlHorario\Actions\ReverseTimeSalaryAction;
use App\Domain\ControlHorario\Actions\ReviewTimeIncidentAction;
use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Actions\SubmitTimeReasonAction;
use App\Domain\ControlHorario\Calculators\DelayCalculator;
use App\Domain\ControlHorario\Calculators\TimeDiscountCalculator;
use App\Domain\ControlHorario\Models\Attendance;
use App\Domain\ControlHorario\Models\MonthlyLeave;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\Usuarios\Actions\SetUserPinAction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function historyPeople(): array
{
    app(UsuariosPermissionsSeeder::class)->run();
    app(ControlHorarioPermissionsSeeder::class)->run();
    app(IncidenciasPermissionsSeeder::class)->run();
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);

    return [$admin, $cashier, $day];
}
function historyLate(User $actor, ScheduledWorkday $day, string $time = '12:20'): TimeIncident
{
    return app(DetectTimeIncidentAction::class)->opening($actor, $day, CarbonImmutable::parse('2026-10-14 '.$time, 'UTC'), 'bcp:'.$day->id);
}
it('calculates weighting without an extra grace period', function (string $time, int $real, int $weighted) {
    $result = app(DelayCalculator::class)->calculate(CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), CarbonImmutable::parse('2026-10-14 '.$time, 'UTC'));
    expect($result['real'])->toBe($real);
    expect($result['weighted'])->toBe($weighted);
})->with([['12:17', 2, 4], ['12:18', 3, 6], ['12:47', 32, 62], ['12:48', 33, 63], ['12:17:01', 3, 6]]);
it('records one BCP reference per workday with automatic confirmed debt', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $first = historyLate($cashier, $day);
    $second = historyLate($cashier, $day, '12:30');
    expect($first->id)->toBe($second->id);
    expect($first->nominal_minutes)->toBe(10);
    expect($first->pending_minutes)->toBe(10);
    expect(TimeMovement::count())->toBe(1);
});
it('confirms a delay once and consumes earned credit from the entire pending accumulation', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = historyLate($cashier, $day);
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Retraso confirmado');
    app(ApplyTimeCreditAction::class)->execute($day, CarbonImmutable::parse('2026-10-14 11:00', 'UTC'), CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), 'worked:one');
    app(ApplyTimeCreditAction::class)->execute($day, CarbonImmutable::parse('2026-10-14 11:00', 'UTC'), CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), 'worked:one');
    expect($case->fresh()->pending_minutes)->toBe(0);
    expect((int) TimeMovement::where('user_id', $cashier->id)->where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(50);
    expect((int) TimeMovement::where('user_id', $cashier->id)->where('bucket', 'RETRASO')->sum('delta_minutes'))->toBe(0);
});
it('does not double credit overlapping worked intervals or time after the scheduled start', function () {
    [, $cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $action = app(ApplyTimeCreditAction::class);
    $action->execute($day, CarbonImmutable::parse('2026-10-14 10:00', 'UTC'), CarbonImmutable::parse('2026-10-14 11:30', 'UTC'), 'interval:1');
    $action->execute($day, CarbonImmutable::parse('2026-10-14 11:00', 'UTC'), CarbonImmutable::parse('2026-10-14 13:00', 'UTC'), 'interval:2');
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(120);
});
it('records a pending absence without inserting a fictitious PIN', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = app(DetectTimeIncidentAction::class)->absence($day);
    expect($case->status)->toBe('FALTA_PENDIENTE');
    expect($case->pending_minutes)->toBe(0);
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Ausencia sin permiso');
    expect($case->fresh()->pending_minutes)->toBe(360);
    expect($day->attendance()->count())->toBe(0);
});
it('keeps an approved administrative delay at zero effect', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = historyLate($cashier, $day);
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, 'administracion_tardia', 'Esperé a Administración');
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'JUSTIFICAR', 'Demora verificada');
    expect($case->fresh()->status)->toBe('JUSTIFICADA');
    expect($case->fresh()->pending_minutes)->toBe(0);
    expect((int) TimeMovement::where('bucket', 'RETRASO')->sum('delta_minutes'))->toBe(0);
    expect(TimeMovement::count())->toBe(2);
});
it('does not annul delays for personal motives', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = historyLate($cashier, $day);
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, 'personal', 'Me retrasé por transporte');
    expect(fn () => app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'JUSTIFICAR', 'Motivo personal'))->toThrow(ValidationException::class);
});
it('marks a future monthly day off used immediately on approval and does not create an absence debt', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 10:00', 'UTC'));
    $request = app(RequestLeaveAction::class)->execute($cashier, ['kind' => 'AUSENCIA_FUTURA', 'workday_id' => $day->id, 'reason' => 'Urgencia personal', 'request_key' => (string) Str::uuid()]);
    expect($request->monthlyLeave->status)->toBe('DISPONIBLE');
    app(ApproveLeaveAction::class)->execute($admin, $request, true, 'Permiso aprobado');
    app(ApproveLeaveAction::class)->execute($admin, $request, true, 'Permiso aprobado');
    expect($request->monthlyLeave->fresh()->status)->toBe('USADO');
    expect(MonthlyLeave::count())->toBe(1);
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    expect(app(DetectTimeIncidentAction::class)->absence($day)->status)->toBe('PERMISO_AUTORIZADO');
    expect(TimeMovement::count())->toBe(0);
});
it('consumes one monthly day off to cover one complete confirmed absence', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = app(DetectTimeIncidentAction::class)->absence($day);
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Falta confirmada');
    $request = app(RequestLeaveAction::class)->execute($cashier, ['kind' => 'COMPENSAR_FALTA', 'incident_id' => $case->id, 'reason' => 'Usar mi día libre', 'request_key' => (string) Str::uuid()]);
    app(ApproveLeaveAction::class)->execute($admin, $request, true, 'Conversado y confirmado');
    expect($case->fresh()->pending_minutes)->toBe(0);
    expect((int) TimeMovement::where('bucket', 'FALTA_REAL')->sum('delta_minutes'))->toBe(0);
    expect(fn () => app(RequestLeaveAction::class)->execute($cashier, ['kind' => 'COMPENSAR_FALTA', 'incident_id' => $case->id, 'reason' => 'Otra vez', 'request_key' => (string) Str::uuid()]))->toThrow(ModelNotFoundException::class);
});
it('allows requesting a full day off for fractional pending delays', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = historyLate($cashier, $day);
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Confirmado');
    $request = app(RequestLeaveAction::class)->execute($cashier, ['kind' => 'COMPENSAR_RETRASOS', 'reason' => 'Compensar minutos pendientes', 'request_key' => (string) Str::uuid()]);
    expect($request->status)->toBe('PENDIENTE');
    expect($case->fresh()->pending_minutes)->toBe(10);
});
it('calculates partial salary exactly and settles only after recording a real application', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = app(DetectTimeIncidentAction::class)->earlyDeparture($cashier, $day, CarbonImmutable::parse('2026-10-14 15:00', 'UTC'), 'approval:1');
    expect($case->pending_minutes)->toBe(180);
    expect(app(TimeDiscountCalculator::class)->cents(180))->toBe(2500);
    $action = app(ApplyTimeDiscountAction::class);
    $action->execute($admin, $case, 180, '2026-10', 'Planilla aplicada #1', 'salary-one');
    $action->execute($admin, $case, 180, '2026-10', 'Planilla aplicada #1', 'salary-one');
    expect($case->fresh()->pending_minutes)->toBe(0);
    expect(TimeMovement::where('event_key', 'salary:salary-one')->first()->metadata['amount_cents'])->toBe(2500);
});
it('denies accessing or approving another cashiers own request', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = historyLate($cashier, $day);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(fn () => app(SubmitTimeReasonAction::class)->execute($other, $case, 'revision', 'No es mío'))->toThrow(AuthorizationException::class);
    expect(fn () => app(ReviewTimeIncidentAction::class)->execute($cashier, $case, 'CONFIRMAR', 'Autoaprobar'))->toThrow(AuthorizationException::class);
});

it('does not recharge a compensated delay when its review is confirmed again', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = historyLate($cashier, $day);
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Confirmado');
    app(ApplyTimeCreditAction::class)->execute($day, CarbonImmutable::parse('2026-10-14 11:00', 'UTC'), CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), 'worked:review');
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, 'revision', 'Solicito revisar el hecho');
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Ratifico la decisión original');
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(50);
    expect((int) TimeMovement::where('bucket', 'RETRASO')->sum('delta_minutes'))->toBe(0);
});
it('keeps automatic debt unchanged after expiry without automatic sanctions', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = historyLate($cashier, $day);
    $this->travelTo(Carbon::parse('2026-10-19 03:59:00', 'UTC'));
    app(ProcessDueTimeHistoryAction::class)->execute();
    expect($case->fresh()->pending_minutes)->toBe(10);
    $this->travelTo(Carbon::parse('2026-10-19 04:00:00', 'UTC'));
    app(ProcessDueTimeHistoryAction::class)->execute();
    app(ProcessDueTimeHistoryAction::class)->execute();
    expect($case->fresh()->pending_minutes)->toBe(10);
    expect($case->fresh()->status)->toBe('CONFIRMADA');
    expect(TimeMovement::where('event_key', 'confirm:'.$case->id)->count())->toBe(1);
});

it('does not award afternoon credit for already scheduled morning hours or credit the same early hour twice', function () {
    [$admin,$cashier,$morning] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $afternoon = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'tarde', 'starts_time' => '14:00', 'ends_time' => '20:00']);
    $action = app(ApplyTimeCreditAction::class);
    $action->execute($morning, CarbonImmutable::parse('2026-10-14 11:00', 'UTC'), CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), 'early-am');
    $action->execute($afternoon, CarbonImmutable::parse('2026-10-14 11:00', 'UTC'), CarbonImmutable::parse('2026-10-14 18:00', 'UTC'), 'continued-am-to-pm');
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(60);
});
it('restores previously consumed credit exactly once when an incorrect delay is invalidated', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = historyLate($cashier, $day);
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Confirmado');
    app(ApplyTimeCreditAction::class)->execute($day, CarbonImmutable::parse('2026-10-14 11:00', 'UTC'), CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), 'credit-to-reverse');
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'INVALIDAR', 'La marca de origen era errónea');
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'INVALIDAR', 'Reintento');
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(60);
    expect((int) TimeMovement::where('bucket', 'RETRASO')->sum('delta_minutes'))->toBe(0);
});

it('reverses a mistaken absence covered by a day off without consuming the monthly right twice', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = app(DetectTimeIncidentAction::class)->absence($day);
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Ausencia confirmada');
    $request = app(RequestLeaveAction::class)->execute($cashier, ['kind' => 'COMPENSAR_FALTA', 'incident_id' => $case->id, 'reason' => 'Usar día libre', 'request_key' => (string) Str::uuid()]);
    app(ApproveLeaveAction::class)->execute($admin, $request, true, 'Aprobado');
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'INVALIDAR', 'Se acreditó asistencia por otra evidencia');
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'INVALIDAR', 'Reintento');
    expect($request->monthlyLeave->fresh()->status)->toBe('DISPONIBLE');
    expect($request->fresh()->status)->toBe('REVERTIDA');
    expect((int) TimeMovement::where('bucket', 'FALTA_REAL')->sum('delta_minutes'))->toBe(0);
});
it('requires a real payroll reversal before invalidating a paid time incident', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = app(DetectTimeIncidentAction::class)->earlyDeparture($cashier, $day, CarbonImmutable::parse('2026-10-14 15:00', 'UTC'), 'approved');
    app(ApplyTimeDiscountAction::class)->execute($admin, $case, 180, '2026-10', 'Aplicación externa registrada', 'salary-refund');
    expect(fn () => app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'INVALIDAR', 'Corregir'))->toThrow(ValidationException::class);
    $movement = TimeMovement::where('event_key', 'salary:salary-refund')->first();
    app(ReverseTimeSalaryAction::class)->execute($admin, $movement, true, 'Devolución aplicada en planilla');
    app(ReverseTimeSalaryAction::class)->execute($admin, $movement, true, 'Reintento');
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'INVALIDAR', 'Error original demostrado');
    expect($case->fresh()->status)->toBe('INVALIDADA');
    expect((int) TimeMovement::where('bucket', 'FALTA_REAL')->sum('delta_minutes'))->toBe(0);
});
it('does not create a salary sanction at the calendar month boundary', function () {
    [$admin,$cashier,$day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = app(DetectTimeIncidentAction::class)->earlyDeparture($cashier, $day, CarbonImmutable::parse('2026-10-14 15:00', 'UTC'), 'approved-month');
    $this->travelTo(Carbon::parse('2026-11-01 04:00', 'UTC'));
    $action = app(PrepareMonthlyTimeDiscountsAction::class);
    $action->execute('2026-10');
    $action->execute('2026-10');
    expect($case->fresh()->pending_minutes)->toBe(180);
    $event = DB::table('time_history_events')->where('event_key', 'monthly-time:2026-10:'.$cashier->id)->first();
    expect($event)->toBeNull();
});
it('links a morning PIN to a separately scheduled afternoon only through its persisted security proof', function () {
    [$admin,$cashier,$morning] = historyPeople();
    $afternoon = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'tarde', 'starts_time' => '14:00', 'ends_time' => '20:00']);
    app(SetUserPinAction::class)->execute($admin, $cashier, '1234');
    $this->travelTo(Carbon::parse('2026-10-14 12:00', 'UTC'));
    app(RegisterAttendanceAction::class)->execute($cashier, '1234');
    $proof = DB::table('user_audits')->where('action', 'pin_verified')->where('subject_user_id', $cashier->id)->first();
    $this->travelTo(Carbon::parse('2026-10-14 18:00', 'UTC'));
    $action = app(RegisterLinkedAttendanceAction::class);
    $attendance = $action->execute($cashier, $afternoon, $proof->id, 'confirmed-session-continuity', CarbonImmutable::parse('2026-10-14 18:00', 'UTC'));
    $action->execute($cashier, $afternoon, $proof->id, 'confirmed-session-continuity', CarbonImmutable::parse('2026-10-14 18:00', 'UTC'));
    expect($attendance->first_pin_at->format('H:i'))->toBe('12:00');
    expect(Attendance::count())->toBe(2);
    expect(fn () => $action->execute($admin, $afternoon, $proof->id, 'another-user', CarbonImmutable::parse('2026-10-14 18:00', 'UTC')))->toThrow(AuthorizationException::class);
});

it('C116 restores already consumed credit when an automatic delay is justified without deleting history', function () {
    [$admin, $cashier, $day] = historyPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    app(ApplyTimeCreditAction::class)->execute($day, CarbonImmutable::parse('2026-10-14 11:00', 'UTC'), CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), 'C116:early-work');
    $case = historyLate($cashier, $day);
    expect($case->pending_minutes)->toBe(0);
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(50);
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, 'administracion_tardia', 'Esperé a Administración');
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'JUSTIFICAR', 'Demora verificada');
    $count = TimeMovement::count();
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'JUSTIFICAR', 'Demora verificada');
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(60);
    expect((int) TimeMovement::where('bucket', 'RETRASO')->sum('delta_minutes'))->toBe(0);
    expect(TimeMovement::count())->toBe($count);
    expect(TimeMovement::where('event_key', 'confirm:'.$case->id)->count())->toBe(1);
});
