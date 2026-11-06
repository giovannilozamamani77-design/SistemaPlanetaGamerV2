<?php

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Configuraciones\Actions\SavePersonnelRulesAction;
use App\Domain\Configuraciones\Models\PersonnelRuleVersion;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Actions\DetectTimeIncidentAction;
use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Actions\SaveScheduleSectionAction;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Filament\Pages\ControlHorario\ScheduleSettings;
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
function c109People(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    $admin = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    config(['cajas.schedule_controls_enabled' => false]);

    return [$admin, $cashier];
}
it('C109 renders an administrative schedule settings page without creating financial or attendance records', function () {
    [$admin,$cashier] = c109People();
    test()->actingAs($admin);
    test()->get(ScheduleSettings::getUrl())->assertOk()->assertSee('Control de horario')->assertSee('Controles de Cajas pausados');
    expect(PersonnelRuleVersion::count())->toBe(0)->and(DB::table('attendances')->count())->toBe(0)->and(DB::table('cash_box_sessions')->count())->toBe(0);
    test()->actingAs($cashier)->get(ScheduleSettings::getUrl())->assertForbidden();
});
it('C109 previews live without saving and uses the existing delay formula with no extra grace', function () {
    [$admin] = c109People();
    test()->actingAs($admin);
    $page = Livewire::test(ScheduleSettings::class)->set('data.example_opening', '08:20');
    expect($page->instance()->preview['weighted'])->toBe(10)->and($page->instance()->preview['real'])->toBe(5);
    $page->set('data.tolerance_minutes', 10);
    expect($page->instance()->preview['weighted'])->toBe(20)->and(PersonnelRuleVersion::count())->toBe(0);
});
it('C109 saves only time rules with PIN and preserves monetary settings while staying paused', function () {
    [$admin] = c109People();
    test()->actingAs($admin);
    PersonnelRuleVersion::create(['created_by' => $admin->id, 'request_key' => (string) Str::uuid(), 'reason' => 'Base ficticia', 'effective_from' => now()->subMinute(), 'values' => ['high_difference_cents' => 32100, 'repayment_destination_key' => 'preserve-me', 'tolerance_minutes' => 15]]);
    $page = Livewire::test(ScheduleSettings::class)->set('data.tolerance_minutes', 12)->set('data.delay_multiplier', 3)->set('data.automatic_credit', false)
        ->set('data.schedule_controls_enabled', true)->set('data.high_difference_cents', 1)
        ->callAction('saveSettings', data: ['reason' => 'Ajustes futuros de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoErrors();
    $rules = app(PersonnelRules::class)->current();
    expect($rules['tolerance_minutes'])->toBe(12)->and($rules['delay_multiplier'])->toBe(3)->and($rules['automatic_credit'])->toBeFalse()
        ->and($rules['high_difference_cents'])->toBe(32100)->and($rules['repayment_destination_key'])->toBe('preserve-me')->and(config('cajas.schedule_controls_enabled'))->toBeFalse();
    expect(DB::table('user_audits')->where('action', 'personnel_rules_updated')->value('changes'))->not->toContain('"pin"');
});
it('C109 rejects changed retries and stale versions instead of overwriting settings', function () {
    [$admin] = c109People();
    $data = ['tolerance_minutes' => 15, 'high_difference_cents' => 10000, 'reason' => 'Versión original', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $action = app(SavePersonnelRulesAction::class);
    $action->execute($admin, $data);
    $action->execute($admin, $data);
    expect(PersonnelRuleVersion::count())->toBe(1);
    expect(fn () => $action->execute($admin, [...$data, 'tolerance_minutes' => 30]))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($admin, [...$data, 'request_key' => (string) Str::uuid(), 'expected_version_id' => 0]))->toThrow(ValidationException::class);
});
it('C109 keeps settings unchanged on a bad PIN or cancelling confirmation', function () {
    [$admin] = c109People();
    test()->actingAs($admin);
    Livewire::test(ScheduleSettings::class)->set('data.tolerance_minutes', 20)
        ->callAction('saveSettings', data: ['reason' => 'Intento de prueba', 'pin' => '0000', 'request_key' => (string) Str::uuid()])->assertHasActionErrors(['pin']);
    expect(PersonnelRuleVersion::count())->toBe(0);
    $screen = Livewire::test(ScheduleSettings::class)->set('data.tolerance_minutes', 20)->mountAction('saveSettings')->assertActionMounted('saveSettings');
    $screen->call('unmountAction');
    expect(PersonnelRuleVersion::count())->toBe(0)->and(config('cajas.schedule_controls_enabled'))->toBeFalse();
});
it('C109 shows early departure minutes separately and accepts a factor for the whole delay', function () {
    [$admin] = c109People();
    test()->actingAs($admin);
    $page = Livewire::test(ScheduleSettings::class)->set('data.example_closing', '12:30')->set('data.multiplier_minutes', null)->set('data.example_opening', '09:00');
    expect($page->instance()->preview['early'])->toBeTrue()->and($page->instance()->preview['missing_minutes'])->toBe(90)
        ->and($page->instance()->preview['real'])->toBe(45)->and($page->instance()->preview['weighted'])->toBe(90);
    $page->set('data.example_opening', '');
    expect($page->instance()->preview['valid'])->toBeFalse()->and(PersonnelRuleVersion::count())->toBe(0);
});

it('C112 stores both shifts and box scope without lifting the server pause', function () {
    [$admin] = c109People();
    $box = CashBox::factory()->create();
    $data = ['tolerance_minutes' => 15, 'morning_start' => '08:00', 'morning_end' => '14:00', 'morning_closing_minutes' => 10,
        'afternoon_start' => '14:00', 'afternoon_end' => '20:00', 'afternoon_tolerance_minutes' => 20, 'afternoon_closing_minutes' => 15,
        'schedule_enabled' => true, 'schedule_cash_box_ids' => [$box->id], 'reason' => 'Turnos y cajas de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $version = app(SavePersonnelRulesAction::class)->execute($admin, $data);
    expect($version->values['afternoon_tolerance_minutes'] ?? null)->toBe(20)
        ->and($version->values['schedule_cash_box_ids'] ?? null)->toBe([$box->id])
        ->and(config('cajas.schedule_controls_enabled'))->toBeFalse();
});

it('C112 rejects overlapping shifts rather than saving a confusing schedule', function () {
    [$admin] = c109People();
    expect(fn () => app(SavePersonnelRulesAction::class)->execute($admin, ['tolerance_minutes' => 15, 'morning_start' => '08:00', 'morning_end' => '15:00',
        'afternoon_start' => '14:00', 'afternoon_end' => '20:00', 'reason' => 'Solapamiento de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
});

it('C112 shows clear turn summaries and saves box selection through the screen', function () {
    [$admin] = c109People();
    $one = CashBox::factory()->create();
    test()->actingAs($admin);
    $screen = Livewire::test(ScheduleSettings::class)->assertSee('Mañana')->assertSee('Retrasos y créditos')->assertSee('Cajas incluidas')
        ->assertSee('08:15')->assertSee('13:50')->assertSee('14:15')->assertSee('19:50');
    $screen->set('data.all_boxes', false)->set('data.schedule_cash_box_ids', [$one->id])->set('data.afternoon_tolerance_minutes', 10)
        ->set('data.afternoon_closing_minutes', 20)->callAction('saveSettings', data: ['reason' => 'Configuración estructurada', 'pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoErrors();
    $values = app(PersonnelRules::class)->current();
    expect($values['schedule_cash_box_ids'])->toBe([$one->id])->and($values['afternoon_closing_minutes'])->toBe(20);
    $this->assertDatabaseCount('attendances', 0);
});

it('C112 box scope honors per box selection and the global pause always wins', function () {
    [$admin] = c109People();
    $one = CashBox::factory()->create();
    $two = CashBox::factory()->create();
    app(SavePersonnelRulesAction::class)->execute($admin, ['tolerance_minutes' => 15, 'schedule_enabled' => true, 'schedule_cash_box_ids' => [$one->id],
        'reason' => 'Selección de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $scope = app(ScheduleConfiguration::class);
    expect($scope->allows($one->id))->toBeFalse();
    config(['cajas.schedule_controls_enabled' => true]);
    expect($scope->allows($one->id))->toBeTrue()->and($scope->allows($two->id))->toBeFalse();
    app(SavePersonnelRulesAction::class)->execute($admin, ['tolerance_minutes' => 15, 'schedule_enabled' => false,
        'reason' => 'Desactivar en prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    expect($scope->allows($one->id))->toBeFalse()->and($scope->enabled())->toBeFalse();
});

it('C112 rejects unknown boxes and altered retries including new shift values', function () {
    [$admin] = c109People();
    $data = ['tolerance_minutes' => 15, 'morning_start' => '08:00', 'morning_end' => '14:00', 'reason' => 'Version de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    app(SavePersonnelRulesAction::class)->execute($admin, $data);
    expect(fn () => app(SavePersonnelRulesAction::class)->execute($admin, [...$data, 'morning_start' => '09:00']))->toThrow(ValidationException::class);
    expect(fn () => app(SavePersonnelRulesAction::class)->execute($admin, [...$data, 'request_key' => (string) Str::uuid(), 'schedule_cash_box_ids' => [999999]]))->toThrow(ValidationException::class);
    expect(fn () => app(SavePersonnelRulesAction::class)->execute($admin, [...$data, 'schedule_cash_box_ids' => []]))->toThrow(ValidationException::class);
    expect(PersonnelRuleVersion::count())->toBe(1);
});

it('C112 templates fill only new schedules and afternoon has its own tolerance', function () {
    [$admin, $cashier] = c109People();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'America/La_Paz'));
    app(SavePersonnelRulesAction::class)->execute($admin, ['tolerance_minutes' => 15, 'morning_start' => '09:00', 'morning_end' => '14:30',
        'afternoon_start' => '14:30', 'afternoon_end' => '21:00', 'afternoon_tolerance_minutes' => 5,
        'reason' => 'Plantillas de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin,
        ['user_id' => $cashier->id, 'work_date' => '2026-10-15', 'period' => 'tarde']);
    expect($day->starts_at->setTimezone('America/La_Paz')->format('H:i'))->toBe('14:30');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:40', 'America/La_Paz'));
    $incident = app(DetectTimeIncidentAction::class)->opening($cashier, $day, now('UTC')->toImmutable(), 'fictitious-c112');
    expect($incident->real_minutes)->toBe(5);
});

it('C112 shows overlapping schedule errors beside the main form before asking for PIN', function () {
    [$admin] = c109People();
    test()->actingAs($admin);
    Livewire::test(ScheduleSettings::class)->set('data.morning_end', '15:00')->mountAction('saveSettings')->assertHasErrors(['data.afternoon_start']);
    expect(PersonnelRuleVersion::count())->toBe(0);
});

it('C113 stores activation separately for morning and afternoon without registering a workday', function () {
    [$admin] = c109People();
    test()->actingAs($admin);
    Livewire::test(ScheduleSettings::class)->set('data.morning_control_enabled', false)->set('data.afternoon_control_enabled', true)
        ->callAction('saveSettings', data: ['reason' => 'Solo controlar tarde', 'pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoErrors();
    $values = app(PersonnelRules::class)->current();
    expect($values['morning_control_enabled'] ?? null)->toBeFalse()->and($values['afternoon_control_enabled'] ?? null)->toBeTrue();
    $this->assertDatabaseCount('scheduled_workdays', 0);
    expect(config('cajas.schedule_controls_enabled'))->toBeFalse();
});

it('C113 separates general configuration from recording workdays in the screen', function () {
    [$admin] = c109People();
    test()->actingAs($admin);
    Livewire::test(ScheduleSettings::class)->assertSee('Configurar no asigna personal')->assertSee('Mañana')->assertSee('Tarde')
        ->assertActionDoesNotExist('jornadas')->mountAction('configureMorning')->assertMountedActionModalSee('Aplicar control en mañana');
});

it('C129 lets Administration activate and pause box controls from settings without editing server flags', function () {
    [$admin] = c109People();
    $box = CashBox::factory()->create();
    $other = CashBox::factory()->create();
    $this->actingAs($admin);
    $page = Livewire::test(ScheduleSettings::class)->assertSee('Controles de Cajas pausados');
    $page->set('data.cash_box_controls_enabled', true)->set('data.all_boxes', false)->set('data.schedule_cash_box_ids', [$box->id])
        ->callAction('saveSettings', data: ['reason' => 'Activación limitada de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    expect(app(ScheduleConfiguration::class)->enabled())->toBeTrue();
    expect(app(ScheduleConfiguration::class)->allows($box->id))->toBeTrue();
    expect(app(ScheduleConfiguration::class)->allows($other->id))->toBeFalse();
    $page->assertSee('Controles de Cajas activos')->set('data.cash_box_controls_enabled', false)
        ->callAction('saveSettings', data: ['reason' => 'Pausa desde configuración', 'pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors()
        ->assertSee('Controles de Cajas pausados');
    expect(app(ScheduleConfiguration::class)->enabled())->toBeFalse()->and(config('cajas.schedule_controls_enabled'))->toBeFalse();
    expect(PersonnelRuleVersion::count())->toBe(2)->and(DB::table('attendances')->count())->toBe(0)->and(DB::table('cash_box_sessions')->count())->toBe(0);
});

it('C129 activation requires Administrative permission and PIN and rejects contradictory retries', function () {
    [$admin, $cashier] = c109People();
    $data = ['tolerance_minutes' => 15, 'cash_box_controls_enabled' => true, 'reason' => 'Activación QA por prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $action = app(SavePersonnelRulesAction::class);
    expect(fn () => $action->execute($cashier, $data))->toThrow(AuthorizationException::class);
    expect(fn () => $action->execute($admin, [...$data, 'pin' => '0000']))->toThrow(ValidationException::class);
    expect(app(ScheduleConfiguration::class)->enabled())->toBeFalse();
    $first = $action->execute($admin, $data);
    expect($action->execute($admin, $data)->id)->toBe($first->id);
    expect(fn () => $action->execute($admin, [...$data, 'cash_box_controls_enabled' => false]))->toThrow(ValidationException::class);
    expect(PersonnelRuleVersion::count())->toBe(1)->and(app(ScheduleConfiguration::class)->enabled())->toBeTrue();
    $action->execute($admin, [...$data, 'cash_box_controls_enabled' => false, 'request_key' => (string) Str::uuid()]);
    expect(app(ScheduleConfiguration::class)->enabled())->toBeFalse();
    $action->execute($admin, [...$data, 'request_key' => (string) Str::uuid()]);
    expect(app(ScheduleConfiguration::class)->enabled())->toBeTrue();
});

it('C130 edits one schedule section in a modal without overwriting other rules and cancels without saving', function () {
    [$admin] = c109People();
    $this->actingAs($admin);
    $page = Livewire::test(ScheduleSettings::class)->assertSee('Cómo funciona')->assertSee('Control general');
    $page->mountAction('configureMorning')->assertActionMounted('configureMorning')->assertMountedActionModalSee('Qué cambia al guardar');
    $page->call('unmountAction');
    expect(PersonnelRuleVersion::count())->toBe(0);
    $page->callAction('configureMorning', data: ['morning_start' => '09:00', 'morning_end' => '14:00', 'tolerance_minutes' => 5,
        'morning_closing_minutes' => 0, 'morning_control_enabled' => true, 'high_difference_cents' => 1,
        'reason' => 'Solo mañana desde ventana', 'pin' => '1234'])->assertHasNoActionErrors();
    $rules = app(PersonnelRules::class)->current();
    expect($rules['morning_start'])->toBe('09:00')->and($rules['tolerance_minutes'])->toBe(5)
        ->and($rules['high_difference_cents'])->toBe(10000)->and(app(ScheduleConfiguration::class)->enabled())->toBeFalse();
    $page->callAction('configureDelay', data: ['delay_multiplier' => 3, 'multiplier_minutes' => 10, 'automatic_credit' => false,
        'reason' => 'Solo factor desde ventana', 'pin' => '1234'])->assertHasNoActionErrors();
    $rules = app(PersonnelRules::class)->current();
    expect($rules['morning_start'])->toBe('09:00')->and($rules['tolerance_minutes'])->toBe(5)->and($rules['delay_multiplier'])->toBe(3);
    $this->assertDatabaseCount('scheduled_workdays', 0);
});

it('C130 simulates inside its modal without persisting rules or requesting a PIN', function () {
    [$admin] = c109People();
    $this->actingAs($admin);
    $page = Livewire::test(ScheduleSettings::class)->mountAction('simulateSchedule')->assertActionMounted('simulateSchedule');
    $page->setActionData(['example_start' => '08:00', 'example_opening' => '08:20', 'example_end' => '14:00', 'example_closing' => '13:50'])
        ->assertMountedActionModalSee('Solo simulación')->assertMountedActionModalDontSee('Tu PIN');
    expect(PersonnelRuleVersion::count())->toBe(0)->and(DB::table('attendances')->count())->toBe(0);
});

it('C130 retries a section after another section changed without overwriting it and rejects stale new submissions', function () {
    [$admin, $cashier] = c109People();
    $action = app(SaveScheduleSectionAction::class);
    $boxes = ['all_boxes' => false, 'schedule_cash_box_ids' => [], 'expected_version_id' => 0,
        'reason' => 'Alcance vacío de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    expect(fn () => $action->execute($cashier, 'boxes', $boxes))->toThrow(AuthorizationException::class);
    $first = $action->execute($admin, 'boxes', $boxes);
    $morning = ['morning_start' => '08:00', 'morning_end' => '14:00', 'tolerance_minutes' => 5, 'morning_closing_minutes' => 0,
        'morning_control_enabled' => true, 'expected_version_id' => $first->id,
        'reason' => 'Otra sección después', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $action->execute($admin, 'morning', $morning);
    expect($action->execute($admin, 'boxes', $boxes)->id)->toBe($first->id);
    expect(app(PersonnelRules::class)->current()['tolerance_minutes'])->toBe(5);
    expect(fn () => $action->execute($admin, 'boxes', [...$boxes, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(PersonnelRuleVersion::count())->toBe(2);
});

it('C392 shows each existing box and applies control only to explicit selections', function () {
    [$admin] = c109People();
    $one = CashBox::factory()->create(['name' => 'Caja Blanca']);
    $two = CashBox::factory()->create(['name' => 'Caja Negra']);
    $this->actingAs($admin);
    $page = Livewire::test(ScheduleSettings::class)->assertSee('Caja Blanca')->assertSee('Caja Negra')->assertSee('Sin control');
    $page->mountAction('configureBoxes')->assertMountedActionModalDontSee('Aplicar a todas las cajas')->call('unmountAction');
    $page->callAction('configureBoxes', data: ['schedule_cash_box_ids' => [$one->id], 'reason' => 'Solo caja blanca', 'pin' => '1234'])->assertHasNoActionErrors();
    $page->callAction('configureControl', data: ['cash_box_controls_enabled' => true, 'reason' => 'Activar selección explícita', 'pin' => '1234'])->assertHasNoActionErrors();
    expect(app(ScheduleConfiguration::class)->allows($one->id))->toBeTrue()->and(app(ScheduleConfiguration::class)->allows($two->id))->toBeFalse();
    $new = CashBox::factory()->create(['name' => 'Caja Nueva']);
    expect(app(ScheduleConfiguration::class)->allows($new->id))->toBeFalse();
    expect(app(PersonnelRules::class)->current()['schedule_cash_box_ids'])->toBe([$one->id]);
    $this->assertDatabaseCount('attendances', 0);
    $this->assertDatabaseCount('cash_box_sessions', 0);
});

it('C392 activating control without selecting boxes does not silently include every box', function () {
    [$admin] = c109People();
    $box = CashBox::factory()->create();
    $this->actingAs($admin);
    Livewire::test(ScheduleSettings::class)->callAction('configureControl', data: ['cash_box_controls_enabled' => true, 'reason' => 'Activación sin selección', 'pin' => '1234'])->assertHasNoActionErrors();
    expect(app(ScheduleConfiguration::class)->allows($box->id))->toBeFalse();
    expect(app(PersonnelRules::class)->current()['schedule_cash_box_ids'])->toBe([]);
});

it('C392 keeps box selection intact when retrying a control change after another selection', function () {
    [$admin] = c109People();
    $one = CashBox::factory()->create();
    $two = CashBox::factory()->create();
    $action = app(SaveScheduleSectionAction::class);
    $action->execute($admin, 'boxes', ['schedule_cash_box_ids' => [$one->id], 'expected_version_id' => 0, 'reason' => 'Primera selección', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $data = ['cash_box_controls_enabled' => true, 'expected_version_id' => app(PersonnelRules::class)->current()['version_id'], 'reason' => 'Activar control seleccionado', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $first = $action->execute($admin, 'control', $data);
    $action->execute($admin, 'boxes', ['schedule_cash_box_ids' => [$two->id], 'expected_version_id' => $first->id, 'reason' => 'Cambiar selección', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    expect($action->execute($admin, 'control', $data)->id)->toBe($first->id);
    expect(app(PersonnelRules::class)->current()['schedule_cash_box_ids'])->toBe([$two->id]);
});

it('C392 rejects implicit all-box activation and an unknown selection without saving', function () {
    [$admin] = c109People();
    $data = ['expected_version_id' => 0, 'reason' => 'Selección inválida', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $action = app(SaveScheduleSectionAction::class);
    expect(fn () => $action->execute($admin, 'boxes', $data + ['all_boxes' => true]))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($admin, 'boxes', $data + ['schedule_cash_box_ids' => [999999]]))->toThrow(ValidationException::class);
    expect(PersonnelRuleVersion::count())->toBe(0);
});
