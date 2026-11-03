<?php

use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\MonthlyLeave;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReviewEconomicIncidentAction;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Filament\Resources\ControlHorario\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\Pages\ListScheduledWorkdays;
use App\Filament\Resources\ControlHorario\TimeIncidents\Pages\ListTimeIncidents;
use App\Filament\Resources\Incidencias\EconomicIncidents\Pages\ListEconomicIncidents;
use App\Filament\Resources\Incidencias\GlobalRecoveries\Pages\ManageGlobalRecoveries;
use App\Filament\Widgets\Personal\AdministrativePersonnelOverview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/La_Paz'));
    $this->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
});

function personnelPanelPeople(): array
{
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Ana Personal']);
    $cashier->assignRole('cajera');
    $other = User::factory()->create(['name' => 'Beatriz Personal']);
    $other->assignRole('cajera');

    return [$admin, $cashier, $other];
}

function personnelPanelRecords(string $section, User $admin, User $cashier, User $other): Collection
{
    return collect(range(0, 6))->map(function (int $index) use ($section, $admin, $cashier, $other) {
        $owner = $index % 2 === 0 ? $cashier : $other;
        if (in_array($section, ['jornadas', 'retrasos', 'permisos'], true)) {
            $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $owner->id, 'work_date' => '2026-10-'.(16 + $index), 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
            if ($section === 'jornadas') {
                return $day;
            }
            if ($section === 'retrasos') {
                return TimeIncident::create(['user_id' => $owner->id, 'scheduled_workday_id' => $day->id, 'kind' => 'RETRASO', 'status' => 'PENDIENTE_MOTIVO', 'source_key' => (string) Str::uuid(), 'real_minutes' => 5, 'nominal_minutes' => 10, 'pending_minutes' => 0, 'observed_at' => $day->starts_at->addMinutes(20), 'calculation' => []]);
            }
            $leave = MonthlyLeave::firstOrCreate(['user_id' => $owner->id, 'month' => '2026-10']);

            return LeaveRequest::create(['user_id' => $owner->id, 'monthly_leave_id' => $leave->id, 'scheduled_workday_id' => $day->id, 'kind' => 'AUSENCIA_FUTURA', 'status' => 'PENDIENTE', 'reason' => 'Solicitud de permiso del personal', 'request_key' => (string) Str::uuid()]);
        }
        $case = app(ReportEconomicIncidentAction::class)->execute($admin, $owner, ['source_module' => 'manual', 'source_reference' => (string) Str::uuid(), 'component' => 'efectivo', 'amount_cents' => 1000, 'reason' => 'Conteo pendiente de revisión', 'box_label' => 'Caja BCP 1', 'occurred_at' => now()]);
        if ($section === 'faltantes') {
            return $case;
        }
        app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Confirmado para verificar recepción');

        // Existing historical pending reports remain readable; C105 prevents creating overlapping new reports.
        return GlobalRecovery::create(['user_id' => $owner->id, 'declared_by' => $owner->id, 'request_key' => (string) Str::uuid(), 'method' => 'EFECTIVO', 'amount_cents' => 100, 'note' => 'Entrega global pendiente de verificación']);
    });
}

dataset('personnel panels', [
    ['jornadas', ListScheduledWorkdays::class],
    ['retrasos', ListTimeIncidents::class],
    ['permisos', ListLeaveRequests::class],
    ['faltantes', ListEconomicIncidents::class],
    ['reposiciones', ManageGlobalRecoveries::class],
]);

it('shows the five most recent records without hiding older pages or offering deletion', function (string $section, string $page) {
    [$admin, $cashier, $other] = personnelPanelPeople();
    $records = personnelPanelRecords($section, $admin, $cashier, $other);
    $this->actingAs($admin);
    $screen = Livewire::test($page)->assertSuccessful();
    if ($section === 'reposiciones') {
        $screen->call('selectPanelSection', 'global');
    }
    $screen->assertCanSeeTableRecords($records->slice(2))
        ->assertCanNotSeeTableRecords($records->take(2))
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
    expect($screen->instance()->getTableRecords()->count())->toBe(5);
    $screen->call('gotoPage', 2)->assertCanSeeTableRecords($records->take(2));
})->with('personnel panels');

