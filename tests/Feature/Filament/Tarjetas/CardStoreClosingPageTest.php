<?php

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Filament\Pages\Tarjetas\CardStoreClosing;
use App\Filament\Pages\Tarjetas\CardStoreOpening;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Models\User;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
it('C192 closes on a full page with optional shared cash count without duplicate debts at handover', function () {
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

    Livewire::test(CardStoreOpening::class)->call('confirmOpening')->assertHasNoErrors();
    $page = Livewire::test(CardStoreClosing::class)->assertSee('Confirmar cierre')->assertSee('Realizar arqueo');
    expect($page->instance()->getMaxContentWidth())->toBe(Width::Full);
    $dom = new DOMDocument;
    $dom->loadHTML($page->instance()->getSchema('form')->toHtml(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    expect($xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' fi-input-wrp ')][.//input[@id='form.cash']]//button[contains(.,'Realizar arqueo')]")->length)->toBe(1);
    $page->callFormComponentAction('cash', 'closingCashCount')->assertSet('cashCountOpen', true)->set('cashCountQuantities.b100', '3')->call('dismissCashCount')->assertSet('data.cash', '100');
    $page->call('openCashCount')->set('cashCountQuantities.b100', '1')->call('applyCashCount')->assertSet('data.cash', '100');
    $page->set('data.cash', '99')->assertSet('acceptedCashCount', null)->call('cancelClosing')->assertRedirect(QuickCardSale::getUrl());
    expect(CardStoreCount::count())->toBe(1);
    Livewire::test(QuickCardSale::class)->assertSee('Selecciona las tarjetas vendidas');
    $page = Livewire::test(CardStoreClosing::class)->assertSet('data.cash', '100')->set('data.cash', '99')->set('data.stocks.0.quantity', '4');
    $page->call('confirmClosing')->assertHasNoErrors()->assertRedirect(QuickCardSale::getUrl());
    $page->call('confirmClosing')->assertHasNoErrors();
    expect(CardStoreCount::count())->toBe(2)->and(EconomicIncident::sum('pending_cents'))->toBe(1100)->and(EconomicIncident::where('user_id', '!=', $a->id)->count())->toBe(0);
    Livewire::test(QuickCardSale::class)->assertDontSee('Selecciona las tarjetas vendidas');
    $this->actingAs($b);
    Livewire::test(CardStoreOpening::class)->assertSet('data.cash', '99')->assertSet('data.stocks.0.quantity', 4)->call('confirmOpening')->assertHasNoErrors();
    expect(EconomicIncident::sum('pending_cents'))->toBe(1100);
});

it('C192 rejects another cashier and stale closing without modifying the active session', function () {
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

    Livewire::test(CardStoreOpening::class)->call('confirmOpening')->assertHasNoErrors();
    $this->actingAs($b);
    Livewire::test(CardStoreClosing::class)->assertRedirect(QuickCardSale::getUrl());
    $this->actingAs($a);
    $page = Livewire::test(CardStoreClosing::class);
    $store->increment('revision');
    $page->call('confirmClosing')->assertHasErrors(['data.cash']);
    expect(CardStoreCount::count())->toBe(1)->and(EconomicIncident::count())->toBe(0);
});
