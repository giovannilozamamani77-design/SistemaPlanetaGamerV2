<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Models\AccessoryPriceChange;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Filament\Pages\Accesorios\AccessoryInventory;
use App\Filament\Pages\Accesorios\AccessoryQuickSale;
use App\Livewire\Accesorios\AccessorySaleHistory;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function quickSaleAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    Http::preventStrayRequests();
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);

    return $u;
}
function quickSaleProduct(User $u, int $qty = 10, string $cost = '10', string $price = '20', string $code = 'CABLE'): AccessoryProduct
{
    $p = app(CreateAccessoryProductAction::class)->execute($u, ['code' => $code, 'name' => 'Cable de carga '.preg_replace('/\p{N}/u', '', $code), 'sale_price' => $price, 'request_key' => (string) Str::uuid()]);
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost, 'kind' => 'INITIAL', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);

    return $p->refresh();
}
it('C252 builds a quick basket by code and quantity then confirms the cash payment and resets the form', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $this->get(AccessoryQuickSale::getUrl())->assertOk()->assertSee('Venta rápida')->assertSee('Agregar al carrito');
    $page = Livewire::test(AccessoryQuickSale::class);
    $key = $page->get('requestKey');
    $page->set('code', 'cable')->call('addByCode')->assertHasNoErrors()->assertSet('code', '')->call('addProduct', $p->id, 2)->assertHasNoErrors()->call('reviewSale')->assertMountedActionModalSee('Bs 60');
    $page->setActionData(['cash_received' => true])->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt')->assertSet('cart', []);
    expect($page->get('requestKey'))->not->toBe($key)->and($p->fresh()->quantity)->toBe(7)->and(CommercialFund::first()->balance_cents)->toBe(6000)->and(AccessorySale::count())->toBe(1);
    Http::assertNothingSent();
});
it('C252 cancel before final confirmation does not modify stock or record a sale', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id, 1)->call('reviewSale');
    $page->unmountAction();
    expect(AccessorySale::count())->toBe(0)->and($p->fresh()->quantity)->toBe(10);
    expect($page->get('cart'))->toHaveCount(1);
});
it('C252 blocks a stale confirmation when another cashier sells and allows reviewing the refreshed basket', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id, 2)->call('reviewSale');
    app(RegisterAccessorySaleAction::class)->execute($u, ['lines' => [['product_id' => $p->id, 'quantity' => 1, 'expected_revision' => $p->revision, 'expected_price_cents' => 2000]], 'cash_received' => true, 'payment_method' => 'CASH', 'request_key' => (string) Str::uuid()]);
    $page->setActionData(['cash_received' => true])->callMountedAction()->assertHasErrors(['cart']);
    expect(AccessorySale::count())->toBe(1)->and($p->fresh()->quantity)->toBe(9);
    $page->call('removeProduct', $p->id)->call('addProduct', $p->id, 2)->call('reviewSale')->setActionData(['cash_received' => true])->callMountedAction();
    expect(AccessorySale::count())->toBe(2)->and($p->fresh()->quantity)->toBe(7);
});
it('C252 gives cashier sales and history access without acquisition cost or profit details', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u, 10, '12.34', '20');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    $this->get(AccessoryQuickSale::getUrl())->assertOk()->assertDontSee('12.34');
    Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id, 1)->call('reviewSale')->setActionData(['cash_received' => true])->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt');
    $sale = AccessorySale::firstOrFail();
    Livewire::test(AccessorySaleHistory::class)->mountAction(TestAction::make('detail')->table($sale))->assertMountedActionModalDontSee('Costo aplicado')->assertMountedActionModalDontSee('Ganancia')->assertMountedActionModalSee('Cable de carga');
});
it('C252 paginates recent and older sales across cashiers', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    for ($i = 0; $i < 6; $i++) {
        $p->refresh();
        app(RegisterAccessorySaleAction::class)->execute($u, ['lines' => [['product_id' => $p->id, 'quantity' => 1, 'expected_revision' => $p->revision, 'expected_price_cents' => 2000]], 'cash_received' => true, 'payment_method' => 'CASH', 'request_key' => (string) Str::uuid()]);
    }
    $sales = AccessorySale::orderByDesc('id')->get();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    $history = Livewire::test(AccessorySaleHistory::class)->assertCanSeeTableRecords($sales->take(5))->assertCanNotSeeTableRecords($sales->skip(5));
    $history->call('gotoPage', 2)->assertCanSeeTableRecords($sales->skip(5));
});
it('C252 hides zero stock and disables below cost products while retaining useful search', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u, 5, '15', '10', 'BLOCKED');
    $q = quickSaleProduct($u, 1, '1', '2', 'SOLD');
    app(RegisterAccessorySaleAction::class)->execute($u, ['lines' => [['product_id' => $q->id, 'quantity' => 1, 'expected_revision' => $q->revision, 'expected_price_cents' => 200]], 'cash_received' => true, 'payment_method' => 'CASH', 'request_key' => (string) Str::uuid()]);
    $page = Livewire::test(AccessoryQuickSale::class)->assertSee('Revisar precio con Administración')->assertDontSee('Cable de carga SOLD')->call('addProduct', $p->id, 1)->assertHasErrors(['cart']);
    $page->set('search', 'No existe')->assertSee('No hay accesorios disponibles');
    expect(AccessorySale::count())->toBe(1);
});
it('C252 checks permission again before accepting a reviewed sale', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id, 1)->call('reviewSale')->setActionData(['cash_received' => true]);
    $u->removeRole('administrador');
    $page->callMountedAction()->assertForbidden();
    expect(AccessorySale::count())->toBe(0);
});

