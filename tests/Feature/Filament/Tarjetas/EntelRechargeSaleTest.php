<?php

use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordEntelRechargeAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelRecharge;
use App\Domain\Tarjetas\Queries\CardSaleRegisterQuery;
use App\Filament\Pages\Tarjetas\EntelDigitalBalances;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\EntelDigitalPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function c157Panel(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, EntelDigitalPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('cajera');
    test()->actingAs($u);
    CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 0, 'initialized_at' => now(), 'initialized_by' => $u->id]);
    foreach (['CREDIT' => 12345678, 'PACKAGES' => 87654321] as $kind => $amount) {
        EntelDigitalAccount::create(['kind' => $kind, 'balance_cents' => $amount, 'initialized_at' => now(), 'initialized_by' => $u->id]);
    }

    CardStore::sole()->update(['session_control_started_at' => now()]);
    CardStoreSession::create(['card_store_id' => CardStore::sole()->id, 'user_id' => $u->id, 'active_slot' => 1, 'opened_at' => now()]);

    return $u;
}
it('C159 uses one recharge form with separate service selection and no exposed balances', function () {
    c157Panel();
    $p = Livewire::test(QuickCardSale::class)->call('chooseMode', 'WHOLESALE')->set('recharge.kind', 'CREDIT')->set('recharge.amount', '100')->set('recharge.external_confirmed', true);
    $p->assertSee('Saldo disponible')->assertSee('Cliente recibe Bs 108');
    foreach (['12345678', '123456.78', '123.456,78', '87654321', '876543.21'] as $secret) {
        expect($p->html().json_encode($p->snapshot))->not->toContain($secret);
    }
    $p->call('registerRecharge')->assertHasNoErrors()->assertSee('Bs 100');
    expect(EntelRecharge::sole()->kind)->toBe('CREDIT');
    $this->get(EntelDigitalBalances::getUrl())->assertForbidden();
    $h = Livewire::test(QuickCardSale::class)->assertSee('Recarga · Crédito');
    expect($h->html())->not->toContain('balance_cents', '12335678', '123356.78');
});
it('C159 clearing never registers and external confirmation and minimum remain required', function () {
    c157Panel();
    $page = Livewire::test(QuickCardSale::class)->call('chooseMode', 'WHOLESALE')->set('recharge.kind', 'PACKAGES')->set('recharge.amount', '100')->set('recharge.external_confirmed', false)->call('registerRecharge')->assertHasErrors(['recharge.external_confirmed']);
    $page->call('clearRecharge')->assertSet('recharge.amount', null);
    $page->set('recharge.kind', 'PACKAGES')->set('recharge.amount', '99')->set('recharge.external_confirmed', true)->call('registerRecharge')->assertHasErrors(['recharge.amount']);
    expect(EntelRecharge::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(0)->and(EntelDigitalAccount::where('kind', 'PACKAGES')->sole()->balance_cents)->toBe(87654321);
});
it('C157 renders consumed digital history correctly for administration and configures future customer bonuses', function () {
    $cashier = c157Panel();
    app(RecordEntelRechargeAction::class)->execute($cashier, ['kind' => 'PACKAGES', 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $this->actingAs($admin);
    Livewire::test(EntelDigitalBalances::class)->assertSee('Recarga vendida')->assertSee('Consumido por recargas')->callAction('rechargeRates', data: ['credit' => '8', 'packages' => '15', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
});

it('C159 shows a contradictory retry in the visible amount without another recharge', function () {
    $u = c157Panel();
    $key = (string) Str::uuid();
    app(RecordEntelRechargeAction::class)->execute($u, ['kind' => 'CREDIT', 'amount' => '100', 'external_confirmed' => true, 'request_key' => $key]);
    Livewire::test(QuickCardSale::class)->call('chooseMode', 'WHOLESALE')->set('recharge.kind', 'PACKAGES')->set('recharge.amount', '100')->set('recharge.external_confirmed', true)->set('recharge.request_key', $key)->call('registerRecharge')->assertHasErrors(['recharge.amount']);
    expect(EntelRecharge::count())->toBe(1)->and(CardStore::sole()->cash_balance_cents)->toBe(10000);
});

it('C159 renders one recharge register only in wholesale mode', function () {
    c157Panel();
    $page = Livewire::test(QuickCardSale::class)->assertDontSee('Recargas mayoristas Entel')->call('chooseMode', 'WHOLESALE')->assertSee('Recargas mayoristas Entel')->assertSee('Paquetes mayoristas')->assertDontSee('Registrar recarga')->call('chooseRechargeKind', 'CREDIT')->assertSee('Registrar recarga');
    expect(substr_count($page->html(), 'wire:submit="registerRecharge"'))->toBe(1);
    $page->call('chooseMode', 'NORMAL')->assertDontSee('Registrar recarga');
});

it('C159 uses the same register for Packages and replays without another debit or cash receipt', function () {
    c157Panel();
    $data = ['kind' => 'PACKAGES', 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()];
    $p = Livewire::test(QuickCardSale::class)->call('chooseMode', 'WHOLESALE')->set('recharge', $data)->assertSee('Cliente recibe Bs 115')->call('registerRecharge')->assertHasNoErrors();
    $p->set('recharge', $data)->call('registerRecharge')->assertHasNoErrors();
    expect(EntelRecharge::count())->toBe(1)->and(EntelRecharge::sole()->kind)->toBe('PACKAGES')->and(CardStore::sole()->cash_balance_cents)->toBe(10000)->and(EntelDigitalAccount::where('kind', 'CREDIT')->sole()->balance_cents)->toBe(12345678)->and(EntelDigitalAccount::where('kind', 'PACKAGES')->sole()->balance_cents)->toBe(87644321);
});
it('C159 cannot register through the page in unit mode', function () {
    c157Panel();
    Livewire::test(QuickCardSale::class)->set('recharge', ['kind' => 'CREDIT', 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()])->call('registerRecharge')->assertForbidden();
    expect(EntelRecharge::count())->toBe(0);
});

it('C160 presents compact recharge inputs and one combined register for cards and recharges', function () {
    $u = c157Panel();
    $product = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => 1000, 'purchase_price_cents' => 940, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $u->id]);
    CardStock::create(['card_store_id' => CardStore::sole()->id, 'card_product_id' => $product->id, 'quantity' => 10]);
    app(RecordCardSaleAction::class)->execute($u, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()]);
    $this->travel(1)->seconds();
    app(RecordEntelRechargeAction::class)->execute($u, ['kind' => 'PACKAGES', 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $p = Livewire::test(QuickCardSale::class)->call('chooseMode', 'WHOLESALE')->call('chooseRechargeKind', 'CREDIT')->assertSeeHtml('pg-recharge-row')->assertSee('Historial de ventas · turno actual')->assertSee('Recarga · Paquetes')->assertSee('Tarjetas · Normal');
    // One summary table and one paginated register; no separate digital register.
    expect(substr_count($p->html(), '<table'))->toBe(2);
    $p->assertSee('Resumen del turno abierto');
});

it('C160 paginates a mixed register and keeps digital detail private and read only', function () {
    $u = c157Panel();
    $first = null;
    for ($i = 0; $i < 6; $i++) {
        $r = app(RecordEntelRechargeAction::class)->execute($u, ['kind' => $i % 2 ? 'PACKAGES' : 'CREDIT', 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);
        $first ??= $r;
        $this->travel(1)->seconds();
    }
    $entries = app(CardSaleRegisterQuery::class)->entries($u)->get();
    expect($entries)->toHaveCount(6)->and($entries->pluck('id')->unique())->toHaveCount(6);
    $page = Livewire::test(QuickCardSale::class)->assertCanNotSeeTableRecords([$entries->firstWhere('source_id', $first->id)])->call('gotoPage', 2)->assertCanSeeTableRecords([$entries->firstWhere('source_id', $first->id)])->mountTableAction('detail', (string) -$first->id);
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('Bs 108');
    $html = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
    expect($html.json_encode($page->snapshot))->not->toContain('balance_cents', 'balance_before', 'balance_after', '12335678');
    expect(fn () => $entries->first()->delete())->toThrow(LogicException::class);
    $u->forceFill(['is_active' => false])->save();
    expect(fn () => app(CardSaleRegisterQuery::class)->entries($u))->toThrow(AuthorizationException::class);
});

it('C170 chooses a small service card and returns to choices after successful registration', function () {
    c157Panel();
    $page = Livewire::test(QuickCardSale::class)->call('chooseMode', 'WHOLESALE')->assertSeeHtml('pg-recharge-choices')->assertDontSee('Registrar recarga');
    $page->call('chooseRechargeKind', 'PACKAGES')->set('recharge.amount', '100')->set('recharge.external_confirmed', true)->call('registerRecharge')->assertHasNoErrors()->assertSet('recharge.kind', null)->assertSet('recharge.amount', null)->assertSeeHtml('pg-recharge-choices')->assertDontSee('Registrar recarga');
    expect(EntelRecharge::sole()->kind)->toBe('PACKAGES')->and(CardStore::sole()->cash_balance_cents)->toBe(10000);
    $page->call('chooseRechargeKind', 'CREDIT')->set('recharge.amount', '200')->call('clearRecharge')->assertSet('recharge.kind', null)->assertSet('recharge.amount', null);
    expect(EntelRecharge::count())->toBe(1);
});

it('C171 displays historical physical cuts and keeps digital rows separate for matching receipt ids', function () {
    $u = c157Panel();
    $product = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => 2000, 'purchase_price_cents' => 1880, 'normal_price_cents' => 2000, 'wholesale_price_cents' => 1900, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $u->id]);
    CardStock::create(['card_store_id' => CardStore::sole()->id, 'card_product_id' => $product->id, 'quantity' => 10]);
    app(RecordCardSaleAction::class)->execute($u, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => '20']], 'request_key' => (string) Str::uuid()]);
    foreach (['CREDIT', 'PACKAGES'] as $kind) {
        app(RecordEntelRechargeAction::class)->execute($u, ['kind' => $kind, 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    }
    $product->update(['face_value_cents' => 5000]);
    $entries = app(CardSaleRegisterQuery::class)->entries($u)->get();
    expect($entries->where('entry_type', 'CARD')->sole()->lines)->toHaveCount(1);
    foreach ($entries->where('entry_type', 'RECHARGE') as $entry) {
        expect($entry->lines)->toHaveCount(0);
    }
    $page = Livewire::test(QuickCardSale::class)->assertSeeHtml('pg-sale-register-products')->assertSee('Detalle de la venta')->assertSee('Tigo · Bs 20')->assertSee('× 3')->assertSee('1 recarga')->assertSee('Monto libre')->assertSee('Recarga · Crédito')->assertSee('Recarga · Paquetes');
    expect($page->html().json_encode($page->snapshot))->not->toContain('123456.78', '876543.21', 'balance_cents');
});

it('C172 filters compact history by Bolivia date historical company and sale type without duplicate mixed receipts', function () {
    $u = c157Panel();
    $products = [];
    foreach (['TIGO', 'VIVA'] as $company) {
        $product = CardProduct::create(['company' => $company, 'face_value_cents' => 1000, 'purchase_price_cents' => 940, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $u->id]);
        CardStock::create(['card_store_id' => CardStore::sole()->id, 'card_product_id' => $product->id, 'quantity' => 50]);
        $products[] = $product;
    }
    foreach (['2026-10-23 03:59:59', '2026-10-23 04:00:00', '2026-10-24 03:59:59', '2026-10-24 04:00:00'] as $time) {
        $this->travelTo(CarbonImmutable::parse($time, 'UTC'));
        app(RecordCardSaleAction::class)->execute($u, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => array_map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '10'], $products), 'request_key' => (string) Str::uuid()]);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-23 15:00:00', 'UTC'));
    foreach (['CREDIT', 'PACKAGES'] as $kind) {
        app(RecordEntelRechargeAction::class)->execute($u, ['kind' => $kind, 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    }
    $query = app(CardSaleRegisterQuery::class);
    $day = ['from' => '2026-10-23', 'until' => '2026-10-23'];
    $entries = $query->filterHistory($query->entries($u), $day)->get();
    expect($entries)->toHaveCount(4);
    expect($query->filterHistory($query->entries($u), $day + ['company' => 'TIGO'])->count())->toBe(2);
    expect($query->filterHistory($query->entries($u), $day + ['company' => 'ENTEL', 'type' => 'PACKAGES'])->count())->toBe(1);
    expect($query->filterHistory($query->entries($u), ['company' => 'TIGO', 'type' => 'CREDIT'])->count())->toBe(0);
    expect($query->filterHistory($query->entries($u), ['from' => 'invalid'])->count())->toBe(0);
    $packages = $entries->firstWhere('mode', 'PACKAGES');
    $page = Livewire::test(QuickCardSale::class)->assertSee('Filtrar historial')->filterTable('history', $day + ['company' => 'ENTEL', 'type' => 'PACKAGES'])->assertCanSeeTableRecords([$packages])->assertCanNotSeeTableRecords($entries->reject(fn ($entry) => $entry->id === $packages->id));
    $page->resetTableFilters();
    expect($query->entries($u)->count())->toBe(6);
});
