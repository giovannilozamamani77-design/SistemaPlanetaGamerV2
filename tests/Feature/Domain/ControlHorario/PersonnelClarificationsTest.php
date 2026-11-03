<?php

use App\Domain\Configuraciones\Models\PersonnelRuleVersion;
use App\Domain\ControlHorario\Actions\ApproveLeaveAction;
use App\Domain\ControlHorario\Actions\DetectTimeIncidentAction;
use App\Domain\ControlHorario\Actions\RequestLeaveAction;
use App\Domain\ControlHorario\Actions\ReviewTimeIncidentAction;
use App\Domain\ControlHorario\Actions\SubmitTimeReasonAction;
use App\Domain\ControlHorario\Actions\UseMonthlyLeaveForTimeAction;
use App\Domain\ControlHorario\Actions\UseTimeCreditAction;
use App\Domain\ControlHorario\Calculators\DelayCalculator;
use App\Domain\ControlHorario\Models\MonthlyLeave;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\ControlHorario\Queries\PersonnelTimePeriodQuery;
use App\Domain\Incidencias\Actions\ConfirmGlobalRecoveryAction;
use App\Domain\Incidencias\Actions\DeclareGlobalRecoveryAction;
use App\Domain\Incidencias\Actions\EconomicHistoryAudit;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReverseRecoveryAction;
use App\Domain\Incidencias\Actions\ReviewEconomicIncidentAction;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Incidencias\Queries\PersonnelBalancesQuery;
use App\Domain\Usuarios\Calculators\SalaryPeriodCalculator;
use App\Filament\Resources\Incidencias\GlobalRecoveries\GlobalRecoveryResource;
use App\Filament\Widgets\Usuarios\OwnPersonnelSummaryWidget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00', 'America/La_Paz'));
    $this->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
});

function clarifiedUser(string $role): User
{
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole($role);

    return $user;
}

function clarifiedDay(User $cashier, User $admin, string $date = '2026-10-15'): ScheduledWorkday
{
    return ScheduledWorkday::create(['user_id' => $cashier->id, 'created_by' => $admin->id, 'work_date' => $date, 'period' => 'manana', 'starts_at' => CarbonImmutable::parse($date.' 08:00', 'America/La_Paz')->utc(), 'ends_at' => CarbonImmutable::parse($date.' 14:00', 'America/La_Paz')->utc()]);
}

function clarifiedDebt(User $cashier, User $admin, int $minutes = 60): TimeIncident
{
    $day = clarifiedDay($cashier, $admin);
    $case = TimeIncident::create(['user_id' => $cashier->id, 'scheduled_workday_id' => $day->id, 'kind' => 'RETRASO', 'status' => 'CONFIRMADA', 'source_key' => (string) Str::uuid(), 'real_minutes' => $minutes, 'nominal_minutes' => $minutes, 'pending_minutes' => $minutes, 'calculation' => []]);
    TimeMovement::create(['user_id' => $cashier->id, 'time_incident_id' => $case->id, 'bucket' => 'RETRASO', 'delta_minutes' => $minutes, 'event_key' => 'confirm:'.$case->id, 'metadata' => [], 'created_at' => now()]);

    return $case;
}

function clarifiedShortage(User $admin, User $cashier, int $cents): EconomicIncident
{
    $case = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => (string) Str::uuid(), 'component' => 'efectivo', 'amount_cents' => $cents, 'reason' => 'Faltante verificado', 'occurred_at' => now()]);

    return app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Conteo comprobado', $cents);
}

it('counts lateness immediately after tolerance and only multiplies the configured window', function () {
    $start = CarbonImmutable::parse('2026-10-15 08:00', 'America/La_Paz');
    expect(app(DelayCalculator::class)->calculate($start, $start->addMinutes(16))['weighted'])->toBe(2);
    expect(app(DelayCalculator::class)->calculate($start, $start->addMinutes(76), 15, 2, 60)['weighted'])->toBe(121);
    expect(app(DelayCalculator::class)->calculate($start, $start->addMinutes(20), 15, 3, null)['weighted'])->toBe(15);
});

it('keeps an expired delay pending and never condones money or schedules a salary penalty automatically', function () {
    $admin = clarifiedUser('administrador');
    $cashier = clarifiedUser('cajera');
    $case = app(DetectTimeIncidentAction::class)->opening($cashier, clarifiedDay($cashier, $admin, '2026-09-15'), CarbonImmutable::parse('2026-09-15 08:20', 'America/La_Paz'), 'verified-opening');
    $shortage = clarifiedShortage($admin, $cashier, 100);
    $this->artisan('historial:procesar', ['--month' => '2026-09'])->assertSuccessful();
    expect($case->fresh()->status)->toBe('CONFIRMADA');
    expect($shortage->fresh()->pending_cents)->toBe(100);
    expect(DB::table('time_history_events')->where('action', 'like', 'DESCUENTO_PENDIENTE%')->count())->toBe(0);
});