it('filters every administrative list by cashier', function (string $section, string $page) {
    [$admin, $cashier, $other] = personnelPanelPeople();
    $records = personnelPanelRecords($section, $admin, $cashier, $other);
    $this->actingAs($admin);
    $screen = Livewire::test($page);
    if ($section === 'reposiciones') {
        $screen->call('selectPanelSection', 'global')->callAction('filtrarReposiciones', data: ['user_id' => $cashier->id]);
    } else {
        $screen->filterTable('user_id', $cashier->id);
    }
    $screen->assertCanSeeTableRecords($records->where('user_id', $cashier->id))
        ->assertCanNotSeeTableRecords($records->where('user_id', $other->id));
})->with('personnel panels');

it('opens record details in a read only modal on every panel', function (string $section, string $page) {
    [$admin, $cashier, $other] = personnelPanelPeople();
    $record = personnelPanelRecords($section, $admin, $cashier, $other)->last();
    $this->actingAs($admin);
    $screen = Livewire::test($page)->mountTableAction('detalle', $record)->assertActionMounted(TestAction::make('detalle')->table($record));
    expect($screen->instance()->getTable()->getRecordAction($record))->toBe('detalle');
    expect($screen->instance()->getMountedAction()->getModalHeading())->toBe('Información del registro');
    expect($screen->instance()->getSchema($screen->instance()->getMountedActionSchemaName())->toHtml())->toContain('Datos del registro', 'Cómo resolverlo');
    expect($screen->instance()->getMountedAction()->getModalSubmitAction())->toBeNull();
})->with('personnel panels');

it('creates and edits a future workday through actual floating forms using the domain action', function () {
    [$admin, $cashier] = personnelPanelPeople();
    $this->actingAs($admin);
    $data = ['user_id' => $cashier->id, 'work_date' => '2026-10-20', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00'];
    Livewire::test(ListScheduledWorkdays::class)->callAction('create', data: $data)->assertHasNoActionErrors();
    $day = ScheduledWorkday::firstOrFail();
    $data['starts_time'] = '09:00';
    Livewire::test(ListScheduledWorkdays::class)->callTableAction('edit', $day, data: $data)->assertHasNoTableActionErrors();
    expect($day->fresh()->starts_at->setTimezone('America/La_Paz')->format('H:i'))->toBe('09:00');
    expect(DB::table('workday_audits')->count())->toBe(2);
});

it('filters record dates without excluding the last day of a Bolivia date range', function () {
    [$admin,$cashier] = personnelPanelPeople();
    $payload = ['source_module' => 'manual', 'component' => 'efectivo', 'amount_cents' => 1000, 'reason' => 'Diferencia para revisión'];
    $previous = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, $payload + ['source_reference' => 'yesterday', 'occurred_at' => CarbonImmutable::parse('2026-10-15 03:59', 'UTC')]);
    $today = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, $payload + ['source_reference' => 'today', 'occurred_at' => CarbonImmutable::parse('2026-10-16 03:59', 'UTC')]);
    $tomorrow = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, $payload + ['source_reference' => 'tomorrow', 'occurred_at' => CarbonImmutable::parse('2026-10-16 04:00', 'UTC')]);
    $this->actingAs($admin);
    Livewire::test(ListEconomicIncidents::class)->filterTable('fechas', ['desde' => '2026-10-15', 'hasta' => '2026-10-15'])
        ->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$previous, $tomorrow]);
});
it('filters permission states and keeps a rejected request as historical data', function () {
    [$admin,$cashier,$other] = personnelPanelPeople();
    $records = personnelPanelRecords('permisos', $admin, $cashier, $other);
    $this->actingAs($admin);
    $screen = Livewire::test(ListLeaveRequests::class);
    $screen->callTableAction('decidir', $records->last(), data: ['decision' => 'rechazar', 'reason' => 'No corresponde el permiso solicitado', 'exception' => false])->assertHasNoTableActionErrors();
    expect($records->last()->fresh()->status)->toBe('RECHAZADA');
    $screen->filterTable('status', 'RECHAZADA')->assertCanSeeTableRecords([$records->last()])->assertCanNotSeeTableRecords($records->take(6));
    expect(LeaveRequest::count())->toBe(7);
});
it('keeps workdays that have begun read only instead of exposing deletion or a generic state editor', function () {
    [$admin,$cashier] = personnelPanelPeople();
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
    $this->actingAs($admin);
    Livewire::test(ListScheduledWorkdays::class)->assertTableActionHidden('edit', $day)->assertTableActionDoesNotExist('delete')->mountTableAction('detalle', $day)->assertActionMounted(TestAction::make('detalle')->table($day));
    expect($day->fresh()->starts_at)->toEqual($day->starts_at);
});
it('protects the administrative overview and renders its totals without exposing it to a cashier', function (string $section, string $page) {
    [$admin,$cashier,$other] = personnelPanelPeople();
    personnelPanelRecords($section, $admin, $cashier, $other);
    $this->actingAs($admin);
    Livewire::test(AdministrativePersonnelOverview::class, ['section' => $section])->assertSuccessful()->assertSee('historial completo');
    $this->actingAs($cashier);
    Livewire::test(AdministrativePersonnelOverview::class, ['section' => $section])->assertForbidden();
})->with('personnel panels');

