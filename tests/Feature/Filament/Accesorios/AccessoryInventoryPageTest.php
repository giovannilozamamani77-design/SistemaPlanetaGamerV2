<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessoryStockEntry;
use App\Filament\Pages\Accesorios\AccessoryInventory;
use App\Livewire\Accesorios\StockEntryHistory;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function inventoryPageAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    Http::preventStrayRequests();
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);

    return $u;
}
function inventoryPageProduct(User $u): AccessoryProduct
{
    return app(CreateAccessoryProductAction::class)->execute($u, ['code' => 'CHARGER', 'name' => 'Cargador USB', 'sale_price' => '50', 'request_key' => (string) Str::uuid()]);
}
it('C251 renders inventory and receives initial stock through the administrative modal', function () {
    $admin = inventoryPageAdmin();
    $this->get(AccessoryInventory::getUrl())->assertOk()->assertSee('Inventario de accesorios');
    $p = inventoryPageProduct($admin);
    $page = Livewire::test(AccessoryInventory::class);
    $page->callAction(TestAction::make('receive')->table($p), data: ['kind' => 'INITIAL', 'quantity' => 5, 'unit_cost' => '12.34', 'supplier' => 'Distribuidor', 'note' => 'Carga inicial'])->assertHasNoActionErrors()->assertCanSeeTableRecords([$p->refresh()]);
    expect($p->quantity)->toBe(5)->and($p->stock_value_cents)->toBe(6170)->and(AccessoryStockEntry::count())->toBe(1);
    $page->mountAction(TestAction::make('detail')->table($p))->assertMountedActionModalSee('12.34')->assertMountedActionModalSee('Precio confirmado');
    Http::assertNothingSent();
});
it('C251 cancels stock receipt without changes and displays input errors', function () {
    $u = inventoryPageAdmin();
    $p = inventoryPageProduct($u);
    Livewire::test(AccessoryInventory::class)->mountAction(TestAction::make('receive')->table($p))->setActionData(['quantity' => 5, 'unit_cost' => '10'])->unmountAction();
    expect(AccessoryStockEntry::count())->toBe(0);
    Livewire::test(AccessoryInventory::class)->callAction(TestAction::make('receive')->table($p), data: ['kind' => 'INITIAL', 'quantity' => 5, 'unit_cost' => '-1'])->assertHasActionErrors(['unit_cost']);
    expect($p->fresh()->quantity)->toBe(0);
});
it('C251 gives cashier stock and confirmed prices but no cost history or write actions', function () {
    $u = inventoryPageAdmin();
    $p = inventoryPageProduct($u);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    $this->get(AccessoryInventory::getUrl())->assertOk()->assertDontSee('Costo promedio · Bs')->assertDontSee('Inversión · Bs');
    Livewire::test(AccessoryInventory::class)->assertActionHidden('createProduct')->assertActionHidden(TestAction::make('receive')->table($p))->assertActionHidden(TestAction::make('detail')->table($p))->assertCanSeeTableRecords([$p]);
    Livewire::test(StockEntryHistory::class, ['productId' => $p->id])->assertForbidden();
});
it('C251 paginates all original purchase costs and searches accessories', function () {
    $u = inventoryPageAdmin();
    $p = inventoryPageProduct($u);
    for ($i = 0; $i < 6; $i++) {
        app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $p->id, 'quantity' => 1, 'unit_cost' => (string) (10 + $i), 'kind' => 'PURCHASE', 'expected_revision' => $p->fresh()->revision, 'request_key' => (string) Str::uuid()]);
    }
    $events = AccessoryStockEntry::orderByDesc('id')->get();
    $history = Livewire::test(StockEntryHistory::class, ['productId' => $p->id])->assertCanSeeTableRecords($events->take(5))->assertCanNotSeeTableRecords($events->skip(5));
    $history->call('gotoPage', 2)->assertCanSeeTableRecords($events->skip(5));
    Livewire::test(AccessoryInventory::class)->searchTable('Cargador')->assertCanSeeTableRecords([$p])->searchTable('No existe')->assertCanNotSeeTableRecords([$p]);
});
it('C251 rechecks a revoked inventory permission before a submitted stock entry', function () {
    $u = inventoryPageAdmin();
    $p = inventoryPageProduct($u);
    $page = Livewire::test(AccessoryInventory::class)->mountAction(TestAction::make('receive')->table($p))->setActionData(['kind' => 'INITIAL', 'quantity' => 5, 'unit_cost' => '10']);
    $u->removeRole('administrador');
    $page->callMountedAction()->assertForbidden();
    expect($p->fresh()->quantity)->toBe(0)->and(AccessoryStockEntry::count())->toBe(0);
});

it('C258 browses fifteen products per page without hiding earlier records', function () {
    $u = inventoryPageAdmin();
    $products = collect();
    for ($i = 1; $i <= 16; $i++) {
        $products->push(app(CreateAccessoryProductAction::class)->execute($u, ['code' => 'GRID-'.$i, 'name' => 'Producto '.chr(96 + $i), 'sale_price' => '50', 'request_key' => (string) Str::uuid()]));
    }

    $page = Livewire::test(AccessoryInventory::class)->assertCanSeeTableRecords($products->take(15))->assertCanNotSeeTableRecords($products->skip(15));
    $page->call('gotoPage', 2)->assertCanSeeTableRecords($products->skip(15))->assertCanNotSeeTableRecords($products->take(15));
    $page->searchTable('GRID-1')->assertCanSeeTableRecords([$products->first()]);
});
it('C258 searches public product fields and decimal sale prices without exposing costs', function () {
    $u = inventoryPageAdmin();
    $p = app(CreateAccessoryProductAction::class)->execute($u, ['code' => '000123', 'name' => 'Audifono Azul', 'category' => 'Gaming', 'sale_price' => '50.25', 'request_key' => (string) Str::uuid()]);
    $other = inventoryPageProduct($u);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);

    $page = Livewire::test(AccessoryInventory::class);
    foreach (['000123', 'Azul', 'Gaming', '50.25', '50,25'] as $search) {
        $page->searchTable($search)->assertCanSeeTableRecords([$p])->assertCanNotSeeTableRecords([$other]);
    }
    $page->assertActionHidden(TestAction::make('receive')->table($p))->assertActionHidden(TestAction::make('updatePrice')->table($p));
});
