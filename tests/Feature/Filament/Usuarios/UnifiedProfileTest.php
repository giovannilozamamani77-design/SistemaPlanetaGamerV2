<?php

use App\Domain\Cajas\Actions\SetCashBoxCommissionAccountAction;
use App\Domain\Configuraciones\Models\PersonnelRuleVersion;
use App\Domain\ControlHorario\Actions\ApplyTimeCreditAction;
use App\Domain\ControlHorario\Actions\DetectTimeIncidentAction;
use App\Domain\ControlHorario\Actions\RequestLeaveAction;
use App\Domain\ControlHorario\Actions\ReviewTimeAppealAction;
use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Actions\SubmitTimeReasonAction;
use App\Domain\ControlHorario\Actions\TimeHistoryAudit;
use App\Domain\ControlHorario\Actions\UseTimeCreditAction;
use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\MonthlyLeave;
use App\Domain\ControlHorario\Models\TimeHistoryEvent;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\ControlHorario\Queries\OwnTimeHistoryQuery;
use App\Domain\ControlHorario\Queries\TimeAppealQuery;
use App\Domain\Incidencias\Actions\DeclareRecoveryAction;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReviewEconomicIncidentAction;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Notificaciones\Queries\PanelInboxQuery;
use App\Domain\Usuarios\Actions\SetUserPinAction;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Filament\Resources\ControlHorario\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\ControlHorario\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\ControlHorario\TimeIncidents\Pages\ListTimeIncidents;
use App\Filament\Resources\ControlHorario\TimeIncidents\TimeIncidentResource;
use App\Filament\Resources\Incidencias\EconomicIncidents\EconomicIncidentResource;
use App\Filament\Resources\Incidencias\EconomicIncidents\Pages\ListEconomicIncidents;
use App\Filament\Widgets\Usuarios\OwnContributionsWidget;
use App\Filament\Widgets\Usuarios\OwnEconomicHistoryWidget;
use App\Filament\Widgets\Usuarios\OwnLeavesWidget;
use App\Filament\Widgets\Usuarios\OwnProfileBalancesWidget;
use App\Filament\Widgets\Usuarios\OwnTimeHistoryWidget;
use App\Filament\Widgets\Usuarios\OwnTimeMovementsWidget;
use App\Filament\Widgets\Usuarios\OwnTimeSummaryWidget;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function unifiedProfilePeople(): array
{
    app(UsuariosPermissionsSeeder::class)->run();
    app(ControlHorarioPermissionsSeeder::class)->run();
    app(IncidenciasPermissionsSeeder::class)->run();
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $other = User::factory()->create();
    $other->assignRole('cajera');
    Filament::setCurrentPanel(Filament::getPanel('inicio'));

    return [$admin, $cashier, $other];
}
it('renders all personal history sections inside the existing profile', function () {
    [, $cashier] = unifiedProfilePeople();
    $this->actingAs($cashier)->get(EditOwnProfile::getUrl())->assertOk()->assertSee('Datos personales')->assertSee('Retrasos y faltas')->assertSee('Permisos y día libre')->assertSee('Faltantes y reposiciones');
});
it('filters time and economic widgets by authenticated ownership', function () {
    [$admin,$cashier,$other] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $cases = [];
    foreach ([$cashier, $other] as $person) {
        $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $person->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
        $cases[] = app(DetectTimeIncidentAction::class)->opening($person, $day, CarbonImmutable::parse('2026-10-14 12:20', 'UTC'), 'ref-'.$person->id);
    }
    $economic = [];
    foreach ([$cashier, $other] as $person) {
        $economic[] = app(ReportEconomicIncidentAction::class)->execute($admin, $person, ['source_module' => 'manual', 'source_reference' => 'ref-'.$person->id, 'component' => 'efectivo', 'amount_cents' => 1000, 'reason' => 'Conteo para revisión', 'occurred_at' => now()->format('Y-m-d H:i:s')]);
    }
    $this->actingAs($cashier);
    Livewire::test(OwnTimeHistoryWidget::class)->assertCanSeeTableRecords([$cases[0]])->assertCanNotSeeTableRecords([$cases[1]]);
    Livewire::test(OwnEconomicHistoryWidget::class)->assertCanSeeTableRecords([$economic[0]])->assertCanNotSeeTableRecords([$economic[1]]);
});
it('submits a future day off request from the profile and keeps it pending approval', function () {
    [$admin,$cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 10:00', 'UTC'));
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
    $this->actingAs($cashier);
    Livewire::test(OwnLeavesWidget::class)->callTableAction('solicitarPermiso', data: ['request_key' => (string) Str::uuid(), 'kind' => 'AUSENCIA_FUTURA', 'workday_id' => $day->id, 'reason' => 'Emergencia familiar'])->assertHasNoTableActionErrors();
    expect(LeaveRequest::first()->status)->toBe('PENDIENTE');
    expect(MonthlyLeave::first()->status)->toBe('DISPONIBLE');
});
it('records a declared repayment from the profile without reducing confirmed debt', function () {
    [$admin,$cashier] = unifiedProfilePeople();
    app(CajasPermissionsSeeder::class)->run();
    app(SetUserPinAction::class)->executeOwn($admin, '1234');
    app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['destination' => '70000099', 'reason' => 'Cuenta aislada de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $case = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => 'ref-payment', 'component' => 'efectivo', 'amount_cents' => 1000, 'reason' => 'Conteo para revisión', 'occurred_at' => now()->format('Y-m-d H:i:s')]);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Confirmado');
    $this->actingAs($cashier);
    Livewire::test(OwnEconomicHistoryWidget::class)->callTableAction('declararAporte', $case, data: ['request_key' => (string) Str::uuid(), 'operation_reference' => 'YAPE-PROFILE-001'])->assertHasNoTableActionErrors();
    expect(RecoveryPayment::first()->amount_cents)->toBe(1000);
    expect(RecoveryPayment::first()->method)->toBe('QR');
    expect($case->fresh()->pending_cents)->toBe(1000);
});
it('does not render an economic profile tab after an individual restriction', function () {
    [, $cashier] = unifiedProfilePeople();
    $cashier->restrictedPermissions()->attach(Permission::findByName('incidencias.ver_propios', 'web'));
    $this->actingAs($cashier)->get(EditOwnProfile::getUrl())->assertOk()->assertDontSee('Faltantes y reposiciones');
});

it('reviews a time incident through the actual administrative table action', function () {
    [$admin,$cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
    $case = app(DetectTimeIncidentAction::class)->opening($cashier, $day, CarbonImmutable::parse('2026-10-14 12:20', 'UTC'), 'admin-ui-case');
    $this->actingAs($admin);
    Livewire::test(ListTimeIncidents::class)
        ->callTableAction('revisar', $case, data: ['decision' => 'CONFIRMAR', 'reason' => 'Confirmación administrativa', 'exception' => false])->assertHasNoTableActionErrors();
    expect($case->fresh()->pending_minutes)->toBe(10);
});
it('approves a requested day off through the administrative table without a second consumption', function () {
    [$admin,$cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 10:00', 'UTC'));
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
    $request = app(RequestLeaveAction::class)->execute($cashier, ['kind' => 'AUSENCIA_FUTURA', 'workday_id' => $day->id, 'reason' => 'Necesidad personal', 'request_key' => (string) Str::uuid()]);
    $this->actingAs($admin);
    Livewire::test(ListLeaveRequests::class)
        ->callTableAction('decidir', $request, data: ['decision' => 'aprobar', 'reason' => 'Aprobado por Administración', 'exception' => false])->assertHasNoTableActionErrors();
    expect($request->fresh()->status)->toBe('APROBADA');
    expect($request->monthlyLeave->fresh()->status)->toBe('USADO');
});
it('confirms an economic incident and verifies the actual contribution through administrative forms', function () {
    [$admin,$cashier] = unifiedProfilePeople();
    $case = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => 'admin-economic', 'component' => 'efectivo', 'amount_cents' => 20000, 'reason' => 'Faltante para revisar', 'occurred_at' => now()->format('Y-m-d H:i:s')]);
    $this->actingAs($admin);
    Livewire::test(ListEconomicIncidents::class)
        ->callTableAction('revisar', $case, data: ['decision' => 'CONFIRMAR', 'second_amount' => '200.00', 'reason' => 'Segundo conteo correcto', 'exception' => false])->assertHasNoTableActionErrors();
    $payment = app(DeclareRecoveryAction::class)->execute($cashier, $case, ['kind' => 'RECUPERACION', 'method' => 'EFECTIVO', 'amount_cents' => 5000, 'request_key' => (string) Str::uuid(), 'note' => 'Entrego Bs50']);
    Livewire::test(ListEconomicIncidents::class)
        ->callTableAction('verificarRecepcion', $case, data: ['payment_id' => $payment->id, 'received' => true, 'reason' => 'Ingreso real verificado', 'exception' => false])->assertHasNoTableActionErrors();
    expect($case->fresh()->pending_cents)->toBe(15000);
});
it('forbids administrative history URLs for cashiers even with their own profile access', function () {
    [, $cashier] = unifiedProfilePeople();
    $this->actingAs($cashier);
    foreach ([
        TimeIncidentResource::getUrl(),
        LeaveRequestResource::getUrl(),
        EconomicIncidentResource::getUrl(),
    ] as $url) {
        $this->get($url)->assertForbidden();
    }
});
it('shows a contributor their own contribution without revealing the other employees debt', function () {
    [$admin,$cashier,$contributor] = unifiedProfilePeople();
    $case = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => 'third-party', 'component' => 'efectivo', 'amount_cents' => 1000, 'reason' => 'Dato privado de responsable', 'occurred_at' => now()->format('Y-m-d H:i:s')]);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Confirmado');
    $payment = app(DeclareRecoveryAction::class)->execute($admin, $case, ['kind' => 'RECUPERACION', 'method' => 'EFECTIVO', 'amount_cents' => 500, 'paid_by_user_id' => $contributor->id, 'request_key' => (string) Str::uuid(), 'note' => 'Otro aporte']);
    $this->actingAs($contributor);
    Livewire::test(OwnContributionsWidget::class)->assertCanSeeTableRecords([$payment])->assertDontSee('Dato privado de responsable');
    Livewire::test(OwnEconomicHistoryWidget::class)->assertCanNotSeeTableRecords([$case]);
});
it('escapes user provided economic descriptions in personal history', function () {
    [$admin,$cashier] = unifiedProfilePeople();
    $case = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => 'escaping', 'component' => 'efectivo', 'amount_cents' => 1000, 'reason' => '<script>alert("unsafe")</script>', 'occurred_at' => now()->format('Y-m-d H:i:s')]);
    $this->actingAs($cashier);
    Livewire::test(OwnEconomicHistoryWidget::class)->assertDontSeeHtml('<script>alert("unsafe")</script>');
});

