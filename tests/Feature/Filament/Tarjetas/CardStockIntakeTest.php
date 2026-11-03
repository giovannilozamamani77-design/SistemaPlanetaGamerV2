<?php

use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Filament\Pages\Tarjetas\QuickCardSale;
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
function c161Panel(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('cajera');
    test()->actingAs($u);
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 100000, 'initialized_at' => now(), 'initialized_by' => $u->id]);
    $store->update(['session_control_started_at' => now()]);
    CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $u->id, 'active_slot' => 1, 'opened_at' => now()]);
    $ps = [];
    foreach ([['TIGO', 10], ['TIGO', 20], ['ENTEL', 10], ['ENTEL', 30], ['VIVA', 40]] as [$company,$cut]) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => $cut * 100, 'purchase_price_cents' => $cut * 94, 'normal_price_cents' => $cut * 100, 'wholesale_price_cents' => $cut * 95, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $u->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 0]);
        $ps[] = $p;
    }

    return [$u, ...$ps];
}
it('C161 opens shared company-first intake from quick sale and updates stock and cash immediately', function () {
    [$u,$a,$b] = c161Panel();
    $page = Livewire::test(QuickCardSale::class)->assertActionVisible('purchase')->assertActionVisible('receiveEntel')->mountAction('purchase')->set('mountedActions.0.data.company', 'TIGO');
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('Bs 10', 'Bs 20', 'Bs 1000', 'pg-purchase-grid');
    $lines = $page->get('mountedActions.0.data.lines');
    expect($lines)->toHaveCount(2);
    foreach ($lines as $key => $line) {
        $page->set('mountedActions.0.data.lines.'.$key.'.quantity', 2);
    }
    expect($page->instance()->getMountedAction()->getModalContentFooter()->render())->toContain('Bs 56.4', 'El efectivo alcanza');
    $page->callMountedAction()->assertHasNoActionErrors()->assertSee('Total a cobrar');
    expect(CardStore::sole()->cash_balance_cents)->toBe(94360);
    expect(CardInventoryMovement::count())->toBe(2)->and(CardStock::sum('quantity'))->toBe(4);
});
it('C161 displays a shortage and prevents registering any cut when aggregate cash is insufficient', function () {
    [$u,$a,$b] = c161Panel();
    CardStore::sole()->update(['cash_balance_cents' => 1000]);
    $page = Livewire::test(QuickCardSale::class)->mountAction('purchase')->set('mountedActions.0.data.company', 'TIGO');
    $lines = $page->get('mountedActions.0.data.lines');
    foreach ($lines as $key => $line) {
        $page->set('mountedActions.0.data.lines.'.$key.'.quantity', 1);
    }
    expect($page->instance()->getMountedAction()->getModalContentFooter()->render())->toContain('Efectivo insuficiente', 'Administración', 'is-insufficient');
    $page->callMountedAction()->assertHasActionErrors(['lines']);
    expect(CardInventoryMovement::count())->toBe(0)->and(CardStock::sum('quantity'))->toBe(0);
});
it('C161 receives all Entel cuts in a simple list without cash charge or known cost requirement', function () {
    [$u,$a,$b,$e,$f] = c161Panel();
    $page = Livewire::test(QuickCardSale::class)->mountAction('receiveEntel')->set('mountedActions.0.data.company', 'ENTEL');
    $lines = $page->get('mountedActions.0.data.lines');
    expect($lines)->toHaveCount(2);
    foreach ($lines as $key => $line) {
        $page->set('mountedActions.0.data.lines.'.$key.'.quantity', 5);
    }
    $page->callMountedAction()->assertHasNoActionErrors()->assertSee('Total a cobrar');
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000);
    expect(CardStock::sum('quantity'))->toBe(10)->and(CardInventoryMovement::whereNotNull('unit_cost_cents')->count())->toBe(0);
});
it('C161 switches purchase company without keeping cuts from the previous company and cancel never writes', function () {
    c161Panel();
    $p = Livewire::test(QuickCardSale::class)->mountAction('purchase')->set('mountedActions.0.data.company', 'TIGO')->set('mountedActions.0.data.company', 'VIVA');
    expect($p->get('mountedActions.0.data.lines'))->toHaveCount(1);
    expect($p->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('Cabina Viva de Bs 40');
    $p->unmountAction();
    expect(CardInventoryMovement::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(100000);
});
it('C161 hides shortcuts when purchase or reception permission is restricted', function () {
    [$u] = c161Panel();
    $u->restrictedPermissions()->attach(Permission::findByName('tarjetas.compras.registrar')->id);
    $u->restrictedPermissions()->attach(Permission::findByName('tarjetas.entregas.registrar')->id);
    Livewire::test(QuickCardSale::class)->assertActionHidden('purchase')->assertActionHidden('receiveEntel');
});

it('C161 purchases several Viva Cabina cuts with their actual applied prices', function () {
    [$u] = c161Panel();
    $v = CardProduct::where('company', 'VIVA')->sole();
    $p = CardProduct::create(['company' => 'VIVA', 'face_value_cents' => 10000, 'purchase_price_cents' => 9900, 'normal_price_cents' => 10000, 'wholesale_price_cents' => 10000, 'is_active' => true, 'revision' => 1, 'created_by' => $u->id]);
    CardStock::create(['card_store_id' => CardStore::sole()->id, 'card_product_id' => $p->id, 'quantity' => 0]);
    $page = Livewire::test(QuickCardSale::class)->callAction('purchase', data: ['company' => 'VIVA', 'lines' => [['product_id' => $v->id, 'quantity' => 1, 'unit_price' => '39.5'], ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '99']], 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    $page->set('mountedActions.0.data.price_decision', 'KEEP')->callMountedAction()->assertHasNoActionErrors();
    expect(CardStore::sole()->cash_balance_cents)->toBe(86150)->and(CardStock::sum('quantity'))->toBe(2)->and($v->fresh()->purchase_price_cents)->toBe(3760);
});

it('C162 starts with three company cards and opens a wide three-column purchase without cuts until selected', function () {
    c161Panel();
    $page = Livewire::test(QuickCardSale::class)->mountAction('purchase');
    $html = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
    expect($html)->toContain('pg-purchase-companies', 'Tigo', 'Viva', 'Entel', 'pg-purchase-cash')->not->toContain('pg-purchase-grid');
    $action = $page->instance()->getMountedAction();
    $width = $action->getModalWidth();
    expect($width instanceof BackedEnum ? $width->value : $width)->toBe('7xl')->and($action->hasFormWrapper())->toBeTrue();
    $footer = $action->getModalContentFooter()->render();
    expect($footer)->toContain('Cancelar', 'Registrar compra', 'disabled');
    $page->set('mountedActions.0.data.company', 'ENTEL');
    expect($page->get('mountedActions.0.data.lines'))->toHaveCount(2);
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('pg-purchase-grid', 'Bs 10', 'Bs 30');
});
it('C162 purchases Entel through the paid form and preserves zero digits with visible card errors', function () {
    c161Panel();
    $p = Livewire::test(QuickCardSale::class)->mountAction('purchase')->set('mountedActions.0.data.company', 'ENTEL');
    $lines = $p->get('mountedActions.0.data.lines');
    $key = array_key_first($lines);
    $p->set('mountedActions.0.data.lines.'.$key.'.quantity', '5000')->assertSet('mountedActions.0.data.lines.'.$key.'.quantity', '5000');
    expect($p->instance()->getMountedAction()->getModalContentFooter()->render())->toContain('is-insufficient', 'disabled', '5000 tarjetas');
    $p->set('mountedActions.0.data.lines.'.$key.'.quantity', 2)->set('mountedActions.0.data.lines.'.$key.'.unit_price', '1.001')->callMountedAction()->assertHasActionErrors(['lines.'.$key.'.unit_price']);
    expect($p->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('pg-sale-error');
    $p->set('mountedActions.0.data.lines.'.$key.'.unit_price', '9.17')->callMountedAction()->assertHasNoActionErrors();
    $p->set('mountedActions.0.data.price_decision', 'KEEP')->callMountedAction()->assertHasNoActionErrors();
    expect(CardStore::sole()->cash_balance_cents)->toBe(98166)->and(CardInventoryMovement::sole()->kind)->toBe('PURCHASE');
});

it('C163 hides the large company choices after selection and permits choosing another company without a purchase', function () {
    c161Panel();
    $page = Livewire::test(QuickCardSale::class)->mountAction('purchase');
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('pg-purchase-companies');
    $page->set('mountedActions.0.data.company', 'TIGO');
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->not->toContain('pg-purchase-companies');
    $page->callAction(TestAction::make('changePurchaseCompany')->schemaComponent('purchaseNavigation'));
    expect($page->get('mountedActions.0.data.company'))->toBeNull()->and(CardInventoryMovement::count())->toBe(0);
});
it('C163 asks before saving a changed price and remembers only the purchase reference on Yes', function () {
    c161Panel();
    $product = CardProduct::where('company', 'TIGO')->where('face_value_cents', 1000)->sole();
    $p = Livewire::test(QuickCardSale::class)->mountAction('purchase')->set('mountedActions.0.data.company', 'TIGO');
    $lines = $p->get('mountedActions.0.data.lines');
    $key = array_key_first($lines);
    $p->set('mountedActions.0.data.lines.'.$key.'.quantity', 2)->set('mountedActions.0.data.lines.'.$key.'.unit_price', '9.5')->callMountedAction()->assertSet('mountedActions.0.data.price_review', true);
    expect(CardInventoryMovement::count())->toBe(0)->and($product->fresh()->purchase_price_cents)->toBe(940);
    expect($p->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('Cambió el precio de compra', '9.4', '9.5');
    $p->set('mountedActions.0.data.price_decision', 'UPDATE')->callMountedAction()->assertHasNoActionErrors();
    expect($product->fresh()->purchase_price_cents)->toBe(950)->and($product->fresh()->normal_price_cents)->toBe(1000);
    $p->mountAction('purchase')->set('mountedActions.0.data.company', 'TIGO');
    expect(array_values($p->get('mountedActions.0.data.lines'))[0]['unit_price'])->toBe('9.50');
});

it('C163 requires an explicit choice and No keeps the old reference while recording actual cost', function () {
    c161Panel();
    $product = CardProduct::where('company', 'TIGO')->where('face_value_cents', 1000)->sole();
    $page = Livewire::test(QuickCardSale::class)->mountAction('purchase')->set('mountedActions.0.data.company', 'TIGO');
    $key = array_key_first($page->get('mountedActions.0.data.lines'));
    $page->set('mountedActions.0.data.lines.'.$key.'.quantity', 1)->set('mountedActions.0.data.lines.'.$key.'.unit_price', '9.5')->callMountedAction()->callMountedAction()->assertHasActionErrors(['price_decision']);
    expect(CardInventoryMovement::count())->toBe(0);
    $page->set('mountedActions.0.data.price_decision', 'KEEP')->callMountedAction()->assertHasNoActionErrors();
    expect(CardInventoryMovement::sole()->unit_cost_cents)->toBe(950)->and($product->fresh()->purchase_price_cents)->toBe(940);
});
it('C163 cancels price review without purchasing or updating a reference and shows stale errors visibly', function () {
    c161Panel();
    $product = CardProduct::where('company', 'TIGO')->where('face_value_cents', 1000)->sole();
    $page = Livewire::test(QuickCardSale::class)->mountAction('purchase')->set('mountedActions.0.data.company', 'TIGO');
    $key = array_key_first($page->get('mountedActions.0.data.lines'));
    $page->set('mountedActions.0.data.lines.'.$key.'.quantity', 1)->set('mountedActions.0.data.lines.'.$key.'.unit_price', '9.5')->callMountedAction();
    $product->update(['purchase_price_cents' => 960, 'revision' => 2]);
    $page->set('mountedActions.0.data.price_decision', 'UPDATE')->callMountedAction()->assertHasActionErrors(['price_decision'])->unmountAction();
    expect(CardInventoryMovement::count())->toBe(0)->and($product->fresh()->purchase_price_cents)->toBe(960)->and(CardStore::sole()->cash_balance_cents)->toBe(100000);
});

it('C164 identifies prepaid companies with local logos instead of contactless symbols', function () {
    c161Panel();
    $page = Livewire::test(QuickCardSale::class)->mountAction('purchase');
    $html = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
    expect($html)->toContain('pg-prepaid-company', 'Tarjetas de recarga', 'images/cajas/components/tigo.png', 'images/cajas/components/viva.jpg', 'images/cajas/components/entel.jpg')->not->toContain('heroicon-o-signal');
    $page->set('mountedActions.0.data.company', 'VIVA');
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->not->toContain('pg-prepaid-company');
    expect(CardInventoryMovement::count())->toBe(0);
});

it('C166 receives Viva administration cards with branded cuts and no cash requirement or price input', function () {
    c161Panel();
    CardStore::sole()->update(['cash_balance_cents' => 0]);
    $page = Livewire::test(QuickCardSale::class)->mountAction('receiveEntel');
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('pg-prepaid-company', 'Tigo', 'Viva', 'Entel')->not->toContain('pg-purchase-cash');
    $page->set('mountedActions.0.data.company', 'VIVA');
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('data-company="VIVA"', 'Cabina Viva de Bs 40', 'Cantidad')->not->toContain('Precio por unidad', 'pg-prepaid-company');
    $page->set('mountedActions.0.data.lines.0.quantity', 12)->callMountedAction()->assertHasNoActionErrors();
    expect(CardStock::sum('quantity'))->toBe(12)->and(CardStore::sole()->cash_balance_cents)->toBe(0)->and(CardInventoryMovement::sole()->kind)->toBe('ADMIN_DELIVERY');
});

it('C166 changing reception company clears quantities and cancel never records a delivery', function () {
    c161Panel();
    $page = Livewire::test(QuickCardSale::class)->mountAction('receiveEntel')->set('mountedActions.0.data.company', 'TIGO')->set('mountedActions.0.data.lines.0.quantity', 50);
    $page->callAction(TestAction::make('changePurchaseCompany')->schemaComponent('purchaseNavigation'));
    expect($page->get('mountedActions.0.data.company'))->toBeNull()->and($page->get('mountedActions.0.data.lines'))->toBe([]);
    $page->set('mountedActions.0.data.company', 'VIVA');
    expect($page->get('mountedActions.0.data.lines.0.quantity'))->toBe(0);
    $page->unmountAction();
    expect(CardInventoryMovement::count())->toBe(0)->and(CardStock::sum('quantity'))->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(100000);
});
