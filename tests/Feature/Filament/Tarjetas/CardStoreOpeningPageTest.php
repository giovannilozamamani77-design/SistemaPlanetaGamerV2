<?php

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Filament\Pages\Tarjetas\CardStoreOpening;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Models\User;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
it('C190 opens on a full page with optional shared cash count and no writes before confirmation', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $a = User::factory()->create();
    $a->assignRole('cajera');
    $b = User::factory()->create();
    $b->assignRole('cajera');
    $this->actingAs($a);
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 10000, 'initialized_at' => now(), 'initialized_by' => $a->id]);
    $products = [];
    foreach (['ENTEL' => [1000, 5], 'TIGO' => [1000, 0], 'VIVA' => [4000, 0]] as $company => [$face,$quantity]) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => $face, 'purchase_price_cents' => $face, 'normal_price_cents' => $face, 'wholesale_price_cents' => $face, 'is_active' => true, 'revision' => 1, 'created_by' => $a->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => $quantity]);
        $products[$company] = $p;
    }

    $page = Livewire::test(CardStoreOpening::class)
        ->assertSee('Realizar arqueo')->assertDontSee('Sobrante efectivo')->assertDontSee('Faltan tarjetas');
    expect(CardStoreCount::count())->toBe(0);
    $page->call('openCashCount')->set('cashCountQuantities.b100', '3')->call('dismissCashCount')->assertSet('data.cash', '100');
    $page->call('openCashCount')->set('cashCountQuantities.b100', '1')->set('cashCountPacked.m010', '0.10')->call('applyCashCount')->assertSet('data.cash', '100.10');
    $page->call('openCashCount')->set('cashCountQuantities.b100', '5000')->call('dismissCashCount')->assertSet('data.cash', '100.10');
    $page->call('openCashCount')->set('cashCountQuantities.b100', '-1')->call('applyCashCount')->assertHasErrors(['cashCount']);
    $page->call('dismissCashCount');
    $page->set('data.cash', '99')->assertSet('acceptedCashCount', null);
    $page->call('cancelOpening')->assertRedirect(QuickCardSale::getUrl());
    expect(CardStoreCount::count())->toBe(0);
    Livewire::withQueryParams(['action' => 'openStore'])->test(QuickCardSale::class)->assertRedirect(CardStoreOpening::getUrl());
    $page = Livewire::test(CardStoreOpening::class);
    $page->call('confirmOpening')->assertHasNoErrors()->assertRedirect(QuickCardSale::getUrl());
    expect(CardStoreCount::count())->toBe(1);
    $page->call('confirmOpening')->assertHasNoErrors();
    expect(CardStoreCount::count())->toBe(1);
    expect(EconomicIncident::count())->toBe(0);
});

it('C190 rejects stale opening and denies an unauthorized user without writing counts', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $a = User::factory()->create();
    $a->assignRole('cajera');
    $b = User::factory()->create();
    $b->assignRole('cajera');
    $this->actingAs($a);
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 10000, 'initialized_at' => now(), 'initialized_by' => $a->id]);
    $products = [];
    foreach (['ENTEL' => [1000, 5], 'TIGO' => [1000, 0], 'VIVA' => [4000, 0]] as $company => [$face,$quantity]) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => $face, 'purchase_price_cents' => $face, 'normal_price_cents' => $face, 'wholesale_price_cents' => $face, 'is_active' => true, 'revision' => 1, 'created_by' => $a->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => $quantity]);
        $products[$company] = $p;
    }

    $page = Livewire::test(CardStoreOpening::class);
    $store->increment('revision');
    $page->call('confirmOpening')->assertHasErrors(['data.cash']);
    expect(CardStoreCount::count())->toBe(0);
    $this->actingAs(User::factory()->create());
    Livewire::test(CardStoreOpening::class)->assertForbidden();
    expect(CardStoreCount::count())->toBe(0);
});
