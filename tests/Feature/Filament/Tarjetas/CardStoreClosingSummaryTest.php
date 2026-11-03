<?php

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Filament\Pages\Tarjetas\CardStoreClosing;
use App\Filament\Pages\Tarjetas\CardStoreCountHistory;
use App\Models\User;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
it('C184 previews and preserves separate shortages and surpluses even when stock totals coincide', function (string $cash, int $missingCash, int $surplusCash, int $debt) {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $a = User::factory()->create();
    $a->assignRole('cajera');
    $this->actingAs($a);
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 100000, 'initialized_at' => now(), 'initialized_by' => $a->id]);
    foreach ([10, 20] as $face) {
        $p = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => $face * 100, 'purchase_price_cents' => $face * 90, 'normal_price_cents' => $face * 100, 'wholesale_price_cents' => $face * 95, 'is_active' => true, 'revision' => 1, 'created_by' => $a->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 3]);
    }
    $query = app(CardStoreSessionQuery::class);
    app(RecordCardStoreCountAction::class)->open($a, $query->form($a, 'OPEN'));
    $form = $query->form($a, 'CLOSE');
    $form['cash'] = $cash;
    $form['stocks'][0]['quantity'] = 2;
    $form['stocks'][1]['quantity'] = 4;
    // Preview is read-only: explain surplus is required only when committing.
    $preview = $query->preview($a, 'CLOSE', $form);
    $summary = $preview['summary'];
    expect($summary['cash']['expected'])->toBe(100000);
    expect($summary['cash']['missing'])->toBe($missingCash);
    expect($summary['cash']['surplus'])->toBe($surplusCash);
    expect($summary['shortage_cents'])->toBe($debt);
    $group = $summary['companies'][0];
    expect($group['expected'])->toBe(6);
    expect($group['counted'])->toBe(6);
    expect($group['missing'])->toBe(1);
    expect($group['surplus'])->toBe(1);
    expect($group['reserved'])->toBe(1);
    $page = Livewire::test(CardStoreClosing::class)->set('data.cash', $cash)->set('data.stocks.0.quantity', '2')->set('data.stocks.1.quantity', '4');
    $html = $page->instance()->getSchema('form')->toHtml();
    expect($html)->toContain('Faltante efectivo', 'Sobrante efectivo', 'Faltan tarjetas', 'Sobran tarjetas')->not->toContain('Ver detalle por corte y reservas');
    expect($summary['stock']['expected'])->toBe(6);
    expect($summary['stock']['counted'])->toBe(6);
    expect($summary['stock']['missing'])->toBe(1);
    expect($summary['stock']['surplus'])->toBe(1);
    expect(CardStoreCount::count())->toBe(1);
    $page->call('confirmClosing')->assertHasErrors(['data.reason']);
    expect(CardStoreCount::count())->toBe(1);
    $page->set('data.reason', 'Dos diferencias independientes')->call('confirmClosing')->assertHasNoErrors();
    $count = CardStoreCount::where('kind', 'CLOSE')->sole();
    expect($count->shortage_cents)->toBe($debt);
    expect(EconomicIncident::sum('pending_cents'))->toBe($debt);
    $snapshot = $query->detail($a, $count->id)['summary'];
    expect($snapshot)->toBe($summary);
    CardProduct::latest('id')->first()->update(['face_value_cents' => 99900, 'normal_price_cents' => 1]);
    CardStock::query()->update(['quantity' => 77]);
    CardStore::query()->update(['cash_balance_cents' => 123]);
    expect($query->detail($a, $count->id)['summary'])->toBe($snapshot);
    $history = Livewire::test(CardStoreCountHistory::class)->mountTableAction('detail', $count);
    expect($history->instance()->getMountedAction()->getModalContent()->render())->toContain('Bs 1000', 'Faltante efectivo', 'Sobrante efectivo', 'Stock por empresa', 'Bs 10');
    $a->forceFill(['is_active' => false])->save();
    expect(fn () => $query->detail($a, $count->id))->toThrow(AuthorizationException::class);
})->with(['sobrante efectivo y falta tarjeta' => ['1005', 0, 500, 1000], 'faltante efectivo y sobra tarjeta' => ['995', 500, 0, 1500]]);