it('does not invent a Sunday absence when there is no ordinary obligation', function () {
    $admin = clarifiedUser('administrador');
    $cashier = clarifiedUser('cajera');
    $day = clarifiedDay($cashier, $admin, '2026-10-11');
    expect(app(DetectTimeIncidentAction::class)->absence($day))->toBeNull();
});

it('allows administration to use one whole monthly leave for only one hour once', function () {
    $admin = clarifiedUser('administrador');
    $cashier = clarifiedUser('cajera');
    $case = clarifiedDebt($cashier, $admin);
    $request = app(RequestLeaveAction::class)->execute($cashier, ['kind' => 'COMPENSAR_RETRASOS', 'reason' => 'Solicito resolución administrativa', 'request_key' => (string) Str::uuid()]);
    app(ApproveLeaveAction::class)->execute($admin, $request, true, 'Autoriza consumir el día completo por una hora');
    app(ApproveLeaveAction::class)->execute($admin, $request, true, 'Reintento');
    expect($case->fresh()->pending_minutes)->toBe(0);
    expect(MonthlyLeave::where('user_id', $cashier->id)->value('status'))->toBe('USADO');
    expect(TimeMovement::where('event_key', 'like', 'leave:%')->count())->toBe(1);
});

it('allows administration to forgive time without recording a salary application', function () {
    $admin = clarifiedUser('administrador');
    $cashier = clarifiedUser('cajera');
    $case = clarifiedDebt($cashier, $admin);
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'PERDONAR', 'Llamada de atención sin descuento');
    expect($case->fresh()->pending_minutes)->toBe(0);
    expect($case->fresh()->status)->toBe('PERDONADA');
    expect(TimeMovement::where('metadata->kind', 'SALARIO_APLICADO')->count())->toBe(0);
});

it('lets a cashier consume only her verified credit once when automatic mode is disabled', function () {
    PersonnelRuleVersion::create(['request_key' => (string) Str::uuid(), 'values' => ['automatic_credit' => false], 'effective_from' => now()->subDay(), 'created_by' => clarifiedUser('administrador')->id, 'reason' => 'Modo manual de prueba']);
    $admin = clarifiedUser('administrador');
    $cashier = clarifiedUser('cajera');
    $case = clarifiedDebt($cashier, $admin);
    TimeMovement::create(['user_id' => $cashier->id, 'bucket' => 'CREDITO', 'delta_minutes' => 30, 'event_key' => 'verified-credit', 'metadata' => [], 'created_at' => now()]);
    $key = (string) Str::uuid();
    app(UseTimeCreditAction::class)->execute($cashier, $cashier, $key);
    app(UseTimeCreditAction::class)->execute($cashier, $cashier, $key);
    expect($case->fresh()->pending_minutes)->toBe(30);
    expect((int) TimeMovement::where('user_id', $cashier->id)->where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(0);
});

it('classifies pay periods by individual pay day without losing older debt', function () {
    $cashier = clarifiedUser('cajera');
    $cashier->salary_pay_day = 15;
    $cashier->save();
    $period = app(SalaryPeriodCalculator::class)->forUser($cashier, CarbonImmutable::parse('2026-10-17', 'America/La_Paz'));
    expect($period)->toBe(['start' => '2026-10-15', 'end' => '2026-11-14']);
    expect(app(SalaryPeriodCalculator::class)->forUser(User::factory()->make(), now()))->toBeNull();
});

it('records a minor BCP difference briefly and administration excludes a verified bank charge', function () {
    $admin = clarifiedUser('administrador');
    $cashier = clarifiedUser('cajera');
    $case = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => (string) Str::uuid(), 'component' => 'bcp', 'amount_cents' => 1, 'cause' => 'CARGO_BANCARIO', 'box_label' => 'BCP 1', 'occurred_at' => now()]);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CARGO_BANCARIO', 'Movimiento bancario verificado', null, false, '1234');
    expect($case->fresh()->pending_cents)->toBe(0);
    expect($case->fresh()->status)->toBe('CARGO_BANCARIO');
    expect($case->fresh()->box_label)->toBe('BCP 1');
});

