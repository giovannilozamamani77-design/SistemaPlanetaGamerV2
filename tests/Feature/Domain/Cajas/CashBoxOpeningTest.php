<?php

use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\ConfirmCashBoxClosingAction;
use App\Domain\Cajas\Actions\ExplainOpeningDelayAction;
use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Actions\PrepareCashBoxClosingAccessAction;
use App\Domain\Cajas\Actions\RecordCashBoxOperationAction;
use App\Domain\Cajas\Actions\RecordQuickCashBoxCommissionAction;
use App\Domain\Cajas\Actions\RequestCashBoxEarlyCloseAction;
use App\Domain\Cajas\Actions\ResumeOwnCashBoxAccessAction;
use App\Domain\Cajas\Actions\ReviewCashBoxEarlyCloseAction;
use App\Domain\Cajas\Actions\SetCashBoxCommissionAccountAction;
use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Actions\UpdateCashBoxDetailsAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\CashBoxClosingAccessQuery;
use App\Domain\Cajas\Queries\CashBoxSessionTimingQuery;
use App\Domain\Cajas\Queries\CashBoxWorkspaceQuery;
use App\Domain\Configuraciones\Actions\SavePersonnelRulesAction;
use App\Domain\Configuraciones\Models\PersonnelRuleVersion;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Actions\ApplyCashBoxEarlyWorkAction;
use App\Domain\ControlHorario\Actions\ApplyTimeCreditAction;
use App\Domain\ControlHorario\Actions\ApproveLeaveAction;
use App\Domain\ControlHorario\Actions\AuthorizeShiftCoverageAction;
use App\Domain\ControlHorario\Actions\CaptureCashBoxContinuityAction;
use App\Domain\ControlHorario\Actions\DetectTimeIncidentAction;
use App\Domain\ControlHorario\Actions\ProcessCashBoxContinuityAction;
use App\Domain\ControlHorario\Actions\ProcessCashBoxScheduleEventAction;
use App\Domain\ControlHorario\Actions\RegisterLinkedAttendanceAction;
use App\Domain\ControlHorario\Actions\RequestLeaveAction;
use App\Domain\ControlHorario\Actions\SaveRecurringWorkShiftAction;
use App\Domain\ControlHorario\Actions\TimeHistoryAudit;
use App\Domain\ControlHorario\Actions\UseTimeCreditAction;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\CashBoxWorkdayContinuity;
use App\Domain\ControlHorario\Models\RecurringWorkShift;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\ShiftCoverage;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\ControlHorario\Queries\ShiftCoverageContext;
use App\Domain\Incidencias\Actions\EconomicHistoryAudit;
use App\Domain\Incidencias\Actions\JustifyOpeningShortageAction;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReviewEconomicIncidentAction;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Queries\OwnIncidentDetailQuery;
use App\Domain\Liquidaciones\Queries\ClosingLiquidationPreviewQuery;
use App\Filament\Pages\Cajas\CashBoxCatalog;
use App\Filament\Pages\Cajas\CashBoxClosingStart;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Filament\Pages\ControlHorario\ShiftCoverages;
use App\Filament\Resources\Cajas\EarlyRequests\Pages\ListCashBoxEarlyRequests;
use App\Filament\Resources\Incidencias\EconomicIncidents\Pages\ListEconomicIncidents;
use App\Filament\Widgets\Usuarios\OwnEconomicHistoryWidget;
use App\Filament\Widgets\Usuarios\OwnLeavesWidget;
use App\Models\User;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/La_Paz'));
    $this->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, CajasPermissionsSeeder::class]);
});

function c2Person(string $role = 'cajera'): User
{
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole($role);

    return $user;
}

function c2Settings(CashBox $box, array $replace = []): array
{
    $accounts = [];
    foreach (CashBoxComponents::forType($box->type) as $code => $name) {
        $accounts[$code] = ['name' => $name, 'initial' => match ($code) {
            'efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000', default => '18000'
        }];
    }

    return array_replace_recursive(['accounts' => $accounts, 'shifts' => CashBoxComponents::defaultShifts(), 'base_capital' => '20000',
        'expected_revision' => $box->revision, 'pin' => '1234', 'reason' => 'Configuración inicial de referencia', 'request_key' => (string) Str::uuid()], $replace);
}

function c2Configured(string $type = 'BCP'): CashBox
{
    $box = CashBox::factory()->create(['type' => $type]);
    app(ConfigureCashBoxAction::class)->execute(c2Person('administrador'), $box, c2Settings($box));

    return $box->fresh();
}

function c2Access(User $user, CashBox $box): CashBoxAccess
{
    return app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
}

function c2Open(User $user, CashBoxAccess $access, array $replace = []): CashBoxSession
{
    $balances = array_map(fn (int $cents): string => Bolivianos::format($cents), $access->expected_balances);

    return app(OpenCashBoxAction::class)->execute($user, $access->id, array_replace_recursive(['balances' => $balances, 'request_key' => (string) Str::uuid()], $replace));
}

