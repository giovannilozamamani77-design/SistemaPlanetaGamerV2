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
use Database\Seeders\Tarjetas\CardCashFundingPermissionsSeeder;
use Database\Seeders\Tarjetas\CardDepositsPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
it('C191 keeps sales hidden until opening and places cash count on the counted input', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardCashFundingPermissionsSeeder::class, CardDepositsPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
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

    $closed = Livewire::test(QuickCardSale::class)->assertActionHidden('purchase')->assertActionHidden('receiveEntel')->assertActionHidden('receiveCash')->assertActionHidden('depositCash')->assertSee('data-store-welcome', false)->assertSee('Cerrada')->assertSee('Abrir Tarjetas')->assertDontSee('Movimientos')->assertDontSee('Selecciona las tarjetas vendidas')->assertDontSee('Total a cobrar')->call('registerSale')->assertHasErrors(['data.lines'])->call('registerRecharge')->assertHasErrors(['recharge.amount']);
    $closedDom = new DOMDocument;
    $closedDom->loadHTML($closed->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    expect((new DOMXPath($closedDom))->query('//*[@data-open-store]')->length)->toBe(1);
    $closed->assertSee(CardStoreOpening::getUrl(), false);
    $page = Livewire::test(CardStoreOpening::class);
    $dom = new DOMDocument;
    $dom->loadHTML($page->instance()->getSchema('form')->toHtml(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    expect($xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' fi-input-wrp ')][.//input[@id='form.cash']]//button[contains(.,'Realizar arqueo')]")->length)->toBe(1);
    $page->call('confirmOpening')->assertHasNoErrors();
    Livewire::test(QuickCardSale::class)->assertActionVisible('purchase')->assertActionVisible('receiveEntel')->assertActionVisible('receiveCash')->assertActionVisible('depositCash')->assertSee('Movimientos')->assertSee('Selecciona las tarjetas vendidas')->assertSee('Total a cobrar')->assertDontSee('data-store-welcome', false);
    $this->actingAs($b);
    Livewire::test(QuickCardSale::class)->assertSee('Espera el cierre')->assertDontSee('Movimientos')->assertDontSee('Abrir Tarjetas')->assertDontSee('Selecciona las tarjetas vendidas')->assertDontSee('Total a cobrar')->call('registerSale')->assertHasErrors(['data.lines'])->call('registerRecharge')->assertHasErrors(['recharge.amount']);
    $this->actingAs($a);
    Livewire::test(CardStoreClosing::class)->call('confirmClosing')->assertHasNoErrors();
    Livewire::test(QuickCardSale::class)->assertDontSee('Selecciona las tarjetas vendidas');
    expect(CardStoreCount::count())->toBe(2)->and(EconomicIncident::count())->toBe(0);
});