it('C252 provides an audited inventory price correction to Administration and hides it from cashier', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u, 5, '15', '10', 'PRICE');
    Livewire::test(AccessoryInventory::class)->callAction(TestAction::make('updatePrice')->table($p), data: ['price_mode' => 'MANUAL', 'manual_price' => '18'])->assertHasNoActionErrors();
    expect($p->fresh()->sale_price_cents)->toBe(1800)->and(AccessoryPriceChange::count())->toBe(1);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    Livewire::test(AccessoryInventory::class)->assertActionHidden(TestAction::make('updatePrice')->table($p));
});
it('C252 keeps cart data locked against browser changes and does not accept forged price changes', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id, 1);
    expect(fn () => $page->set('cart.0.expected_price_cents', 1))->toThrow(CannotUpdateLockedPropertyException::class);
    expect($p->fresh()->quantity)->toBe(10)->and(AccessorySale::count())->toBe(0);
});

it('C253 finds accessory keywords across names descriptions and accents without requiring exact names', function () {
    $u = quickSaleAdmin();
    $a = quickSaleProduct($u, 10, '5', '15', 'CHG-USB');
    $a->update(['name' => 'Cargador rápido USB C', 'category' => 'Cargadores', 'description' => 'Carga rápida para celular']);
    $b = quickSaleProduct($u, 10, '5', '15', 'AUDIO');
    $b->update(['name' => 'Audífonos inalámbricos', 'description' => 'Bluetooth con micrófono']);
    $q = app(AccessorySaleQuery::class);
    expect($q->products($u, 'cargador celular')->pluck('id')->all())->toBe([$a->id]);
    expect($q->products($u, 'audifonos')->pluck('id')->all())->toBe([$b->id]);
    expect($q->products($u, 'MICROFONO')->pluck('id')->all())->toBe([$b->id]);
});
it('C253 preserves leading barcode zeroes and repeated scans increase one cart line without charging', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u, 3, '5', '15', '0000123456789');
    $page = Livewire::test(AccessoryQuickSale::class)->set('code', " 0000123456789\r\n")->call('addByCode')->assertHasNoErrors()->assertDispatched('accessory-scan-ready');
    $page->set('code', '0000123456789')->call('addByCode')->assertHasNoErrors();
    expect($page->get('cart'))->toHaveCount(1)->and($page->get('cart.0.quantity'))->toBe(2);
    $page->set('code', 'NO-EXISTE')->call('addByCode')->assertHasErrors(['code']);
    expect($page->get('cart.0.quantity'))->toBe(2)->and(AccessorySale::count())->toBe(0)->and($p->fresh()->quantity)->toBe(3);
});
it('C253 final review shows product descriptions quantities and prices and leaves the saved receipt available', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $p->update(['description' => 'Cable reforzado de dos metros']);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id, 2)->call('reviewSale')->assertMountedActionModalSee('Cable reforzado de dos metros')->assertMountedActionModalSee('2 unidades')->assertMountedActionModalSee('Sin descuento');
    $page->setActionData(['cash_received' => true])->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt')->assertMountedActionModalSee('Imprimir recibo')->assertMountedActionModalSee('Descargar PDF');
    expect($page->get('lastSaleId'))->toBe(AccessorySale::first()->id);
});

