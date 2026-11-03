<?php

use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\ReceiveEntelCardsAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Filament\Pages\Tarjetas\InventoryMovements;
use App\Filament\Pages\Tarjetas\InventoryPrices;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Filament\Pages\Tarjetas\StoreInventory;
use App\Models\User;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function c143PanelUser(string $role = 'administrador'): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}
function c143PanelProduct(User $admin, string $company = 'TIGO'): CardProduct
{
    return app(SaveCardProductAction::class)->execute($admin, ['company' => $company, 'face_value' => '10', 'purchase_price' => '9.40', 'normal_price' => '10', 'wholesale_price' => '9.50', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
}
it('C143 renders the store inventory without inventing an initial cash amount or enabling cashier configuration', function () {
    $cashier = c143PanelUser('cajera');
    $this->actingAs($cashier);
    Livewire::test(StoreInventory::class)->assertSee('Sin conteo inicial')->assertActionHidden('createProduct')->assertActionHidden('initializeStore')->assertActionHidden('purchase')->assertActionHidden('receiveEntel')->assertActionVisible('sale');
    $this->assertDatabaseCount('card_stores', 0);
});
it('C143 creates a product and registers actual initial counts through the administrative modals', function () {
    $admin = c143PanelUser();
    $this->actingAs($admin);
    Livewire::test(StoreInventory::class)->callAction('createProduct', data: ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.40', 'normal_price' => '10', 'wholesale_price' => '9.50', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    $p = CardProduct::sole();
    Livewire::test(StoreInventory::class)->callAction('initializeStore', data: ['cash_amount' => '1000', 'stocks' => [$p->id => ['product_id' => $p->id, 'quantity' => 100]], 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors()->assertSee('Bs 1000')->assertActionHidden('initializeStore');
    expect(CardStock::where('card_product_id', $p->id)->sole()->quantity)->toBe(100)->and(CardStore::sole()->cash_balance_cents)->toBe(100000);
});
it('C143 records purchases and Entel deliveries through cashier forms and shows updated expected cash', function () {
    $admin = c143PanelUser();
    $cashier = c143PanelUser('cajera');
    $t = c143PanelProduct($admin);
    $e = c143PanelProduct($admin, 'ENTEL');
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '1000', 'stocks' => [], 'request_key' => (string) Str::uuid()]);
    CardStore::sole()->update(['session_control_started_at' => now()]);
    CardStoreSession::create(['card_store_id' => CardStore::sole()->id, 'user_id' => $cashier->id, 'active_slot' => 1, 'opened_at' => now()]);
    $this->actingAs($cashier);
    Livewire::test(QuickCardSale::class)->callAction('purchase', data: ['company' => 'TIGO', 'lines' => [['product_id' => $t->id, 'quantity' => 10, 'unit_price' => '9.40']], 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    Livewire::test(StoreInventory::class)->assertSee('Bs 906');
    Livewire::test(QuickCardSale::class)->callAction('receiveEntel', data: ['company' => 'ENTEL', 'lines' => [['product_id' => $e->id, 'quantity' => 200]], 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    Livewire::test(StoreInventory::class)->assertSee('Bs 906');
    expect(CardStore::sole()->cash_balance_cents)->toBe(90600)->and(CardStock::where('card_product_id', $e->id)->sole()->quantity)->toBe(200);
});
it('C143 displays insufficient cash in the purchase modal without registering a stock entry', function () {
    $admin = c143PanelUser();
    $p = c143PanelProduct($admin);
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '1', 'stocks' => [], 'request_key' => (string) Str::uuid()]);
    $this->actingAs($admin);
    Livewire::test(StoreInventory::class)->callAction('purchase', data: ['company' => 'TIGO', 'lines' => [['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '9.40']], 'request_key' => (string) Str::uuid()])->assertHasActionErrors(['lines']);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100)->and(CardInventoryMovement::where('kind', 'PURCHASE')->count())->toBe(0);
});
it('C143 edits catalogue prices through a modal and keeps prior costs in movement details', function () {
    $admin = c143PanelUser();
    $p = c143PanelProduct($admin, 'ENTEL');
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '50', 'stocks' => [], 'request_key' => (string) Str::uuid()]);
    $m = app(ReceiveEntelCardsAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 2, 'request_key' => (string) Str::uuid()]);
    $this->actingAs($admin);
    Livewire::test(InventoryPrices::class)->callTableAction('editProduct', $p, data: ['company' => 'ENTEL', 'face_value' => '10', 'purchase_price' => '9.60', 'normal_price' => '10.50', 'wholesale_price' => '9.80', 'is_active' => true, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors();
    expect($p->fresh()->normal_price_cents)->toBe(1050)->and($m->fresh()->product_snapshot['normal_price_cents'])->toBe(1000);
    Livewire::test(InventoryMovements::class)->mountTableAction('detail', $m)->assertActionMounted(TestAction::make('detail')->table($m))->assertMountedActionModalSee('No informado')->assertMountedActionModalSee('Bs 50');
});
it('C143 paginates movement history at five without hiding older entries', function () {
    $admin = c143PanelUser();
    $p = c143PanelProduct($admin, 'ENTEL');
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '0', 'stocks' => [], 'request_key' => (string) Str::uuid()]);
    for ($i = 0; $i < 7; $i++) {
        app(ReceiveEntelCardsAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 1, 'request_key' => (string) Str::uuid()]);
    }
    $latest = CardInventoryMovement::latest('id')->limit(5)->get();
    $older = CardInventoryMovement::oldest('id')->limit(3)->get();
    $this->actingAs($admin);
    Livewire::test(InventoryMovements::class)->assertCanSeeTableRecords($latest)->assertCanNotSeeTableRecords($older)->call('gotoPage', 2)->assertCanSeeTableRecords($older);
});
it('C143 denies inventory and history URLs after an individual permission restriction', function () {
    $cashier = c143PanelUser('cajera');
    $this->actingAs($cashier);
    $this->get(StoreInventory::getUrl())->assertOk();
    $this->get(InventoryMovements::getUrl())->assertOk();
    $cashier->restrictedPermissions()->attach(Permission::whereIn('name', ['tarjetas.inventario.ver', 'tarjetas.movimientos.ver'])->pluck('id'));
    $this->actingAs($cashier->fresh());
    $this->get(StoreInventory::getUrl())->assertForbidden();
    $this->get(InventoryMovements::getUrl())->assertForbidden();
});

it('C143 closes a cancelled purchase form without changing cash inventory or history', function () {
    $admin = c143PanelUser();
    $p = c143PanelProduct($admin);
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '100', 'stocks' => [], 'request_key' => (string) Str::uuid()]);
    $this->actingAs($admin);
    Livewire::test(StoreInventory::class)->mountAction('purchase')->assertActionMounted('purchase')->unmountAction()->assertActionNotMounted('purchase');
    expect(CardStore::sole()->cash_balance_cents)->toBe(10000)->and(CardInventoryMovement::count())->toBe(1)->and(CardStock::where('card_product_id', $p->id)->sole()->quantity)->toBe(0);
});
it('C143 keeps product configuration hidden in cashier row actions while retaining inventory access', function () {
    $admin = c143PanelUser();
    $cashier = c143PanelUser('cajera');
    $p = c143PanelProduct($admin);
    $this->actingAs($cashier);
    Livewire::test(StoreInventory::class)->call('selectCompany', 'TIGO')->assertCanSeeTableRecords([$p])->assertTableActionHidden('editProduct', $p)->assertActionHidden('createProduct')->assertActionHidden('initializeStore');
});

it('C143 escapes a delivery reference in the read only detail modal', function () {
    $admin = c143PanelUser();
    $p = c143PanelProduct($admin, 'ENTEL');
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '0', 'stocks' => [], 'request_key' => (string) Str::uuid()]);
    $m = app(ReceiveEntelCardsAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 1, 'reference' => '<img src=x onerror=alert(1)>', 'request_key' => (string) Str::uuid()]);
    $this->actingAs($admin);
    Livewire::test(InventoryMovements::class)->mountTableAction('detail', $m)->assertMountedActionModalSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertMountedActionModalDontSee('<img src=x onerror=alert(1)>', false);
});