it('refuses a bank-charge classification for cash or Yape', function (string $component) {
    $admin = clarifiedUser('administrador');
    $cashier = clarifiedUser('cajera');
    expect(fn () => app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => (string) Str::uuid(), 'component' => $component, 'reason' => 'Causa declarada', 'amount_cents' => 1, 'cause' => 'CARGO_BANCARIO', 'occurred_at' => now()]))->toThrow(ValidationException::class);
})->with(['efectivo', 'yape']);

it('reduces global debt only after administration receives a partial payment without duplicate application', function () {
    $admin = clarifiedUser('administrador');
    $cashier = clarifiedUser('cajera');
    $one = clarifiedShortage($admin, $cashier, 20000);
    $two = clarifiedShortage($admin, $cashier, 1000);
    $payment = app(DeclareGlobalRecoveryAction::class)->execute($cashier, $cashier, ['request_key' => (string) Str::uuid(), 'method' => 'EFECTIVO', 'amount_cents' => 5000, 'note' => 'Entrega global de efectivo']);
    expect(EconomicIncident::where('user_id', $cashier->id)->sum('pending_cents'))->toBe(21000);
    app(ConfirmGlobalRecoveryAction::class)->execute($admin, $payment, true, 'Recibidos Bs50');
    app(ConfirmGlobalRecoveryAction::class)->execute($admin, $payment, true, 'Reintento');
    expect((int) EconomicIncident::where('user_id', $cashier->id)->sum('pending_cents'))->toBe(16000);
});