it('keeps absence records visible under the scheduled date filter even without an opening timestamp', function () {
    [$admin,$cashier,$other] = personnelPanelPeople();
    $records = personnelPanelRecords('retrasos', $admin, $cashier, $other);
    $absence = $records->last();
    $absence->update(['kind' => 'FALTA', 'status' => 'FALTA_PENDIENTE', 'observed_at' => null]);
    $this->actingAs($admin);
    Livewire::test(ListTimeIncidents::class)->filterTable('fechas', ['desde' => '2026-10-22', 'hasta' => '2026-10-22'])
        ->assertCanSeeTableRecords([$absence])->assertCanNotSeeTableRecords($records->take(6));
});
it('rejects an invalid workday in the floating form and leaves no audit or partial record', function () {
    [$admin,$cashier] = personnelPanelPeople();
    $this->actingAs($admin);
    Livewire::test(ListScheduledWorkdays::class)
        ->callAction('create', data: ['user_id' => $cashier->id, 'work_date' => '2026-10-20', 'period' => 'manana', 'starts_time' => '14:00', 'ends_time' => '08:00'])
        ->assertHasActionErrors(['ends_time']);
    expect(ScheduledWorkday::count())->toBe(0);
    expect(DB::table('workday_audits')->count())->toBe(0);
});
it('escapes user supplied explanations inside the read only record detail', function () {
    [$admin,$cashier,$other] = personnelPanelPeople();
    $case = personnelPanelRecords('faltantes', $admin, $cashier, $other)->last();
    $case->update(['reason' => '<script>alert("x")</script>']);
    $this->actingAs($admin);
    $screen = Livewire::test(ListEconomicIncidents::class)->mountTableAction('detalle', $case);
    $html = $screen->instance()->getSchema($screen->instance()->getMountedActionSchemaName())->toHtml();
    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>alert');
});

it('verifies a global receipt through its actual resolution modal and refreshes the indicators', function () {
    [$admin,$cashier,$other] = personnelPanelPeople();
    $batch = personnelPanelRecords('reposiciones', $admin, $cashier, $other)->last();
    $this->actingAs($admin);
    $before = (int) EconomicIncident::where('user_id', $batch->user_id)->sum('pending_cents');
    Livewire::test(ManageGlobalRecoveries::class)
        ->callTableAction('recibir', $batch, data: ['received' => true, 'receipt' => 'Entrega real recibida y contada', 'exception' => false])
        ->assertHasNoTableActionErrors()->assertDispatched('personnel-record-updated');
    expect($batch->fresh()->confirmed_at)->not->toBeNull();
    expect((int) EconomicIncident::where('user_id', $batch->user_id)->sum('pending_cents'))->toBe($before - 100);
});

it('uses the full content width and gives the date range a separate responsive row', function (string $section, string $page) {
    [$admin] = personnelPanelPeople();
    $this->actingAs($admin);
    $screen = Livewire::test($page)->assertSuccessful();
    expect($screen->instance()->getMaxContentWidth())->toBe(Width::Full);
    if ($section === 'reposiciones') {
        $screen->assertActionExists('filtrarReposiciones');
        expect($screen->instance()->getTable()->getFilters())->toBe([]);

        return;
    }
    $dates = $screen->instance()->getTable()->getFilter('fechas');
    expect($dates->getColumnSpan())->toBe(['default' => 'full']);
    expect($dates->getColumns())->toMatchArray(['default' => 1, 'sm' => 2]);
})->with('personnel panels');