it('C253 serial scanner method adds each scan and keeps previous quantities on unknown or excess scans', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u, 2, '5', '15', '0000123456789');
    $page = Livewire::test(AccessoryQuickSale::class)->call('scanCode', '0000123456789')->call('scanCode', '0000123456789')->assertHasNoErrors();
    expect($page->get('cart.0.quantity'))->toBe(2);
    $page->call('scanCode', '0000123456789')->assertHasErrors(['cart']);
    expect($page->get('cart.0.quantity'))->toBe(2)->and(AccessorySale::count())->toBe(0);
});
it('C253 keyword wildcards are literal and matching tolerates capital accented product names', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $p->update(['name' => 'AUDÍFONOS de prueba', 'description' => 'Oferta 10%']);
    $q = app(AccessorySaleQuery::class);
    expect($q->products($u, 'audifonos prueba')->pluck('id')->all())->toBe([$p->id])->and($q->products($u, '10%')->pluck('id')->all())->toBe([$p->id])->and($q->products($u, '_')->count())->toBe(0);
});

it('C291 previews the discounted total in checkout and records the same net cash without changing the catalogue price', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $p->update(['sale_price_cents' => 6000]);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id)->call('reviewSale')->setActionData(['final_prices' => ['a_'.$p->id => '57']]);
    $page->assertMountedActionModalSee('Bs 57')->callMountedAction()->assertHasNoErrors();
    expect(AccessorySale::sole()->total_cents)->toBe(5700);
    expect(CommercialFund::sole()->balance_cents)->toBe(5700);
    expect($p->fresh()->sale_price_cents)->toBe(6000);
});

it('C301 adds accessories with a product click and removes the complete cart line without a sale', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u, 2);
    $page = Livewire::test(AccessoryQuickSale::class)
        ->assertSee('wire:click="addProduct('.$p->id.')"', false)
        ->call('addProduct', $p->id)->assertHasNoErrors()
        ->call('addProduct', $p->id)->assertHasNoErrors();
    expect($page->get('cart')[0]['quantity'])->toBe(2);
    $page->call('addProduct', $p->id)->assertHasErrors('cart');
    $page->call('removeProduct', $p->id)->assertSet('cart', []);
    expect($p->fresh()->quantity)->toBe(2)->and(AccessorySale::count())->toBe(0);
    Http::assertNothingSent();
});

it('C303 changes cart quantity without changing stock and removes the last unit', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u, 2);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id)
        ->call('addProduct', $p->id)->assertHasNoErrors();
    expect($page->get('cart')[0]['quantity'])->toBe(2);
    $page->call('addProduct', $p->id)->assertHasErrors('cart');
    $page->call('decreaseProduct', $p->id)->assertHasNoErrors();
    expect($page->get('cart')[0]['quantity'])->toBe(1);
    $page->call('decreaseProduct', $p->id)->assertSet('cart', []);
    expect($p->fresh()->quantity)->toBe(2)->and(AccessorySale::count())->toBe(0);
});

it('C308 shows the saved accessory receipt success modal and closing it does not charge again', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id, 2)->call('reviewSale')
        ->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt')
        ->assertMountedActionModalSee('Venta registrada correctamente')
        ->assertMountedActionModalSee('Recibo listo')
        ->assertMountedActionModalSee('Bs 40')
        ->assertMountedActionModalSee('Imprimir recibo')
        ->assertMountedActionModalSee('Descargar PDF');
    $sale = AccessorySale::sole();
    expect($sale->receipt_required)->toBeFalse();
    $page->unmountAction()->assertSet('cart', []);
    $this->get(route('accesorios.recibo', ['sale' => $sale->id]))->assertOk()->assertSee('Bs 40');
    $this->get(route('accesorios.recibo-pdf', ['sale' => $sale->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(AccessorySale::count())->toBe(1)->and($p->fresh()->quantity)->toBe(8)->and(CommercialFund::sole()->balance_cents)->toBe(4000);
    Http::assertNothingSent();
});

it('C309 starts a new sale from the success modal without charging the previous sale again', function () {
    $u = quickSaleAdmin();
    $p = quickSaleProduct($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $p->id)->call('reviewSale')
        ->callMountedAction()->assertActionMounted('phoneReceipt')->assertMountedActionModalSee('Nueva venta');
    $key = $page->get('requestKey');
    $page->call('newSale')->assertHasNoErrors()->assertActionNotMounted('phoneReceipt')
        ->assertSet('lastSaleId', null)->assertSet('cart', [])->assertSet('phoneCart', [])
        ->assertDispatched('accessory-scan-ready');
    expect($page->get('requestKey'))->not->toBe($key);
    expect(AccessorySale::count())->toBe(1)->and($p->fresh()->quantity)->toBe(9)->and(CommercialFund::sole()->balance_cents)->toBe(2000);
    $page->call('addProduct', $p->id)->assertHasNoErrors();
    expect($page->get('cart'))->toHaveCount(1)->and(AccessorySale::count())->toBe(1);
    Http::assertNothingSent();
});