it('c118 shows private accumulated time and explains automatic and manual compensation without writes', function () {
    [, $cashier, $other] = unifiedProfilePeople();
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['cajas.schedule_controls_enabled' => false]);
    foreach ([[$cashier, 'CREDITO', 30, []], [$cashier, 'CREDITO', -10, ['automatic' => true]], [$cashier, 'RETRASO', 25, []], [$cashier, 'RETRASO', -10, ['automatic' => true]], [$cashier, 'CREDITO', -5, ['automatic' => false]], [$cashier, 'RETRASO', -5, ['automatic' => false]], [$other, 'CREDITO', 999, []]] as $i => [$person, $bucket, $delta, $metadata]) {
        TimeMovement::create(['user_id' => $person->id, 'bucket' => $bucket, 'delta_minutes' => $delta, 'event_key' => 'c118-'.$i, 'metadata' => $metadata, 'created_at' => now()]);
    }
    $this->actingAs($cashier);
    $before = TimeMovement::count();
    Livewire::test(OwnTimeSummaryWidget::class)
        ->assertSee('Retrasos pendientes')->assertSee('10 min')->assertSee('Crédito disponible')->assertSee('15 min')->assertSee('Crédito utilizado')->assertSee('Controles de Cajas pausados')->assertDontSee('999');
    Livewire::test(OwnTimeMovementsWidget::class)
        ->assertSee('Compensación automática')->assertSee('Compensación manual')->assertDontSee('999');
    expect(TimeMovement::count())->toBe($before);
    expect(config('cajas.schedule_controls_enabled'))->toBeFalse();
});
it('c118 keeps previous months visible and has a clear empty state', function () {
    [, $cashier] = unifiedProfilePeople();
    $this->actingAs($cashier);
    Livewire::test(OwnTimeSummaryWidget::class)->assertSee('0 min');
    TimeMovement::create(['user_id' => $cashier->id, 'bucket' => 'CREDITO', 'delta_minutes' => 37, 'event_key' => 'c118-previous', 'metadata' => [], 'created_at' => now()->subMonths(2)]);
    Livewire::test(OwnTimeSummaryWidget::class)->assertSee('37 min');
});
it('c118 denies personal time summaries after permission restriction', function () {
    [, $cashier] = unifiedProfilePeople();
    $cashier->restrictedPermissions()->attach(Permission::findByName('controlhorario.ver_propios', 'web'));
    $this->actingAs($cashier);
    Livewire::test(OwnTimeSummaryWidget::class)->assertForbidden();
});