it('C144 shows all registered cuts as cards including empty stock and keeps prices in an administrative list', function () {
    $admin = c143PanelUser();
    $tigo = c143PanelProduct($admin);
    $entel = c143PanelProduct($admin, 'ENTEL');
    $this->actingAs($admin);
    Livewire::test(StoreInventory::class)->assertCanSeeTableRecords([$entel])->assertCanNotSeeTableRecords([$tigo])->call('selectCompany', 'TIGO')->assertCanSeeTableRecords([$tigo])->assertCanNotSeeTableRecords([$entel])->assertSee('tarjetas en stock')->assertActionVisible('prices');
    Livewire::test(InventoryPrices::class)->assertCanSeeTableRecords([$tigo, $entel])->assertSee('Mayorista')->assertSee('Compra');
    expect(CardStock::sum('quantity'))->toBe(0)->and(CardInventoryMovement::count())->toBe(0);
});
it('C144 denies cashier price settings URL and hides its shortcut', function () {
    $cashier = c143PanelUser('cajera');
    $this->actingAs($cashier);
    Livewire::test(StoreInventory::class)->assertActionHidden('prices');
    $this->get(InventoryPrices::getUrl())->assertForbidden();
});

it('C145 switches between three companies without changing stock or cash', function () {
    $admin = c143PanelUser();
    $products = collect(['ENTEL', 'TIGO', 'VIVA'])->mapWithKeys(fn ($company) => [$company => c143PanelProduct($admin, $company)]);
    $this->actingAs($admin);
    $page = Livewire::test(StoreInventory::class);
    foreach ($products as $company => $product) {
        $page->call('selectCompany', $company)->assertCanSeeTableRecords([$product])->assertCanNotSeeTableRecords($products->except($company));
    }
    expect(CardInventoryMovement::count())->toBe(0)->and(CardStock::sum('quantity'))->toBe(0);
});

