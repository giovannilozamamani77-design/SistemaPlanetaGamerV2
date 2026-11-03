<?php

use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
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
it('C195 retains every cut in a full-width compact sale with grouped movements and safe Enter', function () {
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
    foreach (['ENTEL' => [10, 15, 30, 50, 100], 'TIGO' => [10, 20, 30, 50, 100], 'VIVA' => [10, 20, 30, 40, 50, 100]] as $company => $cuts) {
        foreach ($cuts as $face) {
            $p = CardProduct::create(['company' => $company, 'face_value_cents' => $face * 100, 'purchase_price_cents' => $face * 90, 'normal_price_cents' => $face * 100, 'wholesale_price_cents' => $face * 95, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $a->id]);
            CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 10]);
            $products[] = $p;
        }
    }
    Livewire::test(CardStoreOpening::class)->call('confirmOpening')->assertHasNoErrors();

    $page = Livewire::test(QuickCardSale::class)->assertSee('Movimientos')->assertSee('Cerrar Tarjetas')->assertDontSee('Abrir Tarjetas');
    expect($page->instance()->getMaxContentWidth())->toBe(Width::Full);
    $dom = new DOMDocument;
    $dom->loadHTML($page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xp = new DOMXPath($dom);
    expect($xp->query('//input[@data-sale-quantity]')->length)->toBe(16);
    expect($xp->query('//div[contains(@class,"pg-sale-select-actions")]//button[@aria-label="Historial de ventas"]')->length)->toBe(1);
    expect($xp->query('//*[@data-sale-history-dialog]')->length)->toBe(1);
    expect($xp->query('//*[@data-sale-history-dialog]//*[@*[name()="wire:partial" and .="action-modals"]]')->length)->toBe(0);
    expect($xp->query('//details[@aria-label="Historial de ventas"]')->length)->toBe(0);
    foreach ($products as $p) {
        $page->assertSet('quantities.product_'.$p->id, '');
    }
    $key = $page->get('data.request_key');
    $p = $products[0];
    $page->call('registerQuickSale', $p->id, '3', $key)->assertHasNoErrors()->assertSet('quantities.product_'.$p->id, '');
    expect(CardSale::count())->toBe(1)->and(CardStore::sole()->cash_balance_cents)->toBe(13000)->and($p->stock()->first()->quantity)->toBe(7);
    $page->call('registerQuickSale', $p->id, '3', $key)->assertHasNoErrors();
    expect(CardSale::count())->toBe(1);
    $page->call('registerQuickSale', $p->id, '5000', $page->get('data.request_key'))->assertHasErrors();
    expect($page->get('quantities.product_'.$p->id))->toBe('5000');
    $page->call('clearCart')->call('chooseMode', 'WHOLESALE');
    $dom->loadHTML($page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    expect((new DOMXPath($dom))->query('//input[@data-sale-quantity]')->length)->toBe(16);
    $key = $page->get('data.request_key');
    $page->call('registerQuickSale', $p->id, '4', $key)->assertHasErrors();
    $page->call('registerQuickSale', $p->id, '5', $key)->assertHasNoErrors();
    expect(CardSale::count())->toBe(2)->and($p->stock()->first()->quantity)->toBe(2);
    $this->actingAs($b);
    $other = Livewire::test(QuickCardSale::class);
    $other->call('registerQuickSale', $p->id, '1', $other->get('data.request_key'))->assertHasErrors(['data.lines']);
    expect(CardSale::count())->toBe(2);
    $this->actingAs($a);
    foreach ($products as $cut) {
        if ($cut->company === 'ENTEL' || ($cut->company === 'TIGO' && $cut->face_value_cents === 2000)) {
            $cut->stock()->first()->update(['quantity' => 0]);
        }
        if ($cut->company === 'VIVA' && $cut->face_value_cents === 10000) {
            $cut->update(['wholesale_enabled' => false]);
        }
    }
    $page = Livewire::test(QuickCardSale::class)->assertSee('Tarjetas · '.$a->name)->assertDontSee('Sesión de Tarjetas abierta');
    $dom->loadHTML($page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    expect((new DOMXPath($dom))->query('//input[@data-sale-quantity]')->length)->toBe(10);
    $page->call('chooseMode', 'WHOLESALE');
    $dom->loadHTML($page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    expect((new DOMXPath($dom))->query('//input[@data-sale-quantity]')->length)->toBe(9);
    expect(CardSale::count())->toBe(2);
});