it('c118 explains a real automatic application once and links its scheduled day', function () {
    [$admin, $cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
    $case = app(DetectTimeIncidentAction::class)->opening($cashier, $day, CarbonImmutable::parse('2026-10-14 12:20', 'UTC'), 'c118-real');
    app(ApplyTimeCreditAction::class)->execute($day, $day->starts_at->subMinutes(3), $day->starts_at, 'c118-work');
    $this->actingAs($cashier);
    $summary = app(OwnTimeHistoryQuery::class)->summary($cashier);
    expect($summary)->toBe(['pending' => 7, 'available' => 0, 'used' => 3]);
    $records = TimeMovement::where('user_id', $cashier->id)->get();
    Livewire::test(OwnTimeMovementsWidget::class)->assertCanSeeTableRecords($records)->assertSee('Compensación automática')->assertSee('14/10/2026');
    expect($case->fresh()->pending_minutes)->toBe(7);
});

it('c118 refreshes cards after manual credit and preserves idempotent applications', function () {
    [$admin, $cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    PersonnelRuleVersion::create(['request_key' => (string) Str::uuid(), 'values' => ['automatic_credit' => false], 'effective_from' => now()->subDay(), 'created_by' => $admin->id, 'reason' => 'Modo manual aislado']);
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
    $case = app(DetectTimeIncidentAction::class)->opening($cashier, $day, CarbonImmutable::parse('2026-10-14 12:20', 'UTC'), 'c118-manual');
    app(ApplyTimeCreditAction::class)->execute($day, $day->starts_at->subMinutes(3), $day->starts_at, 'c118-manual-work');
    $this->actingAs($cashier);
    $cards = Livewire::test(OwnTimeSummaryWidget::class)->assertSee('10 min')->assertSee('3 min');
    $key = (string) Str::uuid();
    Livewire::test(OwnTimeMovementsWidget::class)->callTableAction('aplicarCredito', data: ['key' => $key])->assertHasNoTableActionErrors()->assertDispatched('own-time-balance-updated');
    $cards->dispatch('own-time-balance-updated')->assertSee('7 min')->assertSee('0 min');
    app(UseTimeCreditAction::class)->execute($cashier, $cashier, $key);
    expect(app(OwnTimeHistoryQuery::class)->summary($cashier))->toBe(['pending' => 7, 'available' => 0, 'used' => 3]);
    expect($case->fresh()->pending_minutes)->toBe(7);
});

function c119Delay(User $admin, User $cashier): TimeIncident
{
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);

    return app(DetectTimeIncidentAction::class)->opening($cashier, $day, CarbonImmutable::parse('2026-10-14 12:20', 'UTC'), 'c119-'.$cashier->id);
}
it('c119 lets the owner appeal and administration accept without deleting credit or debt history', function () {
    [$admin, $cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    config(['cajas.schedule_controls_enabled' => false]);
    $case = c119Delay($admin, $cashier);
    $day = $case->scheduledWorkday;
    app(ApplyTimeCreditAction::class)->execute($day, $day->starts_at->subMinutes(3), $day->starts_at, 'c119-work');
    $this->actingAs($cashier);
    Livewire::test(OwnTimeHistoryWidget::class)->callTableAction('solicitarRevision', $case, data: ['code' => 'administracion_tardia', 'reason' => 'Esperé a que Administración abra la tienda'])->assertHasNoTableActionErrors();
    expect($case->fresh()->pending_minutes)->toBe(7);
    $key = app(TimeAppealQuery::class)->execute($cashier, $case)['appeal_key'];
    $original = TimeMovement::pluck('event_key')->all();
    $this->actingAs($admin);
    Livewire::test(ListTimeIncidents::class)->callTableAction('aceptarApelacion', $case, data: ['appeal_key' => $key, 'resolution' => 'JUSTIFICAR', 'reason' => 'Demora de Administración verificada', 'exception' => false])->assertHasNoTableActionErrors();
    expect($case->fresh()->status)->toBe('JUSTIFICADA');
    expect(app(OwnTimeHistoryQuery::class)->summary($cashier))->toBe(['pending' => 0, 'available' => 3, 'used' => 3]);
    expect(TimeMovement::whereIn('event_key', $original)->count())->toBe(count($original));
    $this->actingAs($cashier);
    $screen = Livewire::test(OwnTimeHistoryWidget::class)->mountTableAction('historialApelacion', $case);
    expect($screen->instance()->getMountedAction()->getModalContent()->render())->toContain('Esperé a que Administración abra la tienda', 'Demora de Administración verificada');
    expect(config('cajas.schedule_controls_enabled'))->toBeFalse();
});
it('c119 rejects an appeal once preserving debt and exposes the answer privately', function () {
    [$admin, $cashier, $other] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = c119Delay($admin, $cashier);
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, 'revision', 'Solicito revisar mi hora registrada');
    $key = app(TimeAppealQuery::class)->execute($cashier, $case)['appeal_key'];
    $this->actingAs($admin);
    Livewire::test(ListTimeIncidents::class)->callTableAction('rechazarApelacion', $case, data: ['appeal_key' => $key, 'reason' => 'La hora coincide con el registro de llegada', 'exception' => false])->assertHasNoTableActionErrors();
    app(ReviewTimeAppealAction::class)->execute($admin, $case, $key, 'RECHAZAR', 'La hora coincide con el registro de llegada');
    expect($case->fresh()->status)->toBe('CONFIRMADA');
    expect($case->fresh()->pending_minutes)->toBe(10);
    expect(TimeHistoryEvent::where('event_key', 'appeal-review:'.$key)->count())->toBe(1);
    $this->actingAs($cashier);
    $screen = Livewire::test(OwnTimeHistoryWidget::class)->mountTableAction('historialApelacion', $case);
    expect($screen->instance()->getMountedAction()->getModalContent()->render())->toContain('La hora coincide con el registro de llegada');
    expect(fn () => app(TimeAppealQuery::class)->execute($other, $case))->toThrow(AuthorizationException::class);
});

it('c119 requires a reason and forbids appealing or deciding for someone else', function () {
    [$admin, $cashier, $other] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = c119Delay($admin, $cashier);
    $this->actingAs($cashier);
    Livewire::test(OwnTimeHistoryWidget::class)->callTableAction('solicitarRevision', $case, data: ['code' => 'revision', 'reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
    expect(fn () => app(SubmitTimeReasonAction::class)->execute($cashier, $case, 'revision', '  '))->toThrow(ValidationException::class);
    expect(fn () => app(SubmitTimeReasonAction::class)->execute($other, $case, 'revision', 'No es mi registro'))->toThrow(AuthorizationException::class);
    expect(fn () => app(ReviewTimeAppealAction::class)->execute($cashier, $case, 'inventado', 'ACEPTAR', 'Quiero aprobarme'))->toThrow(AuthorizationException::class);
    expect($case->fresh()->pending_minutes)->toBe(10);
    expect(TimeHistoryEvent::where('action', 'MOTIVO_O_REVISION')->count())->toBe(0);
});
it('c119 rejects stale answers and preserves the explanations of each version', function () {
    [$admin, $cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = c119Delay($admin, $cashier);
    $submit = app(SubmitTimeReasonAction::class);
    $submit->execute($cashier, $case, 'revision', 'Primera explicación');
    $old = app(TimeAppealQuery::class)->execute($cashier, $case)['appeal_key'];
    $submit->execute($cashier, $case, 'revision', 'Segunda explicación');
    $submit->execute($cashier, $case, 'revision', 'Segunda explicación');
    expect(TimeHistoryEvent::where('action', 'MOTIVO_O_REVISION')->count())->toBe(2);
    expect(fn () => app(ReviewTimeAppealAction::class)->execute($admin, $case, $old, 'RECHAZAR', 'Ventana antigua'))->toThrow(ValidationException::class);
    expect($case->fresh()->pending_minutes)->toBe(10);
    $this->actingAs($cashier);
    $screen = Livewire::test(OwnTimeHistoryWidget::class)->mountTableAction('historialApelacion', $case);
    expect($screen->instance()->getMountedAction()->getModalContent()->render())->toContain('Primera explicación', 'Segunda explicación');
});
it('c119 rolls back an interrupted acceptance and retries without erasing the original movements', function () {
    [$admin, $cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = c119Delay($admin, $cashier);
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, 'administracion_tardia', 'Esperé la apertura de la tienda');
    $key = app(TimeAppealQuery::class)->execute($cashier, $case)['appeal_key'];
    $before = TimeMovement::count();
    app()->instance(TimeHistoryAudit::class, new class extends TimeHistoryAudit
    {
        public function record(int $userId, ?int $actorId, string $action, string $key, array $details): void
        {
            if ($action === 'APELACION_ACEPTAR') {
                throw new RuntimeException('Interrupción de prueba');
            }
            parent::record($userId, $actorId, $action, $key, $details);
        }
    });
    expect(fn () => app(ReviewTimeAppealAction::class)->execute($admin, $case, $key, 'ACEPTAR', 'Demora verificada'))->toThrow(RuntimeException::class);
    expect(TimeMovement::count())->toBe($before);
    expect($case->fresh()->pending_minutes)->toBe(10);
    expect($case->fresh()->status)->toBe('EN_REVISION');
    app()->forgetInstance(TimeHistoryAudit::class);
    $review = app(ReviewTimeAppealAction::class);
    $review->execute($admin, $case, $key, 'ACEPTAR', 'Demora verificada');
    $review->execute($admin, $case, $key, 'ACEPTAR', 'Demora verificada');
    expect($case->fresh()->pending_minutes)->toBe(0);
    expect(TimeMovement::count())->toBe($before + 1);
    expect(fn () => $review->execute($admin, $case, $key, 'RECHAZAR', 'Otra respuesta'))->toThrow(ValidationException::class);
});
it('c119 escapes explanations and retains the existing rule against personal justification', function () {
    [$admin, $cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = c119Delay($admin, $cashier);
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, 'personal', '<script>alert("private")</script>');
    $key = app(TimeAppealQuery::class)->execute($cashier, $case)['appeal_key'];
    expect(fn () => app(ReviewTimeAppealAction::class)->execute($admin, $case, $key, 'ACEPTAR', 'Es personal'))->toThrow(ValidationException::class);
    $this->actingAs($cashier);
    $screen = Livewire::test(OwnTimeHistoryWidget::class)->mountTableAction('historialApelacion', $case);
    expect($screen->instance()->getMountedAction()->getModalContent()->render())->not->toContain('<script>alert("private")</script>')->toContain('&lt;script&gt;');
    expect($case->fresh()->pending_minutes)->toBe(10);
});

it('C134 accepts a verified system outage explanation without erasing the original delay', function () {
    [$admin,$cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = c119Delay($admin, $cashier);
    $original = TimeMovement::where('user_id', $cashier->id)->pluck('id')->all();
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, 'sistema', 'El servidor estuvo en actualización durante 30 minutos');
    expect($case->fresh()->pending_minutes)->toBe(10);
    $key = app(TimeAppealQuery::class)->execute($cashier, $case)['appeal_key'];
    app(ReviewTimeAppealAction::class)->execute($admin, $case, $key, 'ACEPTAR', 'Caída del servidor verificada', false, 'JUSTIFICAR');
    expect($case->fresh()->pending_minutes)->toBe(0)->and(TimeMovement::whereIn('id', $original)->count())->toBe(count($original));
});

it('C134 separates opening and early departure debt in profile hours and minutes', function () {
    [$admin,$cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = c119Delay($admin, $cashier);
    $day = $case->scheduledWorkday;
    $early = app(DetectTimeIncidentAction::class)->earlyDeparture($cashier, $day, CarbonImmutable::parse('2026-10-14 17:30', 'UTC'), 'approved-close-test');
    app(DetectTimeIncidentAction::class)->earlyDeparture($cashier, $day, CarbonImmutable::parse('2026-10-14 17:30', 'UTC'), 'approved-close-test');
    TimeMovement::create(['user_id' => $cashier->id, 'bucket' => 'CREDITO', 'delta_minutes' => 75, 'event_key' => 'c134-available', 'metadata' => [], 'created_at' => now()]);
    $view = app(OwnTimeHistoryQuery::class)->overview($cashier);
    expect($view['opening'])->toBe(10)->and($view['early'])->toBe(30)->and($view['total'])->toBe(40)->and($view['available'])->toBe(75);
    $this->actingAs($cashier);
    Livewire::test(OwnProfileBalancesWidget::class)->assertSee('Tiempo acumulado')->assertSee('0 h 40 min')->assertSee('1 h 15 min');
    Livewire::test(OwnTimeHistoryWidget::class)->assertSee('Salida anticipada')->assertSee('Tardanza de apertura');
});

it('C134 keeps counting explanations pending until an administrative decision and requires text', function (string $code) {
    [$admin,$cashier,$other] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $case = c119Delay($admin, $cashier);
    expect(fn () => app(SubmitTimeReasonAction::class)->execute($cashier, $case, $code, '  '))->toThrow(ValidationException::class);
    expect(fn () => app(SubmitTimeReasonAction::class)->execute($other, $case, $code, 'Explicación ajena'))->toThrow(AuthorizationException::class);
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, $code, 'Me demoré revisando los saldos');
    app(SubmitTimeReasonAction::class)->execute($cashier, $case, $code, 'Me demoré revisando los saldos');
    expect($case->fresh()->pending_minutes)->toBe(10)->and(TimeHistoryEvent::where('action', 'MOTIVO_O_REVISION')->count())->toBe(1);
    $key = app(TimeAppealQuery::class)->execute($cashier, $case)['appeal_key'];
    app(ReviewTimeAppealAction::class)->execute($admin, $case, $key, 'RECHAZAR', 'No corresponde anular el tiempo');
    expect($case->fresh()->pending_minutes)->toBe(10);
})->with(['conteo', 'otros']);

it('C134 reviews an early departure independently and notifies the owner without removing history', function () {
    [$admin,$cashier] = unifiedProfilePeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    $opening = c119Delay($admin, $cashier);
    $early = app(DetectTimeIncidentAction::class)->earlyDeparture($cashier, $opening->scheduledWorkday, CarbonImmutable::parse('2026-10-14 17:30', 'UTC'), 'test-approved');
    $original = TimeMovement::where('time_incident_id', $early->id)->pluck('id')->all();
    app(SubmitTimeReasonAction::class)->execute($cashier, $early, 'sistema', 'El sistema se interrumpió y se autorizó terminar antes');
    $key = app(TimeAppealQuery::class)->execute($cashier, $early)['appeal_key'];
    expect(app(PanelInboxQuery::class)->execute($admin)->where('category', 'time-appeal-review')->count())->toBe(1);
    app(ReviewTimeAppealAction::class)->execute($admin, $early, $key, 'ACEPTAR', 'Falla de servicio verificada', false, 'JUSTIFICAR');
    app(ReviewTimeAppealAction::class)->execute($admin, $early, $key, 'ACEPTAR', 'Falla de servicio verificada', false, 'JUSTIFICAR');
    $view = app(OwnTimeHistoryQuery::class)->overview($cashier);
    expect($view['early'])->toBe(0)->and($view['opening'])->toBe(10)->and($view['total'])->toBe(10)
        ->and(TimeMovement::whereIn('id', $original)->count())->toBe(count($original));
    expect(app(PanelInboxQuery::class)->execute($cashier)->where('category', 'time-appeal-response')->count())->toBe(1);
});
