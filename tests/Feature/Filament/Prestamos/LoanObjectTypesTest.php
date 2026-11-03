<?php

use App\Domain\Prestamos\Models\LoanCommand;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Filament\Pages\Prestamos\LoanList;
use App\Filament\Pages\Prestamos\LoanObjectTypes;
use App\Filament\Pages\Prestamos\LoanSettings;
use App\Models\User;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function objectTypeAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $user = User::factory()->create();
    $user->assignRole('administrador');
    test()->actingAs($user);

    return $user;
}
function objectTypeData(string $name = 'Garrafa'): array
{
    return ['name' => $name, 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()];
}
it('C212 creates an object type without initializing capital and edits it from its own screen', function () {
    $admin = objectTypeAdmin();
    $data = objectTypeData();
    Livewire::test(LoanObjectTypes::class)->assertSee('Tipos de objeto')->callAction('createType', data: $data)->assertHasNoActionErrors();
    $type = LoanObjectType::sole();
    expect($type->rate_bps)->toBe(1500)->and($type->revision)->toBe(1)->and(LoanFund::sole()->initialized_at)->toBeNull()->and(LoanMovement::count())->toBe(0);
    Livewire::test(LoanObjectTypes::class)->callAction('createType', data: $data)->assertHasNoActionErrors();
    expect(LoanObjectType::count())->toBe(1);
    Livewire::test(LoanObjectTypes::class)->callTableAction('editType', $type, data: ['name' => 'Garrafa doméstica', 'rate' => '12.5', 'is_active' => false, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors();
    expect($type->fresh()->rate_bps)->toBe(1250)->and($type->fresh()->is_active)->toBeFalse();
});
it('C212 refuses duplicate names invalid rates and stale edits without altering the catalogue', function () {
    objectTypeAdmin();
    Livewire::test(LoanObjectTypes::class)->callAction('createType', data: objectTypeData())->assertHasNoActionErrors();
    $type = LoanObjectType::sole();
    Livewire::test(LoanObjectTypes::class)->callAction('createType', data: objectTypeData('  gArRaFa  '))->assertHasActionErrors(['name']);
    Livewire::test(LoanObjectTypes::class)->callAction('createType', data: array_replace(objectTypeData('Teléfono'), ['rate' => '0']))->assertHasActionErrors(['rate']);
    Livewire::test(LoanObjectTypes::class)->callTableAction('editType', $type, data: ['name' => 'Cambio obsoleto', 'rate' => '20', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()])->assertHasTableActionErrors(['name']);
    expect(LoanObjectType::count())->toBe(1)->and($type->fresh()->rate_bps)->toBe(1500);
});
it('C212 restricts catalogue creation to Administration independently of fund permissions', function (string $role) {
    $admin = objectTypeAdmin();
    $admin->restrictedPermissions()->attach(Permission::findByName('prestamos.fondo.administrar'));
    $this->get(LoanObjectTypes::getUrl())->assertOk();
    $other = User::factory()->create();
    $other->assignRole($role);
    $this->actingAs($other);
    $this->get(LoanObjectTypes::getUrl())->assertForbidden();
    Livewire::test(LoanList::class)->assertActionHidden('objects');
})->with(['cajera', 'encargado']);
it('C212 searches filters and paginates object types including inactive ones', function () {
    objectTypeAdmin();
    for ($i = 0; $i < 6; $i++) {
        Livewire::test(LoanObjectTypes::class)->callAction('createType', data: objectTypeData('Objeto '.$i))->assertHasNoActionErrors();
    }
    $type = LoanObjectType::where('name', 'Objeto 0')->firstOrFail();
    Livewire::test(LoanObjectTypes::class)->callTableAction('editType', $type, data: ['name' => 'Objeto 0', 'rate' => '15', 'is_active' => false, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors();
    Livewire::test(LoanObjectTypes::class)->filterTable('is_active', '0')->assertCanSeeTableRecords([$type])->assertCountTableRecords(1);
    $types = LoanObjectType::orderBy('name')->get();
    Livewire::test(LoanObjectTypes::class)->assertCanSeeTableRecords($types->take(5))->assertCanNotSeeTableRecords($types->skip(5))->call('gotoPage', 2)->assertCanSeeTableRecords($types->skip(5));
    Livewire::test(LoanObjectTypes::class)->searchTable('Objeto 4')->assertCanSeeTableRecords([$types[4]])->assertCanNotSeeTableRecords([$types[0]]);
});

it('C212 denies a pending catalogue action immediately after permission revocation', function () {
    $admin = objectTypeAdmin();
    $page = Livewire::test(LoanObjectTypes::class)->mountAction('createType')->setActionData(objectTypeData());
    $admin->restrictedPermissions()->attach(Permission::findByName('prestamos.objetos.configurar'));
    $page->callMountedAction()->assertForbidden();
    expect(LoanObjectType::count())->toBe(0);
    $this->get(LoanSettings::getUrl())->assertOk();
    Livewire::test(LoanSettings::class)->assertActionHidden('objects');
});

it('C213 saves using only visible inputs and the identifiers generated by the actual modal', function () {
    objectTypeAdmin();
    Livewire::test(LoanObjectTypes::class)->mountAction('createType')->setActionData(['name' => 'Garrafa prueba natural'])->callMountedAction()->assertHasNoActionErrors();
    $type = LoanObjectType::sole();
    expect($type->rate_bps)->toBe(1500)->and($type->is_active)->toBeTrue();
    Livewire::test(LoanObjectTypes::class)->mountTableAction('editType', $type)->setTableActionData(['name' => 'Garrafa editada'])->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($type->fresh()->name)->toBe('Garrafa editada')->and($type->fresh()->revision)->toBe(2);
});

it('C213 keeps the same identifier after validation and replaces it only for a new modal', function () {
    objectTypeAdmin();
    $page = Livewire::test(LoanObjectTypes::class)->mountAction('createType');
    $key = $page->get('mountedActions.0.data.request_key');
    expect(Str::isUuid($key))->toBeTrue();
    $page->callMountedAction()->assertHasActionErrors(['name']);
    expect($page->get('mountedActions.0.data.request_key'))->toBe($key);
    $page->setActionData(['name' => 'Teléfono prueba natural'])->callMountedAction()->assertHasNoActionErrors();
    expect(LoanCommand::where('kind', 'TYPE_SAVE')->sole()->request_key)->toBe($key);
    $page->mountAction('createType');
    $next = $page->get('mountedActions.0.data.request_key');
    expect(Str::isUuid($next))->toBeTrue()->and($next)->not->toBe($key);
    $page->unmountAction();
    expect(LoanObjectType::count())->toBe(1);
});
