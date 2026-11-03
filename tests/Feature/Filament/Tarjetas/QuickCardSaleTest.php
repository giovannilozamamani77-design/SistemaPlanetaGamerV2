<?php

use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Filament\Pages\Tarjetas\InventoryPrices;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Models\User;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function c155Panel(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $product = app(SaveCardProductAction::class)->execute($admin, ['company' => 'VIVA', 'face_value' => '40', 'purchase_price' => '39.50', 'normal_price' => '40', 'wholesale_price' => '40', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '10', 'stocks' => [['product_id' => $product->id, 'quantity' => 20]], 'request_key' => (string) Str::uuid()]);
    test()->actingAs($cashier);

    CardStore::sole()->update(['session_control_started_at' => now()]);
    CardStoreSession::create(['card_store_id' => CardStore::sole()->id, 'user_id' => $cashier->id, 'active_slot' => 1, 'opened_at' => now()]);

    return [$admin, $cashier, $product];
}
it('C155 renders the sale form and records Cabina Viva with actual cash and stock updates', function () {
    [$admin, $cashier, $p] = c155Panel();
    $page = Livewire::test(QuickCardSale::class)->assertSee('Tarjetas · '.$cashier->name)->fillForm(['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '40']], 'request_key' => (string) Str::uuid()]);
    $page->assertSee('Total a cobrar: Bs 80')->call('registerSale')->assertHasNoFormErrors()->assertSee('Bs 90');
    expect(CardSale::sole()->user_id)->toBe($cashier->id)->and(CardStock::sole()->quantity)->toBe(18)->and(CardStore::sole()->cash_balance_cents)->toBe(9000);
});
it('C155 displays shortage against the cart row without partially registering the sale', function () {
    [$admin, $cashier, $p] = c155Panel();
    Livewire::test(QuickCardSale::class)->fillForm(['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $p->id, 'quantity' => 21, 'unit_price' => '40']], 'request_key' => (string) Str::uuid()])->call('registerSale')->assertHasFormErrors();
    expect(CardSale::count())->toBe(0)->and(CardStock::sole()->quantity)->toBe(20)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
});
it('C155 permits only the administrator to enable Cabina wholesale and change the combined minimum', function () {
    [$admin, $cashier, $p] = c155Panel();
    $this->get(InventoryPrices::getUrl())->assertForbidden();
    $this->actingAs($admin);
    Livewire::test(InventoryPrices::class)->callAction('wholesaleRules', data: ['minimum' => 2, 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    Livewire::test(InventoryPrices::class)->callTableAction('editProduct', $p, data: ['company' => 'VIVA', 'face_value' => '40', 'purchase_price' => '39.50', 'normal_price' => '40', 'wholesale_price' => '39.80', 'is_active' => true, 'wholesale_enabled' => true, 'expected_revision' => $p->revision, 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    $this->actingAs($cashier);
    Livewire::test(QuickCardSale::class)->fillForm(['mode' => 'WHOLESALE', 'payment_method' => 'CASH', 'lines' => [['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '39.80']], 'request_key' => (string) Str::uuid()])->call('registerSale')->assertHasNoFormErrors();
    expect(CardSale::sole()->total_cents)->toBe(7960)->and(CardSale::sole()->mode)->toBe('WHOLESALE');
});

it('C155 prefills selected prices and switches modes explicitly without creating a sale', function () {
    [$admin,$cashier,$cabina] = c155Panel();
    $p = app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.4', 'normal_price' => '10', 'wholesale_price' => '9.5', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $page = Livewire::test(QuickCardSale::class);
    $key = array_key_first($page->get('data.lines'));
    $page->set('data.lines.'.$key.'.product_id', $p->id)->assertSet('data.lines.'.$key.'.unit_price', '10')
        ->set('data.lines.'.$key.'.quantity', 6)->assertSet('data.mode', 'NORMAL')->assertSee('Total a cobrar: Bs 60')
        ->set('data.mode', 'WHOLESALE')->assertSet('data.lines.'.$key.'.unit_price', '9.50')->assertSee('Total a cobrar: Bs 57');
    expect(CardSale::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
});
it('C155 retries the same submitted form without another cash receipt or stock deduction', function () {
    [$admin,$cashier,$p] = c155Panel();
    $data = ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '40']], 'request_key' => (string) Str::uuid()];
    $page = Livewire::test(QuickCardSale::class)->fillForm($data)->call('registerSale')->assertHasNoFormErrors();
    $page->fillForm($data)->call('registerSale')->assertHasNoFormErrors();
    expect(CardSale::count())->toBe(1)->and(CardStock::sole()->quantity)->toBe(18)->and(CardStore::sole()->cash_balance_cents)->toBe(9000);
});
it('C155 refuses the page after sales permission is individually revoked and never exposes digital balances', function () {
    [$admin,$cashier,$p] = c155Panel();
    Livewire::test(QuickCardSale::class)->assertDontSee('Saldos digitales Entel');
    $cashier->restrictedPermissions()->attach(Permission::findByName('tarjetas.ventas.registrar'));
    $this->actingAs($cashier->fresh());
    $this->get(QuickCardSale::getUrl())->assertForbidden();
});
it('C155 abandons an unconfirmed cart without writing sale stock or cash', function () {
    [$admin,$cashier,$p] = c155Panel();
    Livewire::test(QuickCardSale::class)->fillForm(['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '40']]]);
    Livewire::test(QuickCardSale::class)->assertSee('Tarjetas · '.$cashier->name);
    expect(CardSale::count())->toBe(0)->and(CardStock::sole()->quantity)->toBe(20)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
});

it('C155 shows the five latest sales and allows older pages and frozen Cabina detail', function () {
    [$admin,$cashier,$p] = c155Panel();
    $first = null;
    for ($i = 0; $i < 6; $i++) {
        $sale = app(RecordCardSaleAction::class)->execute($cashier, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '40']], 'request_key' => (string) Str::uuid()]);
        $first ??= $sale;
    }
    $page = Livewire::test(QuickCardSale::class)->assertCanNotSeeTableRecords([$first])->call('gotoPage', 2)->assertCanSeeTableRecords([$first])->mountTableAction('detail', $first);
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('Cabina Viva de Bs 40', 'Bs 40');
});

it('C155 attaches a stock error to the actual UUID cart row rendered by Filament', function () {
    [$admin,$cashier,$p] = c155Panel();
    $page = Livewire::test(QuickCardSale::class);
    $key = array_key_first($page->get('data.lines'));
    $page->set('data.lines.'.$key.'.product_id', $p->id)->set('data.lines.'.$key.'.quantity', 21)->call('registerSale')->assertHasFormErrors(['lines.'.$key.'.quantity'])->assertSee('Stock insuficiente');
    expect(CardSale::count())->toBe(0)->and(CardStock::sole()->quantity)->toBe(20);
});

it('C156 selects cuts with quantity buttons beside a sticky cart and registers through the same sale action', function () {
    [$admin,$cashier,$p] = c155Panel();
    $page = Livewire::test(QuickCardSale::class)->assertSeeHtml('pg-quick-sale-layout')->assertSeeHtml('pg-quick-sale-cart')->assertSee('Cabina Viva de Bs 40')
        ->call('adjustQuantity', $p->id, 1)->call('adjustQuantity', $p->id, 1)->assertSee('Total a cobrar: Bs 80')->call('registerSale')->assertHasNoFormErrors();
    expect(CardStock::sole()->quantity)->toBe(18)->and(CardStore::sole()->cash_balance_cents)->toBe(9000)->and(CardSale::count())->toBe(1);
});
it('C156 keeps typed trailing zeros and clearing the cart never writes a sale', function () {
    [$admin,$cashier,$p] = c155Panel();
    $key = 'product_'.$p->id;
    $page = Livewire::test(QuickCardSale::class)->set('quantities.'.$key, '5000')->assertSet('quantities.'.$key, '5000')->assertSee('Total a cobrar: Bs 200000')->call('registerSale')->assertHasFormErrors()->assertSee('Stock insuficiente');
    $page->call('clearCart')->assertSet('quantities.'.$key, '');
    expect(CardSale::count())->toBe(0)->and(CardStock::sole()->quantity)->toBe(20)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
});
it('C156 changing to wholesale removes ineligible cuts and does not persist an operation', function () {
    [$admin,$cashier,$p] = c155Panel();
    $page = Livewire::test(QuickCardSale::class)->call('adjustQuantity', $p->id, 1)->call('chooseMode', 'WHOLESALE')->assertSet('quantities.product_'.$p->id, '')->assertDontSee('Cabina Viva de Bs 40')->assertSee('No hay cortes disponibles para venta por mayor');
    expect(CardSale::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
});

it('C156 retains a custom price when changing quantities through product cards', function () {
    [$admin,$cashier,$p] = c155Panel();
    $key = 'product_'.$p->id;
    Livewire::test(QuickCardSale::class)->call('adjustQuantity', $p->id, 1)->set('data.lines.'.$key.'.unit_price', '39.50')->call('adjustQuantity', $p->id, 1)
        ->assertSet('data.lines.'.$key.'.unit_price', '39.50')->assertSee('Total a cobrar: Bs 79')->call('registerSale')->assertHasNoFormErrors();
    expect(CardSale::sole()->total_cents)->toBe(7900)->and(CardStore::sole()->cash_balance_cents)->toBe(8900);
});
it('C156 prevents adding above stock with the plus button and does not create a sale', function () {
    [$admin,$cashier,$p] = c155Panel();
    Livewire::test(QuickCardSale::class)->set('quantities.product_'.$p->id, '20')->call('adjustQuantity', $p->id, 1)->assertHasErrors(['quantities.product_'.$p->id])->assertSee('Stock insuficiente');
    expect(CardSale::count())->toBe(0)->and(CardStock::sole()->quantity)->toBe(20);
});
