<?php

use App\Domain\Tarjetas\Actions\RecordCardCashFundingAction;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Filament\Pages\Tarjetas\CardCashDeposits;
use App\Filament\Pages\Tarjetas\CardDepositAccounts;
use App\Filament\Pages\Tarjetas\CardStoreCountHistory;
use App\Filament\Pages\Tarjetas\CardStoreReserves;
use App\Filament\Pages\Tarjetas\EntelDigitalBalances;
use App\Filament\Pages\Tarjetas\InventoryMovements;
use App\Filament\Pages\Tarjetas\InventoryPrices;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Filament\Pages\Tarjetas\StoreInventory;
use App\Models\User;
use Database\Seeders\Tarjetas\CardCashFundingPermissionsSeeder;
use Database\Seeders\Tarjetas\CardDepositsPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\EntelDigitalPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function c200User(string $role): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardDepositsPermissionsSeeder::class, CardCashFundingPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, EntelDigitalPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

it('C202 keeps sale inventory and count histories in the menu with cashier inventory read only', function () {
    c200User('cajera');
    expect(StoreInventory::shouldRegisterNavigation())->toBeTrue()->and(QuickCardSale::shouldRegisterNavigation())->toBeTrue();
    expect(CardStoreCountHistory::shouldRegisterNavigation())->toBeTrue()->and(CardStoreCountHistory::getNavigationLabel())->toBe('Historiales');
    foreach ([InventoryPrices::class, InventoryMovements::class, CardCashDeposits::class, CardDepositAccounts::class, CardStoreReserves::class, EntelDigitalBalances::class] as $page) {
        expect($page::shouldRegisterNavigation())->toBeFalse();
    }
    Livewire::test(StoreInventory::class)->assertActionVisible('movements')->assertActionDoesNotExist('countHistory')->assertActionVisible('depositsHistory')->assertActionHidden('prices')->assertActionHidden('digitalBalances')->assertActionHidden('depositAccounts')->assertActionHidden('reserves')->assertActionHidden('purchase')->assertActionHidden('receiveEntel')->assertActionHidden('receiveCash')->assertActionHidden('depositCash')->assertActionVisible('sale');
});

it('C200 edits prices in inventory without changing stock and denies cashier configuration even by direct access', function () {
    $admin = c200User('administrador');
    $product = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => 1000, 'purchase_price_cents' => 940, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
    Livewire::test(StoreInventory::class)->assertActionVisible('prices')->assertActionVisible('digitalBalances')->assertActionVisible('depositAccounts')->assertActionVisible('reserves')->call('showPriceSettings')->assertSee('Precios por empresa y corte')->callTableAction('editProduct', $product, data: ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.60', 'normal_price' => '10.50', 'wholesale_price' => '9.80', 'is_active' => true, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors()->call('showStock')->assertSee('STOCK DISPONIBLE');
    expect($product->fresh()->normal_price_cents)->toBe(1050)->and(CardStore::sole()->cash_balance_cents)->toBe(0)->and(CardStore::sole()->initialized_at)->toBeNull();
    c200User('cajera');
    Livewire::test(StoreInventory::class)->call('showPriceSettings')->assertForbidden();
    foreach ([InventoryPrices::class, CardDepositAccounts::class, CardStoreReserves::class, EntelDigitalBalances::class] as $page) {
        test()->get($page::getUrl())->assertForbidden();
    }
});

it('C200 rechecks administrative permission on an already mounted inventory', function () {
    $admin = c200User('administrador');
    $page = Livewire::test(StoreInventory::class);
    $admin->removeRole('administrador');
    $admin->assignRole('cajera');
    $page->call('showPriceSettings')->assertForbidden();
});

it('C202 lets the cashier record real received funds once in her own session and keeps settings forbidden', function () {
    $cashier = c200User('cajera');
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 10000, 'initialized_at' => now(), 'initialized_by' => $cashier->id, 'session_control_started_at' => now()]);
    $session = CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $cashier->id, 'active_slot' => 1, 'opened_at' => now()]);
    $data = ['amount' => '50', 'reference' => 'Efectivo recibido para compra', 'request_key' => (string) Str::uuid()];
    Livewire::test(QuickCardSale::class)->assertActionVisible('purchase')->assertActionVisible('receiveEntel')->assertActionVisible('depositCash')->assertActionVisible('receiveCash')->callAction('receiveCash', data: $data)->assertHasNoActionErrors();
    $action = app(RecordCardCashFundingAction::class);
    $action->execute($cashier, $data);
    expect($store->fresh()->cash_balance_cents)->toBe(15000);
    expect(CardInventoryMovement::sole()->user_id)->toBe($cashier->id)->and(CardInventoryMovement::sole()->card_store_session_id)->toBe($session->id);
    $other = c200User('cajera');
    expect(fn () => $action->execute($other, ['amount' => '50', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect($store->fresh()->cash_balance_cents)->toBe(15000);
});

it('C202 upgrades existing cashier permission only once without granting settings', function () {
    $user = c200User('cajera');
    $role = Role::findByName('cajera', 'web');
    $role->revokePermissionTo('tarjetas.efectivo.aportar');
    $migration = require database_path('migrations/tarjetas/2026_10_24_201440_allow_cashier_card_cash_funding.php');
    $migration->up();
    $migration->up();
    expect($user->fresh()->can('tarjetas.efectivo.aportar'))->toBeTrue()->and($user->fresh()->can('tarjetas.precios.editar'))->toBeFalse();
    expect(DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', Permission::findByName('tarjetas.efectivo.aportar', 'web')->id)->count())->toBe(1);
});
