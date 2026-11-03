<?php

use App\Domain\Cajas\Actions\CreateCashBoxAction;
use App\Domain\Cajas\Models\CashBox;
use App\Filament\Resources\Cajas\CashBoxes\CashBoxResource;
use App\Filament\Resources\Cajas\CashBoxes\Pages\ListCashBoxes;
use App\Models\User;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
});

function cashBoxPanelUser(string $role): User
{
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole($role);

    return $user;
}

it('provides the administrative catalog inside the current panel', function () {
    $this->actingAs(cashBoxPanelUser('administrador'));
    $this->get(CashBoxResource::getUrl())->assertOk()->assertSee('Configuración de cajas');
    expect(collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Configuraciones'))->not->toBeNull();
});

it('denies the administrative catalog to cashiers and supervisors', function (string $role) {
    $this->actingAs(cashBoxPanelUser($role));
    $this->get(CashBoxResource::getUrl())->assertForbidden();
    $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map->getLabel()->all();
    expect($labels)->not->toContain('Configuración de cajas');
})->with(['cajera', 'encargado']);

it('registers a cash box through the floating form and its domain action', function () {
    $this->actingAs(cashBoxPanelUser('administrador'));
    Livewire::test(ListCashBoxes::class)->callAction('create', data: [
        'code' => 'BCP-UNO', 'name' => 'Caja uno', 'type' => 'BCP', 'base_capital' => '20000.50',
        'is_active' => true, 'pin' => '1234', 'reason' => 'Alta de caja autorizada', 'request_key' => (string) Str::uuid(),
    ])->assertHasNoActionErrors();
    $this->assertDatabaseHas('cash_boxes', ['code' => 'BCP-UNO', 'base_capital_cents' => 2000050]);
    $this->assertDatabaseCount('cash_box_audits', 1);
});

it('registers a box with the administrative session without requesting another PIN', function () {
    $this->actingAs(cashBoxPanelUser('administrador'));
    Livewire::test(ListCashBoxes::class)->callAction('create', data: [
        'code' => 'BCP-UNO', 'name' => 'Caja uno', 'type' => 'BCP', 'base_capital' => '20000',
        'is_active' => true, 'reason' => 'Alta de caja autorizada', 'request_key' => (string) Str::uuid(),
    ])->assertHasNoActionErrors();
    $this->assertDatabaseCount('cash_boxes', 1);
});

it('edits availability in a modal without changing reference capital', function () {
    $this->actingAs(cashBoxPanelUser('administrador'));
    $box = CashBox::factory()->create();
    Livewire::test(ListCashBoxes::class)->callTableAction('edit', $box, data: [
        'name' => 'Caja renovada', 'is_active' => false, 'expected_revision' => 1,
        'pin' => '1234', 'reason' => 'Desactivación de caja autorizada', 'request_key' => (string) Str::uuid(),
    ])->assertHasNoActionErrors();
    expect($box->fresh()->is_active)->toBeFalse()->and($box->fresh()->name)->toBe('Caja renovada')
        ->and($box->fresh()->base_capital_cents)->toBe(2000000);
});

it('paginates the latest five boxes and keeps older ones reachable', function () {
    $this->actingAs(cashBoxPanelUser('administrador'));
    $boxes = CashBox::factory()->count(7)->create()->sortByDesc('id')->values();
    Livewire::test(ListCashBoxes::class)->assertCanSeeTableRecords($boxes->take(5))
        ->assertCanNotSeeTableRecords($boxes->skip(5))->call('gotoPage', 2)
        ->assertCanSeeTableRecords($boxes->skip(5));
});

it('filters type and availability without loading all records', function () {
    $this->actingAs(cashBoxPanelUser('administrador'));
    $bcp = CashBox::factory()->create();
    $tigo = CashBox::factory()->create(['type' => 'TIGO_MONEY', 'is_active' => false]);
    Livewire::test(ListCashBoxes::class)->filterTable('type', 'TIGO_MONEY')
        ->assertCanSeeTableRecords([$tigo])->assertCanNotSeeTableRecords([$bcp]);
});

it('opens configuration history as read only and escapes its reason', function () {
    $admin = cashBoxPanelUser('administrador');
    $this->actingAs($admin);
    $box = app(CreateCashBoxAction::class)->execute($admin, [
        'code' => 'BCP-UNO', 'name' => 'Caja uno', 'type' => 'BCP', 'base_capital' => '20000',
        'is_active' => true, 'pin' => '1234', 'reason' => '<script>alert(1)</script>', 'request_key' => (string) Str::uuid(),
    ]);
    $page = Livewire::test(ListCashBoxes::class)->mountTableAction('historial', $box)
        ->assertActionMounted(TestAction::make('historial')->table($box));
    $instance = $page->instance();
    expect($instance->getMountedAction()->getModalSubmitAction())->toBeNull();
    $html = $instance->getSchema($instance->getMountedActionSchemaName())->toHtml();
    expect($html)->toContain('Registro de caja', '&lt;script&gt;alert(1)&lt;/script&gt;')->not->toContain('<script>alert(1)</script>');
});

it('keeps catalog reads bounded and loads audit history only on demand', function () {
    $this->actingAs(cashBoxPanelUser('administrador'));
    CashBox::factory()->count(20)->create();
    DB::enableQueryLog();
    $start = hrtime(true);
    try {
        Livewire::test(ListCashBoxes::class)->assertCountTableRecords(20);
        $queries = collect(DB::getQueryLog());
        $catalog = $queries->filter(fn (array $query): bool => str_contains($query['query'], '"cash_boxes"') && str_starts_with(mb_strtolower($query['query']), 'select'));
        $history = $queries->filter(fn (array $query): bool => str_contains($query['query'], '"cash_box_audits"'));
        expect($catalog->count())->toBeGreaterThan(0)->toBeLessThanOrEqual(3);
        expect($history)->toHaveCount(0);
        fwrite(STDOUT, "\nCashBox catalog: ".$catalog->count().' catalog SELECTs; '.$history->count().' audit SELECTs; '.round((hrtime(true) - $start) / 1000000)." ms (test environment).\n");
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});