function c2Workday(User $user, string $period = 'manana'): ScheduledWorkday
{
    return ScheduledWorkday::create(['user_id' => $user->id, 'created_by' => c2Person('administrador')->id, 'work_date' => '2026-10-15',
        'period' => $period, 'starts_at' => CarbonImmutable::parse('2026-10-15 '.($period === 'manana' ? '08:00' : '14:00'), 'America/La_Paz')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-10-15 '.($period === 'manana' ? '14:00' : '20:00'), 'America/La_Paz')->utc()]);
}

it('versions accounts capital and default shifts without creating money or sessions', function () {
    $box = CashBox::factory()->create();
    $admin = c2Person('administrador');
    $data = c2Settings($box);
    $version = app(ConfigureCashBoxAction::class)->execute($admin, $box, $data);
    $retry = app(ConfigureCashBoxAction::class)->execute($admin, $box, $data);
    expect($retry->id)->toBe($version->id)->and($version->shifts['manana']['liquidates'])->toBeFalse()
        ->and($version->shifts['tarde']['liquidates'])->toBeTrue()->and($version->accounts['bcp']['initial_cents'])->toBe(1500000);
    $this->assertDatabaseCount('cash_box_configurations', 1);
    $this->assertDatabaseCount('cash_box_accounts', 3);
    $this->assertDatabaseCount('cash_box_sessions', 0);
});

it('only permits administration to configure a box', function () {
    $user = c2Person();
    $box = CashBox::factory()->create();
    $user->givePermissionTo('cajas.catalogo.gestionar');
    expect(fn () => app(ConfigureCashBoxAction::class)->execute($user, $box, c2Settings($box)))->toThrow(AuthorizationException::class);
});

it('rejects overlapping shifts and incompatible accounts', function (array $change) {
    $box = CashBox::factory()->create();
    expect(fn () => app(ConfigureCashBoxAction::class)->execute(c2Person('administrador'), $box, c2Settings($box, $change)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_configurations', 0);
})->with([
    'overlap' => [['shifts' => ['manana' => ['end' => '15:00']]]],
    'reversed' => [['shifts' => ['tarde' => ['end' => '13:00']]]],
    'foreign account' => [['accounts' => ['tigo_money' => ['name' => 'Tigo', 'initial' => '100']]]],
    'negative amount' => [['accounts' => ['bcp' => ['initial' => '-1']]]],
]);

it('opens with real balances without assigning a financial turn', function () {
    $box = c2Configured();
    $user = c2Person();
    $day = c2Workday($user);
    $access = c2Access($user, $box);
    $session = c2Open($user, $access);
    expect($session->user_id)->toBe($user->id)->and($session->status)->toBe('ABIERTA')
        ->and($session->opening_balances['bcp'])->toBe(1500000)->and($session->scheduled_workday_id)->toBe($day->id);
    $this->assertDatabaseCount('attendances', 1);
    expect(TimeIncident::where('source_key', 'opening:'.$day->id)->sole()->calculation['reference'])->toBe('cash-box-session:'.$session->id);
    expect(array_key_exists('turn', $session->getAttributes()))->toBeFalse();
});

it('recovers the same session and balances after interruption without another opening or delay reference', function () {
    $user = c2Person();
    $box = c2Configured();
    c2Workday($user);
    $first = c2Open($user, c2Access($user, $box));
    $this->travel(30)->minutes();
    $recovery = c2Access($user, $box);
    $workspace = app(CashBoxWorkspaceQuery::class)->execute($user, $recovery->id);
    expect($recovery->cash_box_session_id)->toBe($first->id)->and($workspace['balances'])->toBe($first->opening_balances)
        ->and($first->fresh()->opened_at->equalTo($first->opened_at))->toBeTrue();
    $this->assertDatabaseCount('cash_box_sessions', 1);
    $this->assertDatabaseCount('attendances', 1);
    $this->assertDatabaseCount('time_incidents', 1);
});

it('denies another cashier supervisor and administrator while retaining their valid PIN attendance', function (string $role) {
    $box = c2Configured();
    $first = c2Person();
    $session = c2Open($first, c2Access($first, $box));
    $other = c2Person($role);
    if ($role === 'cajera') {
        $day = c2Workday($other);
    }
    expect(fn () => c2Access($other, $box))->toThrow(AuthorizationException::class);
    expect($session->fresh()->user_id)->toBe($first->id);
    if (isset($day)) {
        expect($day->attendance()->sole()->status)->toBe('PRESENTE_SIN_APERTURA');
    }
    expect($other->fresh()->can('panel.acceder'))->toBeTrue();
    $this->assertDatabaseCount('cash_box_sessions', 1);
})->with(['cajera', 'encargado', 'administrador']);

it('does not allow forging access ownership even with an administrative role', function () {
    $box = c2Configured();
    $access = c2Access(c2Person(), $box);
    $admin = c2Person('administrador');
    expect(fn () => c2Open($admin, $access))->toThrow(AuthorizationException::class);
    expect(fn () => app(CashBoxWorkspaceQuery::class)->execute($admin, $access->id))->toThrow(AuthorizationException::class);
});

it('rejects an incorrect PIN without attendance or access', function () {
    $user = c2Person();
    c2Workday($user);
    expect(fn () => app(StartCashBoxAccessAction::class)->execute($user, c2Configured(), '9999', (string) Str::uuid()))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('cash_box_accesses', 0);
});

it('preserves attendance when an inactive or unconfigured box rejects entry', function () {
    $user = c2Person();
    c2Workday($user);
    expect(fn () => c2Access($user, CashBox::factory()->create(['is_active' => false])))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('attendances', 1);
    $this->assertDatabaseCount('cash_box_sessions', 0);
});

it('does not create a debt for redistribution with an equal total', function () {
    $user = c2Person();
    $session = c2Open($user, c2Access($user, c2Configured()), ['balances' => ['efectivo' => '15000', 'bcp' => '2000', 'yape' => '3000']]);
    expect($session->comparison['redistribution_only'])->toBeTrue();
    $this->assertDatabaseCount('cash_box_receipt_discrepancies', 0);
    $this->assertDatabaseCount('economic_incidents', 0);
});

it('opens a high shortage without a second reading or manual observation', function () {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    $opened = c2Open($user, $access, ['balances' => ['bcp' => '14800']]);
    expect($opened->comparison['difference_cents'])->toBe(-20000);
    $this->assertDatabaseHas('cash_box_receipt_discrepancies', ['difference_cents' => -20000, 'status' => 'EN_REVISION']);
    $this->assertDatabaseCount('economic_incidents', 0);
});

it('accepts the exact threshold with a reason but no extra reading', function () {
    $user = c2Person();
    $session = c2Open($user, c2Access($user, c2Configured()), ['balances' => ['bcp' => '14900'], 'reason' => 'Diferencia de recepción']);
    expect($session->comparison['difference_cents'])->toBe(-10000);
});

it('keeps Tigo Money balances and time reference separate from BCP', function () {
    $user = c2Person();
    c2Workday($user);
    $session = c2Open($user, c2Access($user, c2Configured('TIGO_MONEY')));
    expect($session->opening_balances)->toHaveKeys(['efectivo', 'tigo_money'])->not->toHaveKey('bcp');
    $this->assertDatabaseCount('attendances', 1);
    $this->assertDatabaseCount('time_incidents', 0);
});

it('rolls back financial opening if recording its session event fails', function () {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    CashBoxSession::created(fn () => throw new RuntimeException('Interruption'));
    try {
        expect(fn () => c2Open($user, $access))->toThrow(RuntimeException::class);
    } finally {
        CashBoxSession::flushEventListeners();
    }
    $this->assertDatabaseCount('cash_box_sessions', 0);
    expect($access->fresh()->cash_box_session_id)->toBeNull();
    c2Open($user, $access);
    $this->assertDatabaseCount('cash_box_sessions', 1);
});

it('returns the same opening on replay and rejects altered payloads', function () {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    $data = ['balances' => ['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()];
    $first = app(OpenCashBoxAction::class)->execute($user, $access->id, $data);
    $retry = app(OpenCashBoxAction::class)->execute($user, $access->id, $data);
    expect($retry->id)->toBe($first->id);
    expect(fn () => app(OpenCashBoxAction::class)->execute($user, $access->id, array_replace($data, ['reason' => 'Alteración posterior'])))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_session_events', 1);
});

it('prevents stale configuration and config changes or deactivation while occupied', function () {
    $box = c2Configured();
    $user = c2Person();
    $access = c2Access($user, $box);
    $admin = c2Person('administrador');
    app(ConfigureCashBoxAction::class)->execute($admin, $box, c2Settings($box));
    expect(fn () => c2Open($user, $access))->toThrow(ValidationException::class);
    c2Open($user, c2Access($user, $box));
    expect(fn () => app(ConfigureCashBoxAction::class)->execute($admin, $box->fresh(), c2Settings($box->fresh())))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateCashBoxDetailsAction::class)->execute($admin, $box->fresh(),
        ['name' => 'Cambio', 'is_active' => false, 'expected_revision' => $box->fresh()->revision, 'pin' => '1234', 'reason' => 'Cambio administrativo', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
});

it('denies permission revoked after PIN validation', function () {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    $user->restrictedPermissions()->attach(Permission::findByName('cajas.operar', 'web'));
    expect(fn () => c2Open($user, $access))->toThrow(AuthorizationException::class);
    expect(fn () => app(CashBoxWorkspaceQuery::class)->execute($user, $access->id))->toThrow(AuthorizationException::class);
});

it('does not accept financial turn selection from the opening client', function () {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    expect(fn () => c2Open($user, $access, ['turn' => 'manana']))->toThrow(ValidationException::class);
});

it('requires a fresh PIN after the business date changes', function () {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    $this->travel(1)->days();
    expect(fn () => c2Open($user, $access))->toThrow(ValidationException::class);
});

it('enforces one occupied slot in the database', function () {
    $user = c2Person();
    $session = c2Open($user, c2Access($user, c2Configured()));
    expect(fn () => CashBoxSession::create(array_replace($session->getAttributes(), ['id' => null, 'request_key' => (string) Str::uuid()])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('uses released handover balances without exposing the previous cashier or assigning her shortage to the new one', function () {
    $box = c2Configured();
    $former = c2Person();
    $previous = c2Open($former, c2Access($former, $box));
    // Arrange the released-close contract; the closing workflow belongs to the next increment.
    $previous->update(['status' => 'CERRADA_CON_INCIDENCIAS', 'active_slot' => null, 'closed_at' => now(), 'released_at' => now(),
        'handover_balances' => ['bcp' => 1480000, 'efectivo' => 200000, 'yape' => 300000]]);
    $next = c2Person();
    $access = c2Access($next, $box);
    $session = c2Open($next, $access);
    expect($session->opening_balances['bcp'])->toBe(1480000)->and($session->comparison['difference_cents'])->toBe(0)
        ->and($session->previous_session_id)->toBe($previous->id);
    $workspace = app(CashBoxWorkspaceQuery::class)->execute($next, $access->id);
    expect($workspace)->not->toHaveKeys(['previous_user_id', 'previous_session_id', 'previous_debt']);
    $this->assertDatabaseCount('economic_incidents', 0);
});

it('refuses a recorded close pending administrative release even for its former owner', function () {
    $user = c2Person();
    $box = c2Configured();
    $session = c2Open($user, c2Access($user, $box));
    $session->update(['status' => 'CIERRE_REGISTRADO', 'closed_at' => now()]);
    expect(fn () => c2Access($user, $box))->toThrow(ValidationException::class);
    expect(fn () => c2Access(c2Person('administrador'), $box))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('cash_box_sessions', 1);
});

it('uses a single first BCP reference across multiple boxes in the same workday', function () {
    $user = c2Person();
    $day = c2Workday($user);
    $first = c2Open($user, c2Access($user, c2Configured()));
    $this->travel(10)->minutes();
    c2Open($user, c2Access($user, c2Configured()));
    $this->assertDatabaseCount('time_incidents', 1);
    expect(TimeIncident::sole()->calculation['reference'])->toBe('cash-box-session:'.$first->id);
    $this->assertDatabaseCount('attendances', 1);
});

it('registers both scheduled workdays without asking for a financial turn', function () {
    $user = c2Person();
    c2Workday($user);
    c2Workday($user, 'tarde');
    c2Open($user, c2Access($user, c2Configured()));
    $this->travel(5)->hours();
    c2Open($user, c2Access($user, c2Configured()));
    $this->assertDatabaseCount('attendances', 2);
    $this->assertDatabaseCount('time_incidents', 2);
});

it('keeps old configuration snapshots unchanged after a later closed-box update', function () {
    $box = c2Configured();
    $old = CashBoxConfiguration::where('cash_box_id', $box->id)->sole();
    $values = c2Settings($box, ['shifts' => ['tarde' => ['end' => '21:00']], 'base_capital' => '21000']);
    $new = app(ConfigureCashBoxAction::class)->execute(c2Person('administrador'), $box, $values);
    expect($old->fresh()->shifts['tarde']['end'])->toBe('20:00')->and($new->shifts['tarde']['end'])->toBe('21:00');
    expect($box->fresh()->base_capital_cents)->toBe(2100000);
});

it('accepts a surplus above the threshold without reentering balances', function () {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    $session = c2Open($user, $access, ['balances' => ['efectivo' => '2100.01']]);
    expect($session->comparison['difference_cents'])->toBe(10001);
});

it('allows opening without an invented schedule or attendance', function () {
    $user = c2Person();
    $session = c2Open($user, c2Access($user, c2Configured()));
    expect($session->scheduled_workday_id)->toBeNull();
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('time_incidents', 0);
});

it('opens with optional cash count and compares the received total despite redistributed components', function (string $bcp, int $difference) {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    $data = ['balances' => ['efectivo' => '1000', 'bcp' => $bcp], 'second_balances' => ['efectivo' => '1000', 'bcp' => $bcp], 'reason' => 'Conteo manual de recepcion', 'request_key' => (string) Str::uuid()];
    $session = c2Open($user, $access, $data);
    expect(c2Open($user, $access, $data)->id)->toBe($session->id);
    expect($session->comparison['difference_cents'])->toBe($difference)
        ->and($session->comparison['redistribution_only'])->toBe($difference === 0)
        ->and($session->comparison['count_details']['method'])->toBe('MANUAL');
    $this->assertDatabaseCount('cash_box_receipt_discrepancies', $difference === 0 ? 0 : 1);
    $this->assertDatabaseCount('cash_box_sessions', 1);
    $this->assertDatabaseCount('cash_box_session_events', 1);
    $this->assertDatabaseCount('economic_incidents', 0);
})->with([['16000', 0], ['15999', -100], ['16019', 1900]]);

function c87Reception(array $handover = []): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $box = c2Configured();
    $previousUser = c2Person();
    $previous = c2Open($previousUser, c2Access($previousUser, $box));
    $received = array_replace($previous->opening_balances, $handover);
    $previous->update(['status' => 'CERRADA', 'active_slot' => null, 'closed_at' => now(), 'released_at' => now(), 'handover_balances' => $received, 'current_balances' => $received]);
    $incoming = c2Person();

    return [$box, $previousUser, $previous, $incoming, c2Access($incoming, $box)];
}

it('automatically charges a net reception shortage to the previous cashier without altering capital', function () {
    [$box, $owner, $previous, $incoming, $access] = c87Reception();
    $data = ['balances' => ['bcp' => '14950', 'efectivo' => '2000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()];
    $session = app(OpenCashBoxAction::class)->execute($incoming, $access->id, $data);
    expect(app(OpenCashBoxAction::class)->execute($incoming, $access->id, $data)->id)->toBe($session->id);
    $case = EconomicIncident::sole();
    expect($case->user_id)->toBe($owner->id)->and($case->reported_by)->toBe($incoming->id)
        ->and($case->pending_cents)->toBe(5000)->and($case->status)->toBe('CONFIRMADA');
    expect($session->opening_balances['bcp'])->toBe(1495000)->and($session->operating_capital_cents)->toBe(2000000);
    $this->assertDatabaseHas('cash_box_receipt_discrepancies', ['cash_box_session_id' => $session->id, 'previous_session_id' => $previous->id, 'economic_incident_id' => $case->id]);
    $this->assertDatabaseCount('economic_incidents', 1);
    $this->assertDatabaseCount('cash_box_receipt_discrepancies', 1);
});

it('keeps reception surplus separate from service commissions and liquidates it only once', function () {
    [$box, $owner, $previous, $incoming, $access] = c87Reception();
    $session = c2Open($incoming, $access, ['balances' => ['bcp' => '15050']]);
    $this->assertDatabaseCount('economic_incidents', 0);
    $this->assertDatabaseCount('cash_box_operations', 0);
    $this->assertDatabaseHas('cash_box_receipt_discrepancies', ['cash_box_session_id' => $session->id, 'classification' => 'SOBRANTE']);
    app(RecordQuickCashBoxCommissionAction::class)->execute($incoming, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 200, 'request_key' => (string) Str::uuid()]);
    $workspace = app(CashBoxWorkspaceQuery::class)->execute($incoming, $access->id);
    expect($workspace['opening_surplus'])->toBe(5000)->and($workspace['commission_total'])->toBe(10000)
        ->and($workspace['commission_and_surplus_total'])->toBe(15000)->and($workspace['expected_total'])->toBe(2015000);
    expect($session->fresh()->operating_capital_cents)->toBe(2000000);
    $admin = c2Person('administrador');
    app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['destination' => '70000000', 'reason' => 'Destino ficticio', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $quote = app(ClosingLiquidationPreviewQuery::class)->execute($incoming, $session->id, 'tarde', ['bcp' => '15050', 'efectivo' => '2100', 'yape' => '3000']);
    expect($quote['calculation']['amount_cents'])->toBe(15000)->and($quote['calculation']['capital_after_cents'])->toBe(2000000);
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $closing = ['period' => 'tarde', 'balances' => ['bcp' => '15050', 'efectivo' => '2100', 'yape' => '3000'], 'readings' => ['general' => '100', 'fixed' => '50'],
        'expected_revision' => $session->fresh()->revision, 'liquidate' => true, 'account_id' => $quote['account']->id, 'amount_cents' => 15000,
        'transferred' => true, 'operation_reference' => 'C87-TRANSFERENCIA-FICTICIA', 'request_key' => (string) Str::uuid()];
    $close = app(ConfirmCashBoxClosingAction::class)->execute($incoming, $session->id, $closing);
    expect(app(ConfirmCashBoxClosingAction::class)->execute($incoming, $session->id, $closing)->id)->toBe($close->id);
    expect($close->commission_cents)->toBe(10000)->and($close->liquidated_cents)->toBe(15000)->and($close->realTotal())->toBe(2000000);
    $this->assertDatabaseCount('cash_box_operations', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 1);

});

it('does not recreate an already authorized shortage and charges only the new reception difference', function () {
    [$box, $owner, $previous, $incoming, $access] = c87Reception(['bcp' => 1480000]);
    $session = c2Open($incoming, $access);
    $this->assertDatabaseCount('economic_incidents', 0);
    expect($session->comparison['difference_cents'])->toBe(0);
    $session->update(['status' => 'CERRADA', 'active_slot' => null, 'closed_at' => now(), 'released_at' => now(), 'handover_balances' => $session->opening_balances]);
    $next = c2Person();
    c2Open($next, c2Access($next, $box), ['balances' => ['bcp' => '14750']]);
    expect(EconomicIncident::sole()->pending_cents)->toBe(5000);
    expect(EconomicIncident::sole()->user_id)->toBe($incoming->id);
});

it('rolls back both the opening and the automatic debt if the internal receipt writer fails', function () {
    [$box, $owner, $previous, $incoming, $access] = c87Reception();
    $this->mock(EconomicHistoryAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Simulated interruption'));
    expect(fn () => c2Open($incoming, $access, ['balances' => ['bcp' => '14950']]))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('cash_box_sessions', 1);
    $this->assertDatabaseCount('economic_incidents', 0);
    $this->assertDatabaseCount('cash_box_receipt_discrepancies', 0);
    expect($access->fresh()->cash_box_session_id)->toBeNull();
});

it('lets only the previous owner justify a reception debt and administration annul it without changing cash', function () {
    [$box, $owner, $previous, $incoming, $access] = c87Reception();
    $session = c2Open($incoming, $access, ['balances' => ['bcp' => '14950']]);
    $case = EconomicIncident::sole();
    $balances = $session->fresh()->current_balances;
    $action = app(JustifyOpeningShortageAction::class);
    $data = ['text' => 'El banco aplicó un cargo que solicito revisar.', 'request_key' => (string) Str::uuid()];
    expect(fn () => $action->execute($incoming, $case->id, $data))->toThrow(AuthorizationException::class);
    $action->execute($owner, $case->id, $data);
    $action->execute($owner, $case->id, $data);
    expect($case->fresh()->pending_cents)->toBe(5000)->and($case->fresh()->receipt_justification)->toBe($data['text']);
    expect(fn () => $action->execute($owner, $case->id, [...$data, 'text' => 'Otro justificativo']))->toThrow(ValidationException::class);
    $admin = c2Person('administrador');
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'ANULAR', 'Diferencia no atribuible a la cajera', null, false, '1234');
    expect($case->fresh()->pending_cents)->toBe(0)->and($case->fresh()->status)->toBe('ANULADA');
    expect($session->fresh()->current_balances)->toBe($balances);
    $this->assertDatabaseCount('economic_incidents', 1);
    $this->assertDatabaseHas('economic_history_events', ['economic_incident_id' => $case->id, 'action' => 'RECEPCION_JUSTIFICADA']);
});

it('records reception cents and does not give a cashier permission to report arbitrary debts for others', function () {
    [$box, $owner, $previous, $incoming, $access] = c87Reception();
    c2Open($incoming, $access, ['balances' => ['bcp' => '14999.90']]);
    expect(EconomicIncident::sole()->pending_cents)->toBe(10);
    expect(fn () => app(ReportEconomicIncidentAction::class)->execute($incoming, $owner, []))->toThrow(AuthorizationException::class);
});

it('renders the receipt justification in the owners profile and the administrative detail', function () {
    [$box, $owner, $previous, $incoming, $access] = c87Reception();
    c2Open($incoming, $access, ['balances' => ['bcp' => '14950']]);
    $case = EconomicIncident::sole();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($owner);
    Livewire::test(OwnEconomicHistoryWidget::class)
        ->callTableAction('apelar', $case, data: ['text' => 'Justificativo ficticio desde mi perfil', 'request_key' => (string) Str::uuid()])
        ->assertHasNoTableActionErrors();
    expect($case->fresh()->receipt_justification)->toBe('Justificativo ficticio desde mi perfil');
    $this->actingAs($incoming);
    Livewire::test(OwnEconomicHistoryWidget::class)->assertCanNotSeeTableRecords([$case]);
    $admin = c2Person('administrador');
    $this->actingAs($admin);
    $screen = Livewire::test(ListEconomicIncidents::class)->mountTableAction('detalle', $case)
        ->assertActionMounted(TestAction::make('detalle')->table($case));
    expect($screen->instance()->getSchema($screen->instance()->getMountedActionSchemaName())->toHtml())->toContain('Justificativo ficticio desde mi perfil');
});

it('does not create reception debt for an exact or redistributed authorized handover', function (array $balances) {
    [$box, $owner, $previous, $incoming, $access] = c87Reception();
    c2Open($incoming, $access, ['balances' => $balances]);
    $this->assertDatabaseCount('economic_incidents', 0);
    $this->assertDatabaseCount('cash_box_receipt_discrepancies', 0);
})->with([
    'exact' => [['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000']],
    'redistributed' => [['bcp' => '14000', 'efectivo' => '3000', 'yape' => '3000']],
]);

it('attributes reception to the prior session even when the same cashier returns', function () {
    [$box, $owner, $previous] = c87Reception();
    $session = c2Open($owner, c2Access($owner, $box), ['balances' => ['bcp' => '14950']]);
    $case = EconomicIncident::sole();
    expect($case->user_id)->toBe($owner->id)->and($case->policy_snapshot['previous_session_id'])->toBe($previous->id);
    expect($session->previous_session_id)->toBe($previous->id);
});

it('opens with schedule controls paused without attendance or time incidents', function () {
    config(['cajas.schedule_controls_enabled' => false]);
    $user = c2Person();
    c2Workday($user);
    $session = c2Open($user, c2Access($user, c2Configured()));
    expect($session->status)->toBe('ABIERTA')->and($session->scheduled_workday_id)->toBeNull();
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('time_incidents', 0);
});

it('recalculates and stores an optional opening count and keeps its retry idempotent', function () {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    $data = ['cash_count' => ['quantities' => ['b100' => '20'], 'packed_amounts_cents' => [], 'total_cents' => 1], 'request_key' => (string) Str::uuid()];
    $session = c2Open($user, $access, $data);
    expect($session->comparison['count_details']['cash_count']['total_cents'])->toBe(200000)
        ->and($session->comparison['count_details']['method'])->toBe('ARQUEO');
    expect(c2Open($user, $access, $data)->id)->toBe($session->id);
    expect(fn () => c2Open($user, $access, [...$data, 'cash_count' => ['quantities' => ['b200' => '10']]]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_sessions', 1);
});

it('rejects an incoherent or invalid optional opening count without recording anything', function (array $count) {
    $user = c2Person();
    $access = c2Access($user, c2Configured());
    expect(fn () => c2Open($user, $access, ['cash_count' => $count]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_sessions', 0);
    $this->assertDatabaseCount('cash_box_session_events', 0);
})->with([
    'different cash' => [['quantities' => ['b100' => '19']]],
    'negative packed value' => [['quantities' => ['b100' => '20'], 'packed_amounts_cents' => ['m1' => -1]]],
    'unknown denomination' => [['quantities' => ['fake' => '20']]],
]);

it('C102 shows an opening shortage separately from the previous cashiers closing and identifies who detected it', function () {
    [$box,$owner,$previous,$incoming,$access] = c87Reception();
    c2Open($incoming, $access, ['balances' => ['bcp' => '14950']]);
    $case = EconomicIncident::sole();
    $before = $case->getAttributes();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($owner);
    Livewire::test(OwnEconomicHistoryWidget::class)->assertSee('Detectado en apertura');
    $detail = app(OwnIncidentDetailQuery::class)->execute($owner, $case->id);
    expect($detail['origin']['label'])->toBe('Detectado en apertura')->and($detail['origin']['detected_by'])->toBe($incoming->name);
    expect(view('incidencias.personal-recoveries', $detail)->render())->toContain($incoming->name, 'Detectado en apertura');
    expect($case->fresh()->getAttributes())->toBe($before);
    expect(fn () => app(OwnIncidentDetailQuery::class)->execute($incoming, $case->id))->toThrow(AuthorizationException::class);
});

it('C110 preserves financial opening when time evaluation fails and retries its original instant', function () {
    $user = c2Person();
    $day = c2Workday($user);
    $box = c2Configured();
    $access = c2Access($user, $box);
    $this->mock(DetectTimeIncidentAction::class)
        ->shouldReceive('opening')->once()->andThrow(new RuntimeException('simulated time failure'));
    $session = c2Open($user, $access);
    expect($session->status)->toBe('ABIERTA')->and($session->opening_balances['efectivo'])->toBe(200000);
    $this->assertDatabaseCount('cash_box_sessions', 1);
    $this->assertDatabaseCount('time_incidents', 0);
});

it('C110 does not block a valid PIN access when linked attendance fails', function () {
    $user = c2Person();
    c2Workday($user);
    $box = c2Configured();
    $this->mock(RegisterLinkedAttendanceAction::class)
        ->shouldReceive('execute')->once()->andThrow(new RuntimeException('simulated attendance failure'));
    $access = c2Access($user, $box);
    expect($access->user_id)->toBe($user->id);
    $this->assertDatabaseCount('attendances', 0);
});

it('C110 retries a failed opening without duplicating attendance incident or money', function () {
    $user = c2Person();
    $day = c2Workday($user);
    $box = c2Configured();
    $access = c2Access($user, $box);
    $real = app(DetectTimeIncidentAction::class);
    $this->mock(DetectTimeIncidentAction::class)->shouldReceive('opening')->once()->andThrow(new RuntimeException('temporary failure'));
    $session = c2Open($user, $access);
    $source = CashBoxScheduleEvent::where('cash_box_session_id', $session->id)->sole();
    expect($source->status)->toBe('FAILED');
    expect(app(CashBoxSessionTimingQuery::class)->forSession($session)['status'])->toBe('PENDING');
    $this->travel(45)->minutes();
    app()->instance(DetectTimeIncidentAction::class, $real);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    $incident = TimeIncident::where('source_key', 'opening:'.$day->id)->sole();
    expect($incident->observed_at->equalTo($session->opened_at))->toBeTrue()
        ->and($incident->real_minutes)->toBe(105)
        ->and($source->fresh()->status)->toBe('DONE')
        ->and($session->fresh()->current_balances)->toBe($session->current_balances);
    $this->assertDatabaseCount('attendances', 1);
    $this->assertDatabaseCount('time_incidents', 1);
    $this->assertDatabaseCount('cash_box_sessions', 1);
});

it('C110 first confirmed BCP stays the unique reference when retrying several boxes out of order', function () {
    $user = c2Person();
    $day = c2Workday($user);
    $one = c2Configured();
    $two = c2Configured();
    $tigo = c2Configured('TIGO_MONEY');
    $runner = app(ProcessCashBoxScheduleEventAction::class);
    // Simulate interruption after the financial commit, before processing either source.
    $this->mock(ProcessCashBoxScheduleEventAction::class)->shouldReceive('safely')->andReturnNull();
    $first = c2Open($user, c2Access($user, $one));
    $this->travel(10)->minutes();
    $second = c2Open($user, c2Access($user, $two));
    $third = c2Open($user, c2Access($user, $tigo));
    $last = CashBoxScheduleEvent::where('cash_box_session_id', $third->id)->sole();
    app()->instance(ProcessCashBoxScheduleEventAction::class, $runner);
    $runner->execute($last->id);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    $incident = TimeIncident::where('source_key', 'opening:'.$day->id)->sole();
    expect($incident->calculation['reference'])->toBe('cash-box-session:'.$first->id)
        ->and($incident->observed_at->equalTo($first->opened_at))->toBeTrue();
    foreach ([$first, $second, $third] as $session) {
        expect($session->fresh()->scheduled_workday_id)->toBe($day->id);
    }
    $this->assertDatabaseCount('attendances', 1);
    $this->assertDatabaseCount('time_incidents', 1);
    $this->assertDatabaseCount('cash_box_sessions', 3);
});

it('C110 does not produce schedule sources while paused or backfill paused openings later', function () {
    config(['cajas.schedule_controls_enabled' => false]);
    $user = c2Person();
    c2Workday($user);
    $box = c2Configured();
    $access = c2Access($user, $box);
    $session = c2Open($user, $access);
    $this->assertDatabaseCount('cash_box_schedule_events', 0);
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('time_incidents', 0);
    $this->artisan('horario:reintentar-aperturas')->expectsOutput('Controles de Cajas pausados. No se procesaron eventos.')->assertSuccessful();
    config(['cajas.schedule_controls_enabled' => true]);
    $retry = c2Open($user, $access);
    expect($retry->id)->toBe($session->id);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    $this->assertDatabaseCount('cash_box_schedule_events', 0);
    $this->assertDatabaseCount('time_incidents', 0);
});

it('C110 same access and opening UUID retries have only one durable source each', function () {
    $user = c2Person();
    c2Workday($user);
    $box = c2Configured();
    $key = (string) Str::uuid();
    $a = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', $key);
    $b = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', $key);
    expect($a->id)->toBe($b->id);
    $openKey = (string) Str::uuid();
    $first = c2Open($user, $a, ['request_key' => $openKey]);
    $retry = c2Open($user, $a, ['request_key' => $openKey]);
    expect($first->id)->toBe($retry->id);
    $this->assertDatabaseCount('cash_box_schedule_events', 2);
    $this->assertDatabaseCount('attendances', 1);
    $this->assertDatabaseCount('time_incidents', 1);
});

it('C110 absence of a scheduled day never prevents opening and does not invent attendance', function () {
    $user = c2Person();
    $session = c2Open($user, c2Access($user, c2Configured()));
    expect($session->status)->toBe('ABIERTA')->and($session->scheduled_workday_id)->toBeNull();
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('time_incidents', 0);
    expect(CashBoxScheduleEvent::where('status', 'SKIPPED')->count())->toBe(2);
});

it('C110 rolls back partial schedule writes on failure but leaves the financial session usable', function () {
    $user = c2Person();
    c2Workday($user);
    $access = c2Access($user, c2Configured());
    $audit = app(TimeHistoryAudit::class);
    $this->mock(TimeHistoryAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable'));
    $session = c2Open($user, $access);
    $this->assertDatabaseCount('time_incidents', 0);
    expect($session->status)->toBe('ABIERTA');
    app()->instance(TimeHistoryAudit::class, $audit);
    // Rebind the calculator action that captured the failed audit dependency.
    app()->forgetInstance(DetectTimeIncidentAction::class);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    $this->assertDatabaseCount('time_incidents', 1);
});

it('C110 deferred attendance can be retried after commissions without changing financial balances', function () {
    $user = c2Person();
    $day = c2Workday($user);
    $box = c2Configured();
    $attendance = app(RegisterLinkedAttendanceAction::class);
    $this->mock(RegisterLinkedAttendanceAction::class)->shouldReceive('execute')->andThrow(new RuntimeException('offline schedule'));
    $access = c2Access($user, $box);
    $session = c2Open($user, $access);
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('time_incidents', 0);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id,
        ['fee_type' => 'ACH', 'movement' => 'DEPOSITO', 'amount' => '100', 'request_key' => (string) Str::uuid(), 'expected_revision' => $session->revision]);
    $money = $session->fresh()->current_balances;
    expect($money['efectivo'])->toBe(200200);
    config(['cajas.schedule_controls_enabled' => false]);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    $this->assertDatabaseCount('attendances', 0);
    config(['cajas.schedule_controls_enabled' => true]);
    app()->instance(RegisterLinkedAttendanceAction::class, $attendance);
    $this->travel(1)->hours();
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    expect($session->fresh()->current_balances)->toBe($money)
        ->and($session->fresh()->scheduled_workday_id)->toBe($day->id);
    $this->assertDatabaseCount('attendances', 1);
    $this->assertDatabaseCount('time_incidents', 1);
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('C110 at the tolerance boundary opens normally without any time debt', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 08:15', 'America/La_Paz'));
    $user = c2Person();
    $day = c2Workday($user);
    $session = c2Open($user, c2Access($user, c2Configured()));
    $incident = TimeIncident::where('source_key', 'opening:'.$day->id)->sole();
    expect($session->status)->toBe('ABIERTA')->and($incident->status)->toBe('SIN_RETRASO')
        ->and($incident->real_minutes)->toBe(0)->and($incident->pending_minutes)->toBe(0);
    $this->assertDatabaseCount('time_movements', 0);
});

it('C110 invalid PIN and rolled back opening never leave an opening source to retry', function () {
    $user = c2Person();
    c2Workday($user);
    $box = c2Configured();
    expect(fn () => app(StartCashBoxAccessAction::class)->execute($user, $box, '9999', (string) Str::uuid()))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_schedule_events', 0);
    $access = c2Access($user, $box);
    CashBoxSession::created(fn () => throw new RuntimeException('financial interruption'));
    try {
        expect(fn () => c2Open($user, $access))->toThrow(RuntimeException::class);
    } finally {
        CashBoxSession::flushEventListeners();
    }
    expect(CashBoxScheduleEvent::where('kind', 'OPENING')->count())->toBe(0);
    $this->assertDatabaseCount('cash_box_sessions', 0);
    $this->assertDatabaseCount('time_incidents', 0);
    $this->assertDatabaseCount('attendances', 1);
});

it('C110 recovers the earliest real PIN when its attendance is processed after a later box', function () {
    $user = c2Person();
    $day = c2Workday($user);
    $firstBox = c2Configured();
    $secondBox = c2Configured();
    $runner = app(ProcessCashBoxScheduleEventAction::class);
    $this->mock(ProcessCashBoxScheduleEventAction::class)->shouldReceive('safely')->andReturnNull();
    $firstAccess = c2Access($user, $firstBox);
    $first = c2Open($user, $firstAccess);
    $this->travel(20)->minutes();
    app()->instance(ProcessCashBoxScheduleEventAction::class, $runner);
    $secondAccess = c2Access($user, $secondBox);
    c2Open($user, $secondAccess);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    $attendance = $day->attendance()->sole();
    expect($attendance->first_pin_at->equalTo($firstAccess->verified_at))->toBeTrue();
    expect(TimeIncident::where('source_key', 'opening:'.$day->id)->sole()->calculation['reference'])->toBe('cash-box-session:'.$first->id);
    $this->assertDatabaseCount('attendances', 1);
});

it('C111 continues into the afternoon by resuming the same box without another PIN', function () {
    $user = c2Person();
    $morning = c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $box = c2Configured();
    $session = c2Open($user, c2Access($user, $box));
    $pinCount = DB::table('user_audits')->where('action', 'pin_verified')->count();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:20', 'America/La_Paz'));
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    expect($afternoon->attendance()->exists())->toBeTrue();
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe($pinCount);
    expect($session->fresh()->status)->toBe('ABIERTA')->and($session->fresh()->scheduled_workday_id)->toBe($morning->id);
    $this->assertDatabaseCount('cash_box_sessions', 1);
    $this->assertDatabaseCount('attendances', 2);
    expect(TimeIncident::where('source_key', 'opening:'.$afternoon->id)->sole()->real_minutes)->toBe(0);
});

it('C111 a commission in the afternoon links attendance without closing or changing its financial result', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $session = c2Open($user, c2Access($user, c2Configured()));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:30', 'America/La_Paz'));
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id,
        ['fee_type' => 'ACH', 'movement' => 'DEPOSITO', 'amount' => '100', 'request_key' => (string) Str::uuid(), 'expected_revision' => $session->revision]);
    expect($afternoon->attendance()->exists())->toBeTrue();
    expect($session->fresh()->current_balances['efectivo'])->toBe(200200);
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('C111 does not generate an absence for possible continuous attendance or invent presence from an idle open box', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    c2Open($user, c2Access($user, c2Configured()));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 20:30', 'America/La_Paz'));
    expect(app(DetectTimeIncidentAction::class)->absence($afternoon))->toBeNull();
    expect($afternoon->attendance()->exists())->toBeFalse();
    expect(TimeIncident::where('source_key', 'absence:'.$afternoon->id)->exists())->toBeFalse();
});

it('C111 several boxes share one afternoon attendance and repeat resume does not add PINs or history', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $one = c2Configured();
    $two = c2Configured();
    $first = c2Open($user, c2Access($user, $one));
    c2Open($user, c2Access($user, $two));
    $pinCount = DB::table('user_audits')->where('action', 'pin_verified')->count();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:01', 'America/La_Paz'));
    foreach ([$one, $two, $one, $two] as $box) {
        app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    }
    $this->assertDatabaseCount('attendances', 2);
    expect(TimeIncident::where('source_key', 'opening:'.$afternoon->id)->count())->toBe(1);
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe($pinCount);
    expect(DB::table('time_history_events')->where('action', 'CONTINUIDAD_DE_JORNADA')->count())->toBe(2);
    $timing = app(CashBoxSessionTimingQuery::class)->forSession($first->fresh());
    expect($timing['continuity'])->toBeTrue()->and($timing['scheduled_end'])->toBe('20:00');
});

it('C111 retries an attendance failure without blocking a commission or duplicating its source', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $session = c2Open($user, c2Access($user, c2Configured()));
    $real = app(RegisterLinkedAttendanceAction::class);
    $this->mock(RegisterLinkedAttendanceAction::class)->shouldReceive('execute')->andThrow(new RuntimeException('continuity failure'));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:05', 'America/La_Paz'));
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id,
        ['fee_type' => 'ACH', 'movement' => 'DEPOSITO', 'amount' => '100', 'request_key' => (string) Str::uuid(), 'expected_revision' => $session->revision]);
    expect($session->fresh()->current_balances['efectivo'])->toBe(200200);
    $link = CashBoxWorkdayContinuity::sole();
    expect($link->status)->toBe('FAILED')->and($afternoon->attendance()->exists())->toBeFalse();
    app()->instance(RegisterLinkedAttendanceAction::class, $real);
    $this->travel(1)->hours();
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    expect($link->fresh()->status)->toBe('DONE')->and($afternoon->attendance()->exists())->toBeTrue();
    $this->assertDatabaseCount('cash_box_workday_continuities', 1);
    $this->assertDatabaseCount('attendances', 2);
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('C111 replays persisted afternoon operations before detecting absences after a callback interruption', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $session = c2Open($user, c2Access($user, c2Configured()));
    $real = app(CaptureCashBoxContinuityAction::class);
    $this->mock(CaptureCashBoxContinuityAction::class)->shouldReceive('fromOperation')->andReturnNull();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:05', 'America/La_Paz'));
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id,
        ['fee_type' => 'ACH', 'movement' => 'DEPOSITO', 'amount' => '100', 'request_key' => (string) Str::uuid(), 'expected_revision' => $session->revision]);
    app()->instance(CaptureCashBoxContinuityAction::class, $real);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 20:30', 'America/La_Paz'));
    expect(app(DetectTimeIncidentAction::class)->absence($afternoon))->toBeNull();
    expect($afternoon->attendance()->exists())->toBeTrue();
    expect(TimeIncident::where('source_key', 'absence:'.$afternoon->id)->exists())->toBeFalse();
});

it('C111 paused sessions do not create continuity attendance or retroactive links', function () {
    config(['cajas.schedule_controls_enabled' => false]);
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $box = c2Configured();
    c2Open($user, c2Access($user, $box));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:05', 'America/La_Paz'));
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    $this->assertDatabaseCount('cash_box_workday_continuities', 0);
    config(['cajas.schedule_controls_enabled' => true]);
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    expect($afternoon->attendance()->exists())->toBeFalse();
    $this->assertDatabaseCount('cash_box_workday_continuities', 0);
});

it('C111 a session closed before the boundary does not excuse a real afternoon absence', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $session = c2Open($user, c2Access($user, c2Configured()));
    $session->update(['status' => 'CERRADA', 'active_slot' => null, 'closed_at' => CarbonImmutable::parse('2026-10-15 13:59', 'America/La_Paz')->utc()]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 20:30', 'America/La_Paz'));
    expect(app(DetectTimeIncidentAction::class)->absence($afternoon)->kind)->toBe('FALTA');
    $this->assertDatabaseCount('cash_box_workday_continuities', 0);
});

it('C111 valid PIN for a new box recognizes the existing continuous box before creating a false late reference', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $one = c2Configured();
    $two = c2Configured();
    c2Open($user, c2Access($user, $one));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:30', 'America/La_Paz'));
    c2Open($user, c2Access($user, $two));
    expect(TimeIncident::where('source_key', 'opening:'.$afternoon->id)->sole()->real_minutes)->toBe(0);
    $this->assertDatabaseCount('attendances', 2);
});

it('C111 an interrupted continuity cannot be replaced by a false late opening from another box', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $firstBox = c2Configured();
    $otherBox = c2Configured();
    c2Open($user, c2Access($user, $firstBox));
    $real = app(RegisterLinkedAttendanceAction::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:30', 'America/La_Paz'));
    // The new PIN attendance succeeds; only continuity evaluation is interrupted.
    $processor = app(ProcessCashBoxContinuityAction::class);
    $this->mock(ProcessCashBoxContinuityAction::class)
        ->shouldReceive('safely')->andReturnNull()->getMock()->shouldReceive('execute')->andThrow(new RuntimeException('continuity unavailable'));
    $second = c2Open($user, c2Access($user, $otherBox));
    expect($second->status)->toBe('ABIERTA');
    expect(TimeIncident::where('source_key', 'opening:'.$afternoon->id)->exists())->toBeFalse();
    app()->instance(ProcessCashBoxContinuityAction::class, $processor);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    expect(TimeIncident::where('source_key', 'opening:'.$afternoon->id)->sole()->real_minutes)->toBe(0);
});

it('C111 absence recovery checks all boxes instead of stopping at an idle first box', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    c2Open($user, c2Access($user, c2Configured()));
    $second = c2Open($user, c2Access($user, c2Configured()));
    $capture = app(CaptureCashBoxContinuityAction::class);
    $this->mock(CaptureCashBoxContinuityAction::class)->shouldReceive('fromOperation')->andReturnNull();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:10', 'America/La_Paz'));
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $second->id,
        ['fee_type' => 'ACH', 'movement' => 'DEPOSITO', 'amount' => '100', 'request_key' => (string) Str::uuid(), 'expected_revision' => $second->revision]);
    app()->instance(CaptureCashBoxContinuityAction::class, $capture);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 20:30', 'America/La_Paz'));
    expect(app(DetectTimeIncidentAction::class)->absence($afternoon))->toBeNull();
    expect($afternoon->attendance()->exists())->toBeTrue();
});

it('C111 another cashier cannot inherit continuity and the original PIN is not carried into tomorrow', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $box = c2Configured();
    $session = c2Open($user, c2Access($user, $box));
    $other = c2Person();
    $otherDay = c2Workday($other, 'tarde');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:30', 'America/La_Paz'));
    expect(app(ResumeOwnCashBoxAccessAction::class)->execute($other, $box->id))->toBeNull();
    expect($otherDay->attendance()->exists())->toBeFalse();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 14:30', 'America/La_Paz'));
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    expect($afternoon->attendance()->exists())->toBeFalse();
    $this->assertDatabaseCount('cash_box_workday_continuities', 0);
});

it('C111 audit failure rolls back partial afternoon attendance and reference but not the commission', function () {
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $session = c2Open($user, c2Access($user, c2Configured()));
    $audit = app(TimeHistoryAudit::class);
    $this->mock(TimeHistoryAudit::class)->shouldReceive('record')->andReturnUsing(
        function ($userId, $actorId, $action, $key, $details) use ($audit) {
            if ($action === 'CONTINUIDAD_DE_JORNADA') {
                throw new RuntimeException('final audit unavailable');
            }
            $audit->record($userId, $actorId, $action, $key, $details);
        }
    );
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:10', 'America/La_Paz'));
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id,
        ['fee_type' => 'ACH', 'movement' => 'DEPOSITO', 'amount' => '100', 'request_key' => (string) Str::uuid(), 'expected_revision' => $session->revision]);
    expect($afternoon->attendance()->exists())->toBeFalse();
    expect(TimeIncident::where('source_key', 'opening:'.$afternoon->id)->exists())->toBeFalse();
    expect($session->fresh()->current_balances['efectivo'])->toBe(200200);
    app()->instance(TimeHistoryAudit::class, $audit);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    expect($afternoon->attendance()->exists())->toBeTrue();
    expect(TimeIncident::where('source_key', 'opening:'.$afternoon->id)->count())->toBe(1);
});

it('C112 excluded boxes keep operating without attendance and included boxes evaluate normally', function () {
    $user = c2Person();
    c2Workday($user);
    $included = c2Configured();
    $excluded = c2Configured();
    app(SavePersonnelRulesAction::class)->execute(c2Person('administrador'),
        ['tolerance_minutes' => 15, 'schedule_enabled' => true, 'schedule_cash_box_ids' => [$included->id],
            'reason' => 'Alcance de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $outside = c2Open($user, c2Access($user, $excluded));
    expect($outside->status)->toBe('ABIERTA');
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('cash_box_schedule_events', 0);
    expect(app(CashBoxSessionTimingQuery::class)->forSession($outside)['status'])->toBe('DISABLED');
    c2Open($user, c2Access($user, $included));
    $this->assertDatabaseCount('attendances', 1);
    $this->assertDatabaseCount('time_incidents', 1);
});

it('C113 a disabled morning keeps only context and can continue into enabled afternoon without a new PIN', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'America/La_Paz'));
    $user = c2Person();
    $morning = c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $box = c2Configured();
    app(SavePersonnelRulesAction::class)->execute(c2Person('administrador'),
        ['tolerance_minutes' => 15, 'morning_control_enabled' => false, 'afternoon_control_enabled' => true,
            'reason' => 'Solo tarde en prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/La_Paz'));
    $session = c2Open($user, c2Access($user, $box));
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('time_incidents', 0);
    expect(app(CashBoxSessionTimingQuery::class)->forSession($session)['status'])->toBe('TURN_DISABLED');
    $pins = DB::table('user_audits')->where('action', 'pin_verified')->count();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:20', 'America/La_Paz'));
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    expect($afternoon->attendance()->exists())->toBeTrue()->and($morning->attendance()->exists())->toBeFalse();
    expect(TimeIncident::where('source_key', 'opening:'.$afternoon->id)->sole()->real_minutes)->toBe(0);
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe($pins);
    expect($session->fresh()->status)->toBe('ABIERTA');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 21:00', 'America/La_Paz'));
    expect(app(DetectTimeIncidentAction::class)->absence($morning))->toBeNull();
    expect(TimeIncident::where('source_key', 'absence:'.$morning->id)->exists())->toBeFalse();
});

it('C113 a disabled afternoon does not create continuity attendance absence or early departure debt', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'America/La_Paz'));
    $user = c2Person();
    c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $box = c2Configured();
    app(SavePersonnelRulesAction::class)->execute(c2Person('administrador'),
        ['tolerance_minutes' => 15, 'morning_control_enabled' => true, 'afternoon_control_enabled' => false,
            'reason' => 'Solo mañana en prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/La_Paz'));
    c2Open($user, c2Access($user, $box));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:20', 'America/La_Paz'));
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    expect($afternoon->attendance()->exists())->toBeFalse();
    $this->assertDatabaseCount('cash_box_workday_continuities', 0);
    expect(app(DetectTimeIncidentAction::class)->earlyDeparture($user, $afternoon, now('UTC')->toImmutable(), 'fictitious-approval'))->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 21:00', 'America/La_Paz'));
    expect(app(DetectTimeIncidentAction::class)->absence($afternoon))->toBeNull();
    expect(TimeIncident::where('scheduled_workday_id', $afternoon->id)->exists())->toBeFalse();
});

it('C116 records PIN arrival then automatic late debt once for multiple boxes', function () {
    $user = c2Person();
    $box = c2Configured();
    $day = c2Workday($user);
    $day->update(['starts_at' => now('UTC')->toImmutable(), 'ends_at' => now('UTC')->addMinutes(2)]);
    DB::table('personnel_rule_versions')->insert(['effective_from' => now()->subDay(), 'values' => json_encode(['tolerance_minutes' => 0, 'delay_multiplier' => 2, 'multiplier_minutes' => null, 'automatic_credit' => false]), 'created_by' => c2Person('administrador')->id, 'request_key' => (string) Str::uuid(), 'reason' => 'QA corto', 'created_at' => now()]);
    $access = c2Access($user, $box);
    expect($day->attendance->first_pin_at->equalTo(now()))->toBeTrue();
    $this->assertDatabaseCount('time_incidents', 0);
    $this->travel(1)->minutes();
    $session = c2Open($user, $access);
    $incident = TimeIncident::where('source_key', 'opening:'.$day->id)->sole();
    expect($incident->status)->toBe('CONFIRMADA')->and($incident->pending_minutes)->toBe(2);
    $other = c2Configured();
    c2Open($user, c2Access($user, $other));
    expect(TimeIncident::where('source_key', 'opening:'.$day->id)->count())->toBe(1);
    expect(TimeMovement::where('event_key', 'confirm:'.$incident->id)->sum('delta_minutes'))->toBe(2);
});

it('C116 gates early closing requests and admits a one minute boundary without financial writes', function () {
    $user = c2Person();
    $box = c2Configured();
    $day = c2Workday($user);
    $day->update(['starts_at' => now('UTC')->toImmutable(), 'ends_at' => now('UTC')->addMinute()]);
    DB::table('personnel_rule_versions')->insert(['effective_from' => now()->subDay(), 'values' => json_encode(['morning_closing_minutes' => 0]), 'created_by' => c2Person('administrador')->id, 'request_key' => (string) Str::uuid(), 'reason' => 'QA corto', 'created_at' => now()]);
    $session = c2Open($user, c2Access($user, $box));
    $gate = app(CashBoxClosingAccessQuery::class);
    expect($gate->execute($user, $session->id)['allowed'])->toBeFalse();
    $data = ['reason' => 'Urgencia de prueba', 'request_key' => (string) Str::uuid()];
    $action = app(RequestCashBoxEarlyCloseAction::class);
    $request = $action->execute($user, $session->id, $data);
    expect($action->execute($user, $session->id, $data)->id)->toBe($request->id);
    expect($gate->execute($user, $session->id)['allowed'])->toBeFalse();
    app(ReviewCashBoxEarlyCloseAction::class)->execute(c2Person('administrador'), $request->id, ['decision' => 'APROBADA', 'reason' => 'Permiso QA', 'request_key' => (string) Str::uuid()]);
    expect($gate->execute($user, $session->id)['allowed'])->toBeTrue();
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
    $this->assertDatabaseCount('cash_box_early_requests', 1);
    expect($session->fresh()->status)->toBe('ABIERTA');
    $this->travel(1)->minutes();
    expect($gate->execute($user, $session->id)['early'])->toBeFalse();
});

it('C116 keeps paused closing direct and creates no early request or attendance', function () {
    config(['cajas.schedule_controls_enabled' => false]);
    $user = c2Person();
    $box = c2Configured();
    c2Workday($user);
    $session = c2Open($user, c2Access($user, $box));
    expect(app(CashBoxClosingAccessQuery::class)->execute($user, $session->id)['allowed'])->toBeTrue();
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('time_incidents', 0);
    $this->assertDatabaseCount('cash_box_early_requests', 0);
});

it('C116 redirects an early closing URL and exposes the request modal on the owning box', function () {
    $user = c2Person();
    $box = c2Configured();
    $day = c2Workday($user);
    $session = c2Open($user, c2Access($user, $box));
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($user);
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])
        ->assertRedirect(CashBoxOperations::getUrl(['cashBox' => $box->id]));
    $page = Livewire::withQueryParams(['cashBox' => $box->id])->test(CashBoxOperations::class)
        ->assertActionMounted('lateOpeningNotice')->callMountedAction()
        ->mountAction('iniciarCierre')->assertActionMounted('solicitarCierreAnticipado');
    expect($page->getMountedActionModalHtml())->toContain('Aún faltan')->toContain('Seguir trabajando');
    $page->setActionData(['reason' => 'Salida anticipada de prueba'])->callMountedAction()->assertHasNoActionErrors();
    $this->assertDatabaseCount('cash_box_early_requests', 1);
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('C116 denies another cashier requests and administrative decisions and preserves rejection history', function () {
    $user = c2Person();
    $box = c2Configured();
    c2Workday($user);
    $session = c2Open($user, c2Access($user, $box));
    $other = c2Person();
    $action = app(RequestCashBoxEarlyCloseAction::class);
    $data = ['reason' => 'Urgencia personal', 'request_key' => (string) Str::uuid()];
    expect(fn () => $action->execute($other, $session->id, $data))->toThrow(AuthorizationException::class);
    expect(fn () => $action->execute($user, $session->id, ['reason' => '   ', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $request = $action->execute($user, $session->id, $data);
    $review = app(ReviewCashBoxEarlyCloseAction::class);
    $decision = ['decision' => 'RECHAZADA', 'reason' => 'Debe continuar el turno', 'request_key' => (string) Str::uuid()];
    expect(fn () => $review->execute($other, $request->id, $decision))->toThrow(AuthorizationException::class);
    $admin = c2Person('administrador');
    $review->execute($admin, $request->id, $decision);
    $review->execute($admin, $request->id, $decision);
    expect($request->fresh()->status)->toBe('RECHAZADA');
    expect(app(CashBoxClosingAccessQuery::class)->execute($user, $session->id)['allowed'])->toBeFalse();
    expect(DB::table('cash_box_audits')->where('action', 'CIERRE_ANTICIPADO_RECHAZADA')->count())->toBe(1);
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('C116 administrative screen scopes requests and presents review in Spanish', function () {
    $user = c2Person();
    $box = c2Configured();
    c2Workday($user);
    $session = c2Open($user, c2Access($user, $box));
    $request = app(RequestCashBoxEarlyCloseAction::class)->execute($user, $session->id, ['reason' => 'Urgencia de prueba', 'request_key' => (string) Str::uuid()]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs(c2Person('administrador'));
    Livewire::test(ListCashBoxEarlyRequests::class)->assertSee($box->name)
        ->callAction(TestAction::make('approve')->table($request), ['reason' => 'Permiso concedido en prueba'])->assertHasNoActionErrors();
    expect($request->fresh()->status)->toBe('APROBADA');
    $this->actingAs(c2Person());
    Livewire::test(ListCashBoxEarlyRequests::class)->assertForbidden();
});

it('C116 displays the automatic late notice after confirming opening and does not replay it when resuming', function () {
    $user = c2Person();
    $box = c2Configured();
    c2Workday($user);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($user);
    $page = Livewire::test(CashBoxCatalog::class)
        ->callAction(TestAction::make('ingresar')->table($box), ['pin' => '1234'])
        ->set('openingBalances', ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'])
        ->call('confirmOpening')->assertActionMounted('lateOpeningNotice');
    expect($page->getMountedActionModalHtml())->toContain('Apertura con retraso')->toContain('Entendido, continuar');
    $page->callMountedAction()->assertHasNoActionErrors();
    Livewire::withQueryParams(['cashBox' => $box->id])->test(CashBoxOperations::class)->assertActionNotMounted('lateOpeningNotice');
});

it('C116 closes normally exactly at a one minute limit and an old morning permission cannot authorize afternoon', function () {
    $user = c2Person();
    $box = c2Configured();
    $morning = c2Workday($user);
    $afternoon = c2Workday($user, 'tarde');
    $session = c2Open($user, c2Access($user, $box));
    $request = app(RequestCashBoxEarlyCloseAction::class)->execute($user, $session->id, ['reason' => 'Solo mañana', 'request_key' => (string) Str::uuid()]);
    app(ReviewCashBoxEarlyCloseAction::class)->execute(c2Person('administrador'), $request->id, ['decision' => 'APROBADA', 'reason' => 'Permiso mañana', 'request_key' => (string) Str::uuid()]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:00', 'America/La_Paz'));
    $gate = app(PrepareCashBoxClosingAccessAction::class)->execute($user, $session->id);
    expect($gate['period'])->toBe('tarde')->and($gate['workday_id'])->toBe($afternoon->id)->and($gate['allowed'])->toBeFalse();
    expect(fn () => app(CashBoxClosingAccessQuery::class)->assertAllowed($user, $session->id, 'manana'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('C116 accepts the exact one minute closing boundary without permission', function () {
    $user = c2Person();
    $box = c2Configured();
    $day = c2Workday($user);
    $day->update(['starts_at' => now('UTC'), 'ends_at' => now('UTC')->addMinute()]);
    DB::table('personnel_rule_versions')->insert(['created_by' => $user->id, 'request_key' => (string) Str::uuid(), 'reason' => 'QA un minuto',
        'values' => json_encode(['morning_closing_minutes' => 0]), 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    $session = c2Open($user, c2Access($user, $box));
    $this->travel(1)->minutes();
    $gate = app(CashBoxClosingAccessQuery::class)->execute($user, $session->id);
    expect($gate['allowed'])->toBeTrue()->and($gate['early'])->toBeFalse();
    $this->assertDatabaseCount('cash_box_early_requests', 0);
});

function c117Early(User $user, string $time = '13:57'): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-15 '.$time, 'America/La_Paz'));
    $day = c2Workday($user, 'tarde');
    $box = c2Configured();
    $session = c2Open($user, c2Access($user, $box));

    return [$day, $box, $session];
}

it('C117 accrues only completed early work and unions multiple boxes before automatically compensating debt', function () {
    $user = c2Person();
    [$day, $box, $session] = c117Early($user);
    $debt = TimeIncident::create(['user_id' => $user->id, 'scheduled_workday_id' => $day->id, 'kind' => 'RETRASO', 'status' => 'CONFIRMADA',
        'source_key' => 'C117:previous-debt', 'real_minutes' => 5, 'nominal_minutes' => 5, 'pending_minutes' => 5, 'calculation' => [], 'observed_at' => now()]);
    TimeMovement::create(['user_id' => $user->id, 'time_incident_id' => $debt->id, 'bucket' => 'RETRASO', 'delta_minutes' => 5, 'event_key' => 'confirm:'.$debt->id, 'metadata' => ['fixture' => 'C117'], 'created_at' => now()]);
    $this->assertDatabaseCount('time_movements', 1);
    $this->travel(1)->minutes();
    $op = ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()];
    $action = app(RecordQuickCashBoxCommissionAction::class);
    $first = $action->execute($user, $session->id, $op);
    expect($debt->fresh()->pending_minutes)->toBe(4);
    $other = c2Open($user, c2Access($user, c2Configured()));
    $this->travel(2)->minutes();
    $action->execute($user, $other->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $action->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $action->execute($user, $session->id, $op);
    expect($debt->fresh()->pending_minutes)->toBe(2);
    expect((int) TimeMovement::where('user_id', $user->id)->where('bucket', 'CREDITO')->where('delta_minutes', '>', 0)->sum('delta_minutes'))->toBe(3);
    expect((int) TimeMovement::where('user_id', $user->id)->where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(0);
    $this->assertDatabaseCount('cash_box_operations', 3);
});

it('C117 gives no credit for PIN opening idle resume or operations after an on time opening', function () {
    $user = c2Person();
    [$day, $box, $session] = c117Early($user);
    $this->travel(3)->minutes();
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    expect(TimeMovement::where('bucket', 'CREDITO')->count())->toBe(0);
    $other = c2Open($user, c2Access($user, c2Configured()));
    $this->travel(2)->minutes();
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $other->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    expect(TimeMovement::where('bucket', 'CREDITO')->count())->toBe(0);
});

it('C117 preserves the manual mode and applies only the owners existing credit once', function () {
    $user = c2Person();
    PersonnelRuleVersion::create(['created_by' => $user->id, 'reason' => 'QA credito manual', 'request_key' => (string) Str::uuid(), 'values' => ['automatic_credit' => false], 'effective_from' => now()->subDay()]);
    app(PersonnelRules::class)->forget();
    [$day, $box, $session] = c117Early($user);
    $debt = TimeIncident::create(['user_id' => $user->id, 'scheduled_workday_id' => $day->id, 'kind' => 'RETRASO', 'status' => 'CONFIRMADA', 'source_key' => 'C117:manual-debt',
        'real_minutes' => 5, 'nominal_minutes' => 5, 'pending_minutes' => 5, 'calculation' => [], 'observed_at' => now()]);
    TimeMovement::create(['user_id' => $user->id, 'time_incident_id' => $debt->id, 'bucket' => 'RETRASO', 'delta_minutes' => 5, 'event_key' => 'confirm:'.$debt->id, 'metadata' => ['fixture' => 'C117'], 'created_at' => now()]);
    $this->travel(3)->minutes();
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    expect($debt->fresh()->pending_minutes)->toBe(5);
    expect((int) TimeMovement::where('user_id', $user->id)->where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(3);
    $key = (string) Str::uuid();
    $action = app(UseTimeCreditAction::class);
    expect(fn () => $action->execute(c2Person(), $user, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect($action->execute($user, $user, $key))->toBe(3);
    expect($action->execute($user, $user, $key))->toBe(3);
    expect($debt->fresh()->pending_minutes)->toBe(2);
});

it('C117 creates no early credit sources or backfill for a session opened while controls were paused', function () {
    config(['cajas.schedule_controls_enabled' => false]);
    $user = c2Person();
    [$day, $box, $session] = c117Early($user);
    $this->travel(1)->minutes();
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    config(['cajas.schedule_controls_enabled' => true]);
    $this->travel(2)->minutes();
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    expect(TimeMovement::where('bucket', 'CREDITO')->count())->toBe(0);
    expect(CashBoxScheduleEvent::where('kind', 'WORK')->count())->toBe(0);
});

it('C117 persists early work evidence before callback failure and retries without undoing or duplicating commissions', function () {
    $user = c2Person();
    [$day, $box, $session] = c117Early($user);
    $this->travel(2)->minutes();
    $real = app(ApplyTimeCreditAction::class);
    $this->mock(ApplyTimeCreditAction::class)->shouldReceive('execute')->andThrow(new RuntimeException('C117 interrupted credit'));
    $data = ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()];
    $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, $data);
    $source = CashBoxScheduleEvent::where('source_key', 'work-operation:'.$operation->id)->sole();
    expect($source->status)->toBe('FAILED');
    expect($session->fresh()->current_balances['efectivo'])->toBe(200050);
    $this->assertDatabaseCount('cash_box_operations', 1);
    expect(TimeMovement::where('bucket', 'CREDITO')->count())->toBe(0);
    app()->instance(ApplyTimeCreditAction::class, $real);
    $processor = app(ProcessCashBoxScheduleEventAction::class);
    $processor->execute($source->id);
    $processor->execute($source->id);
    expect($source->fresh()->status)->toBe('DONE');
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(2);
    expect(app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, $data)->id)->toBe($operation->id);
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(2);
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('C117 credits whole minutes from the union of verified seconds and stops capturing after the turn starts', function () {
    $user = c2Person();
    [$day, $box, $session] = c117Early($user, '13:59');
    $this->travel(30)->seconds();
    $action = app(RecordQuickCashBoxCommissionAction::class);
    $action->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(0);
    $this->travel(30)->seconds();
    $action->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(1);
    $count = CashBoxScheduleEvent::where('kind', 'WORK')->count();
    $this->travel(7)->hours();
    $action->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(1);
    expect(CashBoxScheduleEvent::where('kind', 'WORK')->count())->toBe($count);
});

it('C117 does not credit the scheduled morning shift again as anticipation for afternoon', function () {
    $user = c2Person();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 08:00', 'America/La_Paz'));
    c2Workday($user);
    c2Workday($user, 'tarde');
    $box = c2Configured();
    $session = c2Open($user, c2Access($user, $box));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:00', 'America/La_Paz'));
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $this->assertDatabaseCount('attendances', 2);
    expect(TimeMovement::where('bucket', 'CREDITO')->count())->toBe(0);
});

it('C117 rejects a work source that belongs to another cashier without granting credit', function () {
    $user = c2Person();
    [$day, $box, $session] = c117Early($user);
    $this->travel(1)->minutes();
    $op = app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $source = CashBoxScheduleEvent::where('source_key', 'work-operation:'.$op->id)->sole();
    $before = TimeMovement::count();
    expect(fn () => app(ApplyCashBoxEarlyWorkAction::class)->execute(c2Person(), $source))->toThrow(RuntimeException::class);
    expect(TimeMovement::count())->toBe($before);
});

function c117CloseData(CashBoxSession $session): array
{
    return ['period' => 'tarde', 'balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'],
        'readings' => ['general' => '1', 'fixed' => '0'], 'expected_revision' => $session->fresh()->revision,
        'liquidate' => true, 'amount_cents' => 0, 'transferred' => false, 'request_key' => (string) Str::uuid()];
}

it('C117 credits the verified early interval at effective closing without including ordinary or later hours', function () {
    $user = c2Person();
    [$day, $box, $session] = c117Early($user);
    $before = $session->current_balances;
    $this->travelTo(CarbonImmutable::parse('2026-10-15 20:00', 'America/La_Paz'));
    expect(TimeMovement::where('bucket', 'CREDITO')->count())->toBe(0);
    $data = c117CloseData($session);
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($session->fresh()->status)->toBe('CERRADA');
    expect($session->fresh()->current_balances)->toBe($before);
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(3);
    expect(app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data)->id)->toBe($close->id);
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(3);
    expect(CashBoxScheduleEvent::where('kind', 'WORK')->count())->toBe(1);
    $this->assertDatabaseCount('cash_box_closes', 1);
});

it('C117 a failed closing credit never undoes money and is recoverable through the existing retry command', function () {
    $user = c2Person();
    [$day, $box, $session] = c117Early($user);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 20:00', 'America/La_Paz'));
    $real = app(ApplyTimeCreditAction::class);
    $this->mock(ApplyTimeCreditAction::class)->shouldReceive('execute')->andThrow(new RuntimeException('C117 close credit interruption'));
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, c117CloseData($session));
    $source = CashBoxScheduleEvent::where('source_key', 'work-close:'.$close->id)->sole();
    expect($source->status)->toBe('FAILED');
    expect($session->fresh()->status)->toBe('CERRADA');
    $this->assertDatabaseCount('cash_box_closes', 1);
    expect(TimeMovement::where('bucket', 'CREDITO')->count())->toBe(0);
    app()->instance(ApplyTimeCreditAction::class, $real);
    config(['cajas.schedule_controls_enabled' => false]);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    expect($source->fresh()->status)->toBe('FAILED');
    config(['cajas.schedule_controls_enabled' => true]);
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    $this->artisan('horario:reintentar-aperturas')->assertSuccessful();
    expect($source->fresh()->status)->toBe('DONE');
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(3);
    $this->assertDatabaseCount('cash_box_closes', 1);
});

it('C117 disabled turns produce no credit even if the box remains enabled', function () {
    $user = c2Person();
    PersonnelRuleVersion::create(['created_by' => $user->id, 'request_key' => (string) Str::uuid(), 'reason' => 'Turno desactivado QA', 'values' => ['afternoon_control_enabled' => false], 'effective_from' => now()->subDay()]);
    app(PersonnelRules::class)->forget();
    [$day, $box, $session] = c117Early($user);
    $this->travel(3)->minutes();
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    expect(TimeMovement::where('bucket', 'CREDITO')->count())->toBe(0);
    expect(CashBoxScheduleEvent::where('kind', 'WORK')->count())->toBe(0);
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('C117 accepts the same verified early evidence from a regular operation without changing its financial calculation', function () {
    $user = c2Person();
    [$day, $box, $session] = c117Early($user);
    $this->travel(3)->minutes();
    app(RecordCashBoxOperationAction::class)->execute($user, $session->id, ['amount' => '100', 'source' => 'efectivo',
        'destination' => 'bcp', 'fee_type' => 'NINGUNA', 'fee_component' => 'efectivo', 'request_key' => (string) Str::uuid()]);
    expect((int) TimeMovement::where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(3);
    expect($session->fresh()->current_balances['efectivo'])->toBe(190000);
    expect($session->fresh()->current_balances['bcp'])->toBe(1510000);
    expect(array_sum($session->fresh()->current_balances))->toBe(2000000);
});

it('C129 persisted activation controls new box opening and pausing immediately releases the time gate without erasing debt', function () {
    config(['cajas.schedule_controls_enabled' => false]);
    $admin = c2Person('administrador');
    $user = c2Person();
    $box = c2Configured();
    $rules = app(SavePersonnelRulesAction::class);
    $base = ['tolerance_minutes' => 0, 'morning_closing_minutes' => 0, 'automatic_credit' => false,
        'reason' => 'Control activado en prueba aislada', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $rules->execute($admin, [...$base, 'cash_box_controls_enabled' => true]);
    $day = c2Workday($user);
    $day->update(['starts_at' => now('UTC'), 'ends_at' => now('UTC')->addMinutes(2)]);
    $access = c2Access($user, $box);
    $this->travel(1)->minutes();
    $session = c2Open($user, $access);
    $case = TimeIncident::where('source_key', 'opening:'.$day->id)->sole();
    expect($case->pending_minutes)->toBe(2);
    expect(app(CashBoxClosingAccessQuery::class)->execute($user, $session->id)['allowed'])->toBeFalse();
    $rules->execute($admin, [...$base, 'cash_box_controls_enabled' => false, 'request_key' => (string) Str::uuid()]);
    expect(app(CashBoxClosingAccessQuery::class)->execute($user, $session->id)['allowed'])->toBeTrue();
    expect($case->fresh()->pending_minutes)->toBe(2)->and($session->fresh()->status)->toBe('ABIERTA');
    $this->artisan('horario:reintentar-aperturas')->expectsOutput('Controles de Cajas pausados. No se procesaron eventos.')->assertSuccessful();
    $this->assertDatabaseCount('cash_box_early_requests', 0);
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('C133 resolves the recurring shift at verified PIN and keeps one late incident across several boxes', function () {
    config(['cajas.schedule_controls_enabled' => true]);
    $user = c2Person();
    $admin = c2Person('administrador');
    $one = c2Configured();
    $two = c2Configured();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 07:00', 'America/La_Paz'));
    app(SaveRecurringWorkShiftAction::class)->execute($admin, ['user_id' => $user->id, 'period' => 'manana', 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()]);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 08:20', 'America/La_Paz'));
    $access = c2Access($user, $one);
    $day = ScheduledWorkday::where('user_id', $user->id)->sole();
    expect($day->attendance->first_pin_at->equalTo(now()))->toBeTrue();
    $first = c2Open($user, $access);
    c2Open($user, c2Access($user, $two));
    expect(ScheduledWorkday::where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('attendances')->count())->toBe(1)
        ->and(TimeIncident::where('source_key', 'opening:'.$day->id)->sole()->real_minutes)->toBe(5);
    expect($first->fresh()->current_balances)->toBe($first->opening_balances);
});

it('C133 creates the recurring afternoon at continuity without another PIN or false late debt', function () {
    config(['cajas.schedule_controls_enabled' => true]);
    $user = c2Person();
    $admin = c2Person('administrador');
    $box = c2Configured();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 07:00', 'America/La_Paz'));
    foreach (['manana', 'tarde'] as $period) {
        app(SaveRecurringWorkShiftAction::class)->execute($admin, ['user_id' => $user->id, 'period' => $period, 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()]);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00', 'America/La_Paz'));
    $session = c2Open($user, c2Access($user, $box));
    expect(ScheduledWorkday::count())->toBe(1);
    $pins = DB::table('user_audits')->where('action', 'pin_verified')->count();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:20', 'America/La_Paz'));
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    app(ResumeOwnCashBoxAccessAction::class)->execute($user, $box->id);
    $day = ScheduledWorkday::where('user_id', $user->id)->where('period', 'tarde')->sole();
    expect($day->attendance()->exists())->toBeTrue()
        ->and(TimeIncident::where('source_key', 'opening:'.$day->id)->sole()->real_minutes)->toBe(0)
        ->and(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe($pins)
        ->and($session->fresh()->status)->toBe('ABIERTA');
    $this->assertDatabaseCount('attendances', 2);
});

it('C134 highlights opening delay and submits its explanation directly from the owning box', function () {
    config(['cajas.schedule_controls_enabled' => true]);
    $user = c2Person();
    $box = c2Configured();
    c2Workday($user);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($user);
    $page = Livewire::test(CashBoxCatalog::class)->callAction(TestAction::make('ingresar')->table($box), ['pin' => '1234'])
        ->set('openingBalances', ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'])->call('confirmOpening')->assertActionMounted('lateOpeningNotice');
    expect($page->getMountedActionModalHtml())->toContain('pg-delay-minutes', 'Apelar o explicar retraso');
    $page->callAction('explainDelay')->assertActionMounted('explainOpeningDelay')
        ->setActionData(['code' => 'sistema', 'reason' => 'El servidor estuvo fuera de servicio durante 30 minutos'])->callMountedAction()->assertHasNoActionErrors();
    $case = TimeIncident::where('user_id', $user->id)->where('kind', 'RETRASO')->sole();
    expect($case->reason_code)->toBe('sistema')->and($case->status)->toBe('EN_REVISION')->and($case->pending_minutes)->toBeGreaterThan(0);
    $session = CashBoxSession::where('user_id', $user->id)->sole();
    app(ExplainOpeningDelayAction::class)->execute($user, $session->id, 'sistema', 'El servidor estuvo fuera de servicio durante 30 minutos');
    expect(DB::table('time_history_events')->where('action', 'MOTIVO_O_REVISION')->count())->toBe(1)
        ->and($session->fresh()->status)->toBe('ABIERTA');
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('C134 previews early departure separately from the normal window without charging a request', function () {
    config(['cajas.schedule_controls_enabled' => true]);
    $user = c2Person();
    $box = c2Configured();
    c2Workday($user);
    $session = c2Open($user, c2Access($user, $box));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 13:20', 'America/La_Paz'));
    $gate = app(CashBoxClosingAccessQuery::class)->execute($user, $session->id);
    expect($gate['minutes'])->toBe(30)->and($gate['departure_minutes'])->toBe(40);
    app(RequestCashBoxEarlyCloseAction::class)->execute($user, $session->id, ['reason' => 'Consulta de prueba', 'request_key' => (string) Str::uuid()]);
    expect(TimeIncident::where('kind', 'FALTA_PARCIAL')->count())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 13:50', 'America/La_Paz'));
    expect(app(CashBoxClosingAccessQuery::class)->execute($user, $session->id)['departure_minutes'])->toBe(0);
});

function c136Coverage($testCase): array
{
    $admin = c2Person('administrador');
    $a = c2Person();
    $b = c2Person();
    $box = c2Configured();
    $thisClock = CarbonImmutable::parse('2026-10-19 07:00', 'America/La_Paz');
    $testCase->travelTo($thisClock);
    foreach ([[$a, 'manana'], [$b, 'tarde']] as [$user,$period]) {
        app(SaveRecurringWorkShiftAction::class)->execute($admin, ['user_id' => $user->id, 'period' => $period, 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()]);
    }
    app(ConfigureCashBoxAction::class)->execute($admin, $box, c2Settings($box, ['shifts' => ['tarde' => ['liquidates' => false]]]));
    $coverage = app(AuthorizeShiftCoverageAction::class)->execute($admin, ['source_user_id' => $a->id, 'user_id' => $b->id, 'work_date' => '2026-10-19', 'period' => 'manana', 'starts_time' => '11:00', 'reason' => 'Relevo autorizado de prueba', 'request_key' => (string) Str::uuid()]);

    return [$admin, $a, $b, $box->fresh(), $coverage];
}
function c136Close(User $user, CashBoxSession $session, string $period): CashBoxClose
{
    return app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, ['period' => $period, 'balances' => array_map(fn ($c) => Bolivianos::format($c), $session->fresh()->expected_balances),
        'readings' => ['general' => '100', 'fixed' => '50'], 'expected_revision' => $session->fresh()->revision, 'liquidate' => false, 'request_key' => (string) Str::uuid()]);
}
it('C136 requests future leave by date without manually creating a workday or changing habits', function () {
    $admin = c2Person('administrador');
    $a = c2Person();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 07:00', 'America/La_Paz'));
    $habit = app(SaveRecurringWorkShiftAction::class)->execute($admin, ['user_id' => $a->id, 'period' => 'manana', 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()]);
    $data = ['kind' => 'AUSENCIA_FUTURA', 'work_date' => '2026-10-19', 'period' => 'manana', 'reason' => 'Necesito mi día libre', 'request_key' => (string) Str::uuid()];
    $leave = app(RequestLeaveAction::class)->execute($a, $data);
    expect(app(RequestLeaveAction::class)->execute($a, $data)->id)->toBe($leave->id);
    expect($leave->scheduledWorkday->work_date)->toBe('2026-10-19')->and($habit->fresh()->enabled)->toBeTrue();
    expect(ScheduledWorkday::where('user_id', $a->id)->count())->toBe(1);
});
it('C136 closes a morning replacement normally at fourteen keeps credit and excuses optional afternoon', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'America/La_Paz'));
    $session = c2Open($b, c2Access($b, $box));
    expect($session->scheduled_workday_id)->toBe($coverage->workday_id);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:00', 'America/La_Paz'));
    expect(app(CashBoxClosingAccessQuery::class)->execute($b, $session->id, 'manana')['allowed'])->toBeTrue();
    c136Close($b, $session, 'manana');
    expect((int) TimeMovement::where('user_id', $b->id)->where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(180);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 20:01', 'America/La_Paz'));
    $day = ScheduledWorkday::findOrFail($coverage->credit_workday_id);
    $case = app(DetectTimeIncidentAction::class)->absence($day);
    expect($case->status)->toBe('PERMISO_AUTORIZADO')->and($case->pending_minutes)->toBe(0);
    expect(TimeIncident::where('user_id', $b->id)->where('kind', 'FALTA_PARCIAL')->count())->toBe(0);
});
it('C136 continues a replacement until twenty without another PIN or duplicate credit', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'America/La_Paz'));
    $session = c2Open($b, c2Access($b, $box));
    $pins = DB::table('user_audits')->where('action', 'pin_verified')->count();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:05', 'America/La_Paz'));
    app(ResumeOwnCashBoxAccessAction::class)->execute($b, $box->id);
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe($pins);
    expect(fn () => c136Close($b, $session, 'manana'))->toThrow(ValidationException::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 20:00', 'America/La_Paz'));
    c136Close($b, $session, 'tarde');
    expect((int) TimeMovement::where('user_id', $b->id)->where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(180);
    expect(TimeIncident::where('user_id', $b->id)->where('pending_minutes', '>', 0)->count())->toBe(0);
});

it('C136 keeps the outgoing shortage of time separate from the replacements verified credit', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00', 'America/La_Paz'));
    $original = c2Open($a, c2Access($a, $box));
    $this->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'America/La_Paz'));
    expect(fn () => c2Access($b, $box))->toThrow(AuthorizationException::class);
    $request = app(RequestCashBoxEarlyCloseAction::class)->execute($a, $original->id, ['reason' => 'Salida autorizada', 'request_key' => (string) Str::uuid()]);
    app(ReviewCashBoxEarlyCloseAction::class)->execute($admin, $request->id, ['decision' => 'APROBADA', 'reason' => 'Relevo confirmado', 'request_key' => (string) Str::uuid()]);
    c136Close($a, $original, 'manana');
    $debt = TimeIncident::where('user_id', $a->id)->where('kind', 'FALTA_PARCIAL')->sole();
    expect($debt->pending_minutes)->toBe(180);
    $replacement = c2Open($b, c2Access($b, $box));
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:00', 'America/La_Paz'));
    c136Close($b, $replacement, 'manana');
    expect($debt->fresh()->pending_minutes)->toBe(180)
        ->and((int) TimeMovement::where('user_id', $b->id)->where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(180);
});

it('C136 preserves recurring assignments and rejects conflicting or contradictory coverage retries', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    $action = app(AuthorizeShiftCoverageAction::class);
    $data = ['source_user_id' => $a->id, 'user_id' => $b->id, 'work_date' => '2026-10-19', 'period' => 'manana', 'starts_time' => '11:00', 'reason' => 'Relevo autorizado de prueba', 'request_key' => $coverage->request_key];
    expect($action->execute($admin, $data)->id)->toBe($coverage->id);
    expect(fn () => $action->execute($admin, [...$data, 'reason' => 'Otra solicitud']))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($admin, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($b, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect(RecurringWorkShift::where('user_id', $b->id)->count())->toBe(1);
    expect(ShiftCoverage::count())->toBe(1);
});

it('C136 does not excuse an afternoon while another replacement box is still open', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    $other = c2Configured();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'America/La_Paz'));
    $one = c2Open($b, c2Access($b, $box));
    c2Open($b, c2Access($b, $other));
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:00', 'America/La_Paz'));
    c136Close($b, $one, 'manana');
    expect(app(ShiftCoverageContext::class)->optionalCompletion(ScheduledWorkday::findOrFail($coverage->credit_workday_id)))->toBeNull();
    expect((int) TimeMovement::where('user_id', $b->id)->where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(180);
});

it('C136 submits a future leave from the profile by date and protects the coverage administrative page', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($a);
    Livewire::test(OwnLeavesWidget::class)->callTableAction('solicitarPermiso', data: ['kind' => 'AUSENCIA_FUTURA', 'work_date' => '2026-10-20', 'period' => 'manana', 'reason' => 'Día libre solicitado por fecha', 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors();
    $this->get(ShiftCoverages::getUrl())->assertForbidden();
    $this->actingAs($admin);
    Livewire::test(ShiftCoverages::class)->assertCanSeeTableRecords([$coverage])->mountAction('authorizeCoverage')->assertActionMounted('authorizeCoverage')->assertMountedActionModalSee('Cobertura de un solo día');
});

it('C136 uses the coverage start for arrival and never invents an eight o clock late arrival', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 11:20', 'America/La_Paz'));
    c2Open($b, c2Access($b, $box));
    $case = TimeIncident::where('user_id', $b->id)->where('kind', 'RETRASO')->sole();
    expect($case->real_minutes)->toBe(5)->and($case->scheduled_workday_id)->toBe($coverage->workday_id);
    expect(ScheduledWorkday::findOrFail($coverage->workday_id)->starts_at->setTimezone('America/La_Paz')->format('H:i'))->toBe('11:00');
});

it('C136 rolls back all dated records after an interrupted coverage audit and retries once', function () {
    $admin = c2Person('administrador');
    $a = c2Person();
    $b = c2Person();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 07:00', 'America/La_Paz'));
    foreach ([[$a, 'manana'], [$b, 'tarde']] as [$user,$period]) {
        app(SaveRecurringWorkShiftAction::class)->execute($admin, ['user_id' => $user->id, 'period' => $period, 'enabled' => true, 'expected_version_id' => 0, 'request_key' => (string) Str::uuid()]);
    }
    $data = ['source_user_id' => $a->id, 'user_id' => $b->id, 'work_date' => '2026-10-19', 'period' => 'manana', 'starts_time' => '11:00', 'reason' => 'Cobertura de prueba', 'request_key' => (string) Str::uuid()];
    $fail = true;
    DB::listen(function ($query) use (&$fail): void {
        if ($fail && str_starts_with($query->sql, 'insert into "workday_audits"') && in_array('coverage_authorized', $query->bindings, true)) {
            $fail = false;
            throw new RuntimeException('Interrupción de prueba');
        }
    });
    $action = app(AuthorizeShiftCoverageAction::class);
    expect(fn () => $action->execute($admin, $data))->toThrow(RuntimeException::class);
    expect(ScheduledWorkday::count())->toBe(0)->and(ShiftCoverage::count())->toBe(0);
    $coverage = $action->execute($admin, $data);
    $action->execute($admin, $data);
    expect(ShiftCoverage::count())->toBe(1)->and(ScheduledWorkday::count())->toBe(3);
});

it('C136 approved future permission excuses only the requested date and keeps both habits', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    $leave = app(RequestLeaveAction::class)->execute($a, ['kind' => 'AUSENCIA_FUTURA', 'work_date' => '2026-10-20', 'period' => 'manana', 'reason' => 'Permiso para un día concreto', 'request_key' => (string) Str::uuid()]);
    app(ApproveLeaveAction::class)->execute($admin, $leave, true, 'Día libre aprobado');
    app(ApproveLeaveAction::class)->execute($admin, $leave, true, 'Día libre aprobado');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 20:01', 'America/La_Paz'));
    $case = app(DetectTimeIncidentAction::class)->absence($leave->scheduledWorkday);
    expect($case->status)->toBe('PERMISO_AUTORIZADO')->and($case->pending_minutes)->toBe(0)
        ->and($leave->monthlyLeave->fresh()->status)->toBe('USADO')
        ->and(RecurringWorkShift::count())->toBe(2);
});
it('C136 rejects self retroactive overlapping coverage and invalid future leave without partial records', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    $count = ScheduledWorkday::count();
    $data = ['source_user_id' => $a->id, 'user_id' => $b->id, 'work_date' => '2026-10-19', 'period' => 'manana', 'starts_time' => '11:00', 'reason' => 'Otra cobertura', 'request_key' => (string) Str::uuid()];
    $action = app(AuthorizeShiftCoverageAction::class);
    expect(fn () => $action->execute($admin, [...$data, 'user_id' => $a->id]))->toThrow(ValidationException::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'America/La_Paz'));
    expect(fn () => $action->execute($admin, $data))->toThrow(ValidationException::class);
    foreach (['2026-10-18', '2026-10-25'] as $date) {
        expect(fn () => app(RequestLeaveAction::class)->execute($a, ['kind' => 'AUSENCIA_FUTURA', 'work_date' => $date, 'period' => 'manana', 'reason' => 'Fecha no habilitada', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    }
    expect(ScheduledWorkday::count())->toBe($count)->and(ShiftCoverage::count())->toBe(1);
});
it('C136 allows an optional afternoon return after completed morning coverage without fake opening debt', function () {
    [$admin,$a,$b,$box,$coverage] = c136Coverage($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'America/La_Paz'));
    $morning = c2Open($b, c2Access($b, $box));
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:00', 'America/La_Paz'));
    c136Close($b, $morning, 'manana');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:00', 'America/La_Paz'));
    $afternoon = c2Open($b, c2Access($b, $box));
    expect($afternoon->scheduled_workday_id)->toBe($coverage->credit_workday_id);
    $case = TimeIncident::where('scheduled_workday_id', $coverage->credit_workday_id)->where('kind', 'RETRASO')->sole();
    expect($case->pending_minutes)->toBe(0)->and($case->calculation['coverage_completion_close_id'])->not->toBeNull();
    expect((int) TimeMovement::where('user_id', $b->id)->where('bucket', 'CREDITO')->sum('delta_minutes'))->toBe(180);
});

it('C136 returns a committed coverage on a next day retry without creating historical coverage', function () {
    [$admin, $a, $b, $box, $coverage] = c136Coverage($this);
    $data = ['source_user_id' => $a->id, 'user_id' => $b->id, 'work_date' => '2026-10-19', 'period' => 'manana', 'starts_time' => '11:00', 'reason' => $coverage->reason, 'request_key' => $coverage->request_key];
    $this->travelTo(CarbonImmutable::parse('2026-10-20 07:00', 'America/La_Paz'));
    $action = app(AuthorizeShiftCoverageAction::class);
    expect($action->execute($admin, $data)->id)->toBe($coverage->id);
    expect(fn () => $action->execute($admin, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(ShiftCoverage::count())->toBe(1);
});

it('C136 finishes an already selected morning close across fourteen without inventing afternoon attendance', function () {
    [$admin, $a, $b, $box, $coverage] = c136Coverage($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'America/La_Paz'));
    $session = c2Open($b, c2Access($b, $box));
    $this->travelTo(CarbonImmutable::parse('2026-10-19 13:59', 'America/La_Paz'));
    expect(app(PrepareCashBoxClosingAccessAction::class)->execute($b, $session->id)['allowed'])->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:01', 'America/La_Paz'));
    $close = c136Close($b, $session, 'manana');
    expect($close->period)->toBe('manana')->and(CashBoxWorkdayContinuity::where('cash_box_session_id', $session->id)->count())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 20:01', 'America/La_Paz'));
    expect(app(DetectTimeIncidentAction::class)->absence(ScheduledWorkday::findOrFail($coverage->credit_workday_id))->status)->toBe('PERMISO_AUTORIZADO');
});

it('C136 authorizes a dated coverage from the administrative modal without changing habits', function () {
    [$admin, $a, $b, $box, $coverage] = c136Coverage($this);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(ShiftCoverages::class)->callAction('authorizeCoverage', data: [
        'source_user_id' => $a->id, 'user_id' => $b->id, 'work_date' => '2026-10-20', 'period' => 'manana',
        'starts_time' => '11:00', 'reason' => 'Cobertura desde el formulario', 'request_key' => (string) Str::uuid(),
    ])->assertHasNoActionErrors();
    $new = ShiftCoverage::where('work_date', '2026-10-20')->sole();
    expect($new->starts_at->setTimezone('America/La_Paz')->format('H:i'))->toBe('11:00')
        ->and($new->created_by)->toBe($admin->id)
        ->and(RecurringWorkShift::count())->toBe(2);
});
