<?php

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Filament\Pages\Tarjetas\CardStoreClosing;
use App\Filament\Pages\Tarjetas\CardStoreCountHistory;
use App\Filament\Pages\Tarjetas\CardStoreOpening;
use App\Filament\Pages\Tarjetas\CardStoreReserves;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Models\User;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
it('C178 opens and closes without PIN or hours and cancellation never persists a count', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('cajera');
    test()->actingAs($u);
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 10000, 'initialized_at' => now(), 'initialized_by' => $u->id]);
    $p = CardProduct::create(['company' => 'VIVA', 'face_value_cents' => 4000, 'purchase_price_cents' => 3950, 'normal_price_cents' => 4000, 'wholesale_price_cents' => 4000, 'is_active' => true, 'revision' => 1, 'created_by' => $u->id]);
    CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 10]);
    $page = Livewire::test(CardStoreOpening::class);
    $html = $page->instance()->getSchema('form')->toHtml();
    expect($html)->toContain('pg-store-count-grid', 'Cabina Viva de Bs 40')->not->toContain('type="password"');
    $page->call('cancelOpening');
    expect(CardStoreCount::count())->toBe(0);
    Livewire::test(CardStoreOpening::class)->call('confirmOpening')->assertHasNoErrors();
    $page = Livewire::test(QuickCardSale::class)->assertActionHidden('openStore')->assertActionVisible('closeStore');
    $page = Livewire::test(CardStoreClosing::class)->set('data.cash', '99');
    $closeHtml = $page->instance()->getSchema('form')->toHtml();
    expect($closeHtml)->toContain('Efectivo esperado', 'Efectivo contado', 'Esperado', 'Contado', 'Cabina Viva de Bs 40')->not->toContain('type="password"', 'Ver detalle por corte y reservas');
    $page->call('cancelClosing');
    expect(CardStoreCount::count())->toBe(1);
    expect(CardStoreSession::where('active_slot', 1)->count())->toBe(1);
    expect(CardStore::sole()->cash_balance_cents)->toBe(10000);
    Livewire::test(CardStoreClosing::class)->assertSet('data.cash', '100')->set('data.cash', '99')->call('confirmClosing')->assertHasNoErrors();
    Livewire::test(QuickCardSale::class)->assertActionHidden('openStore')->assertSee('Abrir Tarjetas')->assertSee(CardStoreOpening::getUrl(), false);
    Livewire::test(CardStoreCountHistory::class)->assertCanSeeTableRecords(CardStoreCount::where('kind', 'CLOSE')->get())->assertCanNotSeeTableRecords(CardStoreCount::where('kind', 'OPEN')->get())->call('selectHistory', 'OPEN')->assertCanSeeTableRecords(CardStoreCount::where('kind', 'OPEN')->get());
    expect(CardStoreReserves::canAccess())->toBeFalse();
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    test()->actingAs($admin);
    $count = CardStoreCount::latest('id')->first();
    $reserve = CardStoreReserve::create(['card_store_id' => $store->id, 'card_store_count_id' => $count->id, 'original_amount' => 200, 'held_amount' => 200, 'created_at' => now()]);
    Livewire::test(CardStoreReserves::class)->assertCanSeeTableRecords([$reserve])->callTableAction('resolve', $reserve, data: ['method' => 'INCORPORAR', 'amount' => '2', 'reason' => 'Verificado', 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors();
    expect($reserve->fresh()->held_amount)->toBe(0);
    expect(CardStoreCount::count())->toBe(2)->and(EconomicIncident::sole()->pending_cents)->toBe(100)->and(EconomicIncident::sole()->user_id)->toBe($u->id);
});

it('C183 groups all companies without changing the counted cut identities or losing trailing zeros', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 100000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
    $ids = [];
    foreach (['ENTEL' => [10, 15, 30, 50, 100], 'TIGO' => [10, 20, 30, 50, 100], 'VIVA' => [10, 20, 30, 40, 50, 100]] as $company => $cuts) {
        foreach ($cuts as $face) {
            $p = CardProduct::create(['company' => $company, 'face_value_cents' => $face * 100, 'purchase_price_cents' => $face * 90, 'normal_price_cents' => $face * 100, 'wholesale_price_cents' => $face * 95, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
            CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 3]);
            $ids[$company][$face] = $p->id;
        }
    }
    $page = Livewire::test(CardStoreOpening::class);
    $html = $page->instance()->getSchema('form')->toHtml();
    expect($html)->toContain('Conteo Entel', 'Conteo Tigo', 'Conteo Viva', 'Esperado', 'Contado', 'Cabina Viva de Bs 40', 'Cabina Viva de Bs 100');
    expect(substr_count($html, 'aria-label="Cantidad contada '))->toBe(16);
    $state = $page->get('data.stocks');
    $entel = array_search($ids['ENTEL'][15], array_column($state, 'product_id'));
    $cabina = array_search($ids['VIVA'][100], array_column($state, 'product_id'));
    $page->set('data.stocks.'.$entel.'.quantity', '2')->set('data.stocks.'.$cabina.'.quantity', '5000')->set('data.reason', 'Sobrante contado en recepción');
    expect($page->get('data.stocks.'.$cabina.'.quantity'))->toBe('5000');
    expect(CardStoreCount::count())->toBe(0);
    $page->call('confirmOpening')->assertHasNoErrors();
    $count = CardStoreCount::sole();
    $lines = collect($count->lines)->keyBy('product_id');
    expect($lines[$ids['ENTEL'][15]]['counted'])->toBe(2);
    expect($lines[$ids['VIVA'][100]]['counted'])->toBe(5000);
    expect($count->cash_counted_cents)->toBe(100000);
    expect($count->shortage_cents)->toBe(1500);
    expect(EconomicIncident::sole()->user_id)->toBe($admin->id);
    expect(EconomicIncident::sole()->pending_cents)->toBe(1500);
    expect(CardStoreReserve::sole()->held_amount)->toBe(4997);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000);
});