it('denies cross-cashier credit and permits administration without reusing spent minutes', function () {
    $admin = clarifiedUser('administrador');
    $owner = clarifiedUser('cajera');
    $other = clarifiedUser('cajera');
    $case = clarifiedDebt($owner, $admin);
    TimeMovement::create(['user_id' => $owner->id, 'bucket' => 'CREDITO', 'delta_minutes' => 30, 'event_key' => 'credit-authorized', 'metadata' => [], 'created_at' => now()]);
    expect(fn () => app(UseTimeCreditAction::class)->execute($other, $owner, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(app(UseTimeCreditAction::class)->execute($admin, $owner, (string) Str::uuid()))->toBe(30);
    expect(app(UseTimeCreditAction::class)->execute($owner, $owner, (string) Str::uuid()))->toBe(0);
    expect($case->fresh()->pending_minutes)->toBe(30);
});
it('lets administration initiate a full monthly leave resolution and keeps retry idempotent', function () {
    $admin = clarifiedUser('administrador');
    $owner = clarifiedUser('cajera');
    $case = clarifiedDebt($owner, $admin);
    $key = (string) Str::uuid();
    $action = app(UseMonthlyLeaveForTimeAction::class);
    $action->execute($admin, $case, 'Acuerdo para consumir todo el derecho', $key);
    $action->execute($admin, $case, 'Acuerdo para consumir todo el derecho', $key);
    expect($case->fresh()->pending_minutes)->toBe(0);
    expect(MonthlyLeave::where('user_id', $owner->id)->value('status'))->toBe('USADO');
    expect(DB::table('time_history_events')->where('action', 'PERMISO_SOLICITADO')->value('actor_user_id'))->toBe($admin->id);
});
it('denies cashier receipt verification and rolls back global receipt when auditing fails', function () {
    $admin = clarifiedUser('administrador');
    $owner = clarifiedUser('cajera');
    $case = clarifiedShortage($admin, $owner, 5000);
    $batch = app(DeclareGlobalRecoveryAction::class)->execute($owner, $owner, ['request_key' => (string) Str::uuid(), 'method' => 'EFECTIVO', 'amount_cents' => 3000, 'note' => 'Entrega real']);
    expect(fn () => app(ConfirmGlobalRecoveryAction::class)->execute($owner, $batch, true, 'Autoverificación'))->toThrow(AuthorizationException::class);
    $this->mock(EconomicHistoryAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failure'));
    expect(fn () => app(ConfirmGlobalRecoveryAction::class)->execute($admin, $batch, true, 'Recepción real'))->toThrow(RuntimeException::class);
    expect($case->fresh()->pending_cents)->toBe(5000);
    expect($batch->fresh()->confirmed_at)->toBeNull();
    expect(RecoveryPayment::where('global_recovery_id', $batch->id)->count())->toBe(0);
});
it('shows received and forgiven amounts separately without losing earlier period debt', function () {
    $admin = clarifiedUser('administrador');
    $owner = clarifiedUser('cajera');
    $owner->forceFill(['salary_pay_day' => 15])->save();
    $case = clarifiedShortage($admin, $owner, 5000);
    $case->update(['occurred_at' => now()->subDays(2)]);
    $batch = app(DeclareGlobalRecoveryAction::class)->execute($owner, $owner, ['request_key' => (string) Str::uuid(), 'method' => 'EFECTIVO', 'amount_cents' => 3000, 'note' => 'Pago parcial']);
    app(ConfirmGlobalRecoveryAction::class)->execute($admin, $batch, true, 'Recibido');
    $b = app(PersonnelBalancesQuery::class)->execute($owner);
    expect($b['previous'])->toBe(2000);
    expect($b['received'])->toBe(3000);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONDONAR', 'Perdón administrativo', null, false, '1234');
    $b = app(PersonnelBalancesQuery::class)->execute($owner);
    expect($b['pending'])->toBe(0);
    expect($b['received'])->toBe(3000);
    expect($b['forgiven'])->toBe(2000);
});
it('renders the global recovery page for administration and denies its route to cashiers', function () {
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = clarifiedUser('administrador');
    $owner = clarifiedUser('cajera');
    clarifiedShortage($admin, $owner, 5000);
    app(DeclareGlobalRecoveryAction::class)->execute($owner, $owner, ['request_key' => (string) Str::uuid(), 'method' => 'EFECTIVO', 'amount_cents' => 1000, 'note' => 'Declaración de prueba']);
    $url = GlobalRecoveryResource::getUrl();
    $this->actingAs($admin)->get($url)->assertOk()->assertSee('Reposiciones');
    $this->actingAs($owner)->get($url)->assertForbidden();
});

it('keeps forgiven time closed to a later cashier reason', function () {
    $admin = clarifiedUser('administrador');
    $owner = clarifiedUser('cajera');
    $case = clarifiedDebt($owner, $admin);
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'PERDONAR', 'Decisión final');
    expect(fn () => app(SubmitTimeReasonAction::class)->execute($owner, $case, 'revision', 'Abrir nuevamente'))->toThrow(ValidationException::class);
    expect($case->fresh()->status)->toBe('PERDONADA');
});
it('does not penalize a Sunday opening and marks a skipped absence evaluated once', function () {
    $admin = clarifiedUser('administrador');
    $owner = clarifiedUser('cajera');
    $day = clarifiedDay($owner, $admin, '2026-10-11');
    $case = app(DetectTimeIncidentAction::class)->opening($owner, $day, CarbonImmutable::parse('2026-10-11 12:00', 'America/La_Paz'), 'sunday');
    expect($case->nominal_minutes)->toBe(0);
    app(DetectTimeIncidentAction::class)->absence($day);
    expect($day->fresh()->absence_checked_at)->not->toBeNull();
});
it('keeps time debt in its original salary period and shows it as previous pending', function () {
    $admin = clarifiedUser('administrador');
    $owner = clarifiedUser('cajera');
    $owner->forceFill(['salary_pay_day' => 15])->save();
    $day = clarifiedDay($owner, $admin, '2026-10-14');
    $case = app(DetectTimeIncidentAction::class)->opening($owner, $day, CarbonImmutable::parse('2026-10-14 08:20', 'America/La_Paz'), 'period-opening');
    app(ReviewTimeIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Atraso confirmado');
    $b = app(PersonnelTimePeriodQuery::class)->execute($owner);
    expect($case->period_start)->toBe('2026-09-15');
    expect($b['previous'])->toBe(10);
    expect($b['current'])->toBe(0);
});
it('renders native balance cards and reverses a global allocation only once', function () {
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = clarifiedUser('administrador');
    $owner = clarifiedUser('cajera');
    $case = clarifiedShortage($admin, $owner, 5000);
    $batch = app(DeclareGlobalRecoveryAction::class)->execute($owner, $owner, ['request_key' => (string) Str::uuid(), 'method' => 'EFECTIVO', 'amount_cents' => 3000, 'note' => 'Entrega para prueba']);
    app(ConfirmGlobalRecoveryAction::class)->execute($admin, $batch, true, 'Recibido y verificado');
    $payment = $case->recoveries()->where('global_recovery_id', $batch->id)->firstOrFail();
    app(ReverseRecoveryAction::class)->execute($admin, $payment, true, 'Devolución real verificada');
    app(ReverseRecoveryAction::class)->execute($admin, $payment, true, 'Reintento');
    $b = app(PersonnelBalancesQuery::class)->execute($owner);
    expect($b['pending'])->toBe(5000);
    expect($b['received'])->toBe(0);
    $this->actingAs($owner);
    Livewire::test(OwnPersonnelSummaryWidget::class)->assertSee('Faltante acumulado pendiente')->assertSee('Reposiciones realizadas');
});