it('C147 uses an actual card layout instead of a table with a custom cell', function () {
    $this->actingAs(c143PanelUser());
    $page = Livewire::test(StoreInventory::class);
    expect($page->instance()->getTable()->hasColumnsLayout())->toBeTrue();
    $page->assertSeeHtml('pg-inventory-panel');
});

it('C152 preloads company cuts and updates totals before saving the initial count', function () {
    $admin = c143PanelUser();
    $entel = c143PanelProduct($admin, 'ENTEL');
    $tigo = c143PanelProduct($admin, 'TIGO');
    $viva = c143PanelProduct($admin, 'VIVA');
    $this->actingAs($admin);
    $page = Livewire::test(StoreInventory::class)->mountAction('initializeStore')->assertMountedActionModalSee('Resumen del conteo inicial');
    $page->fillForm(['cash_amount' => '50.03', 'stocks' => [
        $entel->id => ['product_id' => $entel->id, 'quantity' => 3],
        $tigo->id => ['product_id' => $tigo->id, 'quantity' => 7],
        $viva->id => ['product_id' => $viva->id, 'quantity' => 0],
    ]])->assertMountedActionModalSee('Total contado: 10 tarjetas')->assertMountedActionModalSee('Bs 50.03');
    expect(CardStore::sole()->initialized_at)->toBeNull()->and(CardInventoryMovement::count())->toBe(0);
    $page->callMountedAction()->assertHasNoActionErrors();
    expect(CardStock::sum('quantity'))->toBe(10)->and(CardStore::sole()->cash_balance_cents)->toBe(5003);
    expect(CardInventoryMovement::where('kind', 'INITIAL_STOCK')->whereNotNull('unit_cost_cents')->count())->toBe(0);
});
it('C152 cancels initial counting without registering balances', function () {
    $admin = c143PanelUser();
    c143PanelProduct($admin);
    $this->actingAs($admin);
    Livewire::test(StoreInventory::class)->mountAction('initializeStore')->fillForm(['cash_amount' => '500'])->unmountAction();
    expect(CardStore::sole()->initialized_at)->toBeNull()->and(CardStock::sum('quantity'))->toBe(0)->and(CardInventoryMovement::count())->toBe(0);
});

it('C154 identifies Cabina Viva cuts in inventory initial counting and price settings without changing money or stock', function () {
    $admin = c143PanelUser();
    $products = [];
    foreach ([40, 100] as $face) {
        $products[] = app(SaveCardProductAction::class)->execute($admin, ['company' => 'VIVA', 'face_value' => (string) $face, 'purchase_price' => $face === 40 ? '39.50' : '99', 'normal_price' => (string) $face, 'wholesale_price' => (string) $face, 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    }
    $normal = c143PanelProduct($admin, 'VIVA');
    $before = CardProduct::orderBy('id')->get()->toArray();
    $this->actingAs($admin);
    Livewire::test(StoreInventory::class)->call('selectCompany', 'VIVA')->assertSee('Cabina Viva de Bs 40')->assertSee('Cabina Viva de Bs 100');
    Livewire::test(StoreInventory::class)->mountAction('initializeStore')->assertMountedActionModalSee('Cabina Viva de Bs 40')->assertMountedActionModalSee('Cabina Viva de Bs 100');
    Livewire::test(InventoryPrices::class)->assertSee('Cabina Viva de Bs 40')->assertSee('Cabina Viva de Bs 100');
    expect($products[0]->label())->toBe('Cabina Viva de Bs 40')->and($normal->label())->not->toContain('Cabina')
        ->and(CardProduct::orderBy('id')->get()->toArray())->toBe($before)
        ->and(CardStock::sum('quantity'))->toBe(0)->and(CardInventoryMovement::count())->toBe(0)->and(CardStore::sole()->initialized_at)->toBeNull();
});
