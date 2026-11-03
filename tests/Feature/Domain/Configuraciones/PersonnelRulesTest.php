<?php

use App\Domain\Configuraciones\Actions\SavePersonnelRulesAction;
use App\Domain\Configuraciones\Models\PersonnelRuleVersion;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Actions\DetectTimeIncidentAction;
use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReviewEconomicIncidentAction;
use App\Domain\Usuarios\Actions\SetUserPinAction;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Models\User;
use Carbon\Carbon;
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
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function personnelRulesPeople(): array
{
    app(UsuariosPermissionsSeeder::class)->run();
    app(ControlHorarioPermissionsSeeder::class)->run();
    app(IncidenciasPermissionsSeeder::class)->run();
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    app(SetUserPinAction::class)->execute($admin, $admin, '1234');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');

    return [$admin, $cashier];
}
function personnelRulesPayload(): array
{
    return ['tolerance_minutes' => 0, 'high_difference_cents' => 100000, 'reason' => 'Parámetros para futuras jornadas y casos', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
}
it('requires an administrative PIN and role for sensitive parameter changes', function () {
    [$admin,$cashier] = personnelRulesPeople();
    $data = personnelRulesPayload();
    $data['pin'] = '0000';
    expect(fn () => app(SavePersonnelRulesAction::class)->execute($admin, $data))->toThrow(ValidationException::class);
    expect(fn () => app(SavePersonnelRulesAction::class)->execute($cashier, personnelRulesPayload()))->toThrow(AuthorizationException::class);
    expect(PersonnelRuleVersion::count())->toBe(0);
});
it('creates an audited version and invalidates the request cache without exposing PIN', function () {
    [$admin] = personnelRulesPeople();
    expect(app(PersonnelRules::class)->current()['tolerance_minutes'])->toBe(15);
    $data = personnelRulesPayload();
    $version = app(SavePersonnelRulesAction::class)->execute($admin, $data);
    app(SavePersonnelRulesAction::class)->execute($admin, $data);
    expect(PersonnelRuleVersion::count())->toBe(1);
    expect(app(PersonnelRules::class)->current()['tolerance_minutes'])->toBe(0);
    $audit = DB::table('user_audits')->where('action', 'personnel_rules_updated')->first();
    expect($audit->changes)->not->toContain('"pin"');
});
it('applies new tolerance only to subsequent workdays and preserves the old case snapshot', function () {
    [$admin,$cashier] = personnelRulesPeople();
    $this->travelTo(Carbon::parse('2026-10-14 14:00', 'UTC'));
    $old = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
    $oldCase = app(DetectTimeIncidentAction::class)->opening($cashier, $old, CarbonImmutable::parse('2026-10-14 12:20', 'UTC'), 'old-opening');
    app(SavePersonnelRulesAction::class)->execute($admin, personnelRulesPayload());
    $next = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-10-15', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
    $this->travelTo(Carbon::parse('2026-10-15 14:00', 'UTC'));
    $newCase = app(DetectTimeIncidentAction::class)->opening($cashier, $next, CarbonImmutable::parse('2026-10-15 12:20', 'UTC'), 'new-opening');
    expect($oldCase->fresh()->nominal_minutes)->toBe(10);
    expect($newCase->nominal_minutes)->toBe(40);
    expect($oldCase->calculation['version_id'])->toBe(0);
    expect($newCase->calculation['version_id'])->toBeGreaterThan(0);
});
it('keeps the high difference threshold applied to the original economic case', function () {
    [$admin,$cashier] = personnelRulesPeople();
    $payload = ['source_module' => 'manual', 'source_reference' => 'old-money', 'component' => 'efectivo', 'amount_cents' => 20000, 'reason' => 'Faltante para revisar', 'occurred_at' => now()->format('Y-m-d H:i:s')];
    $old = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, $payload);
    app(SavePersonnelRulesAction::class)->execute($admin, personnelRulesPayload());
    expect(fn () => app(ReviewEconomicIncidentAction::class)->execute($admin, $old, 'CONFIRMAR', 'Sin segunda comprobación'))->toThrow(ValidationException::class);
    $payload['source_reference'] = 'new-money';
    $new = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, $payload);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $new, 'CONFIRMAR', 'El nuevo umbral es Bs1000');
    expect($new->fresh()->pending_cents)->toBe(20000);
});
it('changes personnel parameters from the administrator profile action', function () {
    [$admin] = personnelRulesPeople();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(EditOwnProfile::class)->callAction('parametrosPersonal', data: ['request_key' => (string) Str::uuid(), 'tolerance_minutes' => 10, 'high_difference' => '150.00', 'reason' => 'Nuevo parámetro operativo', 'pin' => '1234'])->assertHasNoActionErrors();
    expect(app(PersonnelRules::class)->current()['high_difference_cents'])->toBe(15000);
});

it('shows the guided settings section only to an authorized administrator', function () {
    [$admin, $cashier] = personnelRulesPeople();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));

    $this->actingAs($admin);
    Livewire::test(EditOwnProfile::class)
        ->assertSee('Ajustes de asistencia y diferencias')
        ->assertSee('Cómo cambiar estos ajustes')
        ->assertDontSee('Parámetros del historial')
        ->assertActionVisible('parametrosPersonal');

    $this->actingAs($cashier);
    Livewire::test(EditOwnProfile::class)
        ->assertDontSee('Ajustes de asistencia y diferencias')
        ->assertActionHidden('parametrosPersonal');
});

it('configures only the administrators own PIN from the profile', function () {
    [$admin, $cashier] = personnelRulesPeople();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(EditOwnProfile::class)
        ->assertSee('Mi PIN personal')
        ->callAction('pinPersonal', data: ['pin' => '5678', 'pinConfirmation' => '5678', 'user_id' => $cashier->id])
        ->assertHasNoActionErrors();
    expect(Hash::check('5678', $admin->fresh()->pin_hash))->toBeTrue();
    expect($cashier->fresh()->pin_hash)->toBeNull();

    $this->actingAs($cashier);
    Livewire::test(EditOwnProfile::class)->assertActionVisible('pinPersonal')->assertSee('Configurar mi PIN');
});

it('keeps PIN management available without exposing restricted global settings', function () {
    [$admin] = personnelRulesPeople();
    $admin->restrictedPermissions()->attach(Permission::findByName('configuraciones.personal', 'web'));
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(EditOwnProfile::class)
        ->assertSee('Mi PIN personal')
        ->assertDontSee('Ajustes de asistencia y diferencias')
        ->assertActionVisible('pinPersonal')
        ->assertActionHidden('parametrosPersonal')
        ->callAction('pinPersonal', data: ['pin' => '5678', 'pinConfirmation' => '9999'])
        ->assertHasActionErrors(['pin' => 'same']);
    expect(Hash::check('1234', $admin->fresh()->pin_hash))->toBeTrue();
});
