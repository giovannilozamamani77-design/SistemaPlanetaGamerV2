<?php

use App\Domain\Tarjetas\Actions\SaveCardWholesaleSelectionAction;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Filament\Pages\Tarjetas\StoreInventory;
use App\Models\User;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function c158Panel(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);
    $ps = [];
    foreach ([1000, 2000] as $cut) {
        $ps[] = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => $cut, 'purchase_price_cents' => $cut, 'normal_price_cents' => $cut, 'wholesale_price_cents' => $cut, 'is_active' => true, 'wholesale_enabled' => $cut === 1000, 'revision' => 1, 'created_by' => $u->id]);
    }

    return [$u, ...$ps];
}
it('C158 configures all wholesale cuts in the inventory settings modal and hides unselected cuts only in Mayor', function () {
    [$u,$a,$b] = c158Panel();
    Livewire::test(StoreInventory::class)->assertActionExists('wholesaleSelection')->callAction('wholesaleSelection', data: ['enabled' => ['ENTEL' => [], 'TIGO' => [$b->id], 'VIVA' => []], 'revisions' => [$a->id => 1, $b->id => 1], 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    expect($a->fresh()->wholesale_enabled)->toBeFalse()->and($b->fresh()->wholesale_enabled)->toBeTrue();
    $store = CardStore::updateOrCreate(['code' => 'TIENDA'], ['initialized_at' => now(), 'initialized_by' => $u->id, 'session_control_started_at' => now()]);
    CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $u->id, 'active_slot' => 1, 'opened_at' => now()]);
    foreach ([$a, $b] as $cut) {
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $cut->id, 'quantity' => 10]);
    }
    Livewire::test(QuickCardSale::class)->assertSeeHtml('sale-cut-'.$a->id)->call('chooseMode', 'WHOLESALE')->assertDontSeeHtml('sale-cut-'.$a->id)->assertSeeHtml('sale-cut-'.$b->id)->call('chooseMode', 'NORMAL')->assertSeeHtml('sale-cut-'.$a->id);
});
it('C158 cancels without changes and rejects stale catalog in the visible selection', function () {
    [$u,$a,$b] = c158Panel();
    $p = Livewire::test(StoreInventory::class)->mountAction('wholesaleSelection')->unmountAction();
    expect(CardInventoryCommand::count())->toBe(0)->and($a->fresh()->revision)->toBe(1);
    $p->mountAction('wholesaleSelection');
    $b->update(['revision' => 2]);
    $p->callMountedAction()->assertHasActionErrors(['enabled.ENTEL']);
    expect(CardInventoryCommand::count())->toBe(0);
});
it('C158 hides inventory settings for a cashier', function () {
    [$u,$a,$b] = c158Panel();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    Livewire::test(StoreInventory::class)->assertActionHidden('wholesaleSelection');
});

it('C158 shows contradictory retries on the selection instead of the hidden UUID', function () {
    [$u,$a,$b] = c158Panel();
    $data = ['enabled' => ['ENTEL' => [], 'TIGO' => [$b->id], 'VIVA' => []], 'revisions' => [$a->id => 1, $b->id => 1], 'request_key' => (string) Str::uuid()];
    app(SaveCardWholesaleSelectionAction::class)->execute($u, $data);
    $data['enabled']['TIGO'] = [$a->id];
    Livewire::test(StoreInventory::class)->callAction('wholesaleSelection', data: $data)->assertHasActionErrors(['enabled.ENTEL']);
    expect(CardInventoryCommand::count())->toBe(1)->and($b->fresh()->wholesale_enabled)->toBeTrue();
});
