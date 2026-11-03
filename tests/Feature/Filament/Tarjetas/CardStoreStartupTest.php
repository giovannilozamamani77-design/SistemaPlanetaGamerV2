<?php

use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Filament\Pages\Cajas\CashBoxCatalog;
use App\Filament\Pages\Tarjetas\CardStoreClosing;
use App\Filament\Pages\Tarjetas\CardStoreOpening;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Filament\Pages\Tarjetas\StoreInventory;
use App\Models\User;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
it('C188 explains the initial count and lets administration reach setup before a cashier opens', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $store = CardStore::create(['code' => 'TIENDA']);
    foreach (['ENTEL', 'TIGO', 'VIVA'] as $company) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => 1000, 'purchase_price_cents' => 940, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 0]);
    }
    $this->actingAs($cashier);
    Livewire::test(CashBoxCatalog::class)->assertSee('Falta conteo inicial')->assertDontSee('Cargar inventario inicial')->assertDontSee('Abrir Tarjetas');
    Livewire::test(QuickCardSale::class)->assertActionHidden('openStore');
    $this->actingAs($admin);
    Livewire::test(CashBoxCatalog::class)->assertSee('Falta conteo inicial')->assertSee('Cargar inventario inicial')->assertSee(StoreInventory::getUrl(['action' => 'initializeStore']), false);
    expect(CardStore::sole()->initialized_at)->toBeNull();
    expect(CardStock::sum('quantity'))->toBe(0);
    $page = Livewire::withQueryParams(['action' => 'initializeStore'])->test(StoreInventory::class)->call('mountDefaultActions')->assertSet('mountedActions.0.name', 'initializeStore');
    $page->unmountAction();
    expect(CardStore::sole()->initialized_at)->toBeNull();
    $page->mountAction('initializeStore')->set('mountedActions.0.data.cash_amount', '0')->callMountedAction()->assertHasNoActionErrors()->assertActionHidden('initializeStore');
    expect(CardStore::sole()->initialized_at)->not->toBeNull();
    expect(CardStore::sole()->initialized_by)->toBe($admin->id);
    expect(CardStore::sole()->cash_balance_cents)->toBe(0);
    expect(CardStock::sum('quantity'))->toBe(0);
    $this->actingAs($cashier);
    Livewire::test(CashBoxCatalog::class)->assertSee('Cerrada')->assertSee('Abrir Tarjetas')->assertDontSee('Falta conteo inicial')->assertDontSee('Cargar inventario inicial');
    Livewire::test(CardStoreOpening::class)->call('confirmOpening')->assertHasNoErrors();
    Livewire::test(CardStoreClosing::class)->call('confirmClosing')->assertHasNoErrors();
    expect(CardStoreCount::count())->toBe(2);
    expect(CardStoreCount::sum('shortage_cents'))->toBe(0);
});
