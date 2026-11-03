<?php

use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Filament\Pages\Cajas\CashBoxCatalog;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Filament\Pages\Tarjetas\CardStoreClosing;
use App\Filament\Pages\Tarjetas\CardStoreOpening;
use App\Filament\Pages\Tarjetas\QuickCardSale;
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
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
it('C181 exposes the existing opening and closing forms from Cajas only to the permitted custodian', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $a = User::factory()->create();
    $a->assignRole('cajera');
    $b = User::factory()->create();
    $b->assignRole('cajera');
    $this->actingAs($a);
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 10000, 'initialized_at' => now(), 'initialized_by' => $a->id]);
    $p = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => 1000, 'purchase_price_cents' => 940, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'is_active' => true, 'revision' => 1, 'created_by' => $a->id]);
    CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 10]);
    foreach ([CashBoxCatalog::class, CashBoxOperations::class] as $page) {
        $screen = Livewire::test($page)->assertSee('Accesos directos')->assertSee('Tarjetas')->assertSee('Abrir Tarjetas')->assertSee(CardStoreOpening::getUrl(), false)->assertDontSee('Cerrar Tarjetas');
        expect(substr_count($screen->html(), 'aria-label="Acceso a caja de Tarjetas"'))->toBe(1);
    }
    expect(CardStoreCount::count())->toBe(0);
    Livewire::test(CardStoreOpening::class)->call('cancelOpening');
    expect(CardStoreCount::count())->toBe(0);
    Livewire::test(CardStoreOpening::class)->call('confirmOpening')->assertHasNoErrors();
    Livewire::test(CashBoxCatalog::class)->assertSee('Continuar ventas')->assertSee('Cerrar Tarjetas')->assertDontSee('Abrir Tarjetas');
    Livewire::withQueryParams(['action' => 'closeStore'])->test(QuickCardSale::class)->assertRedirect(CardStoreClosing::getUrl());
    Livewire::test(CardStoreClosing::class)->call('cancelClosing');
    expect(CardStoreCount::count())->toBe(1);
    $this->actingAs($b);
    Livewire::test(CashBoxCatalog::class)->assertSee('Ver Tarjetas')->assertDontSee('Cerrar Tarjetas')->assertDontSee('Abrir Tarjetas');
    // Role permission must be revoked on this test role, not a direct grant.
    Role::findByName('cajera')->revokePermissionTo('tarjetas.ventas.registrar');
    $this->actingAs($b->fresh());
    Livewire::test(CashBoxCatalog::class)->assertDontSee('Accesos directos');
    expect(CardStoreCount::count())->toBe(1);
});
