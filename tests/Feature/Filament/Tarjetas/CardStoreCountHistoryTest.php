<?php

use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Filament\Pages\Tarjetas\CardStoreCountHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
it('C185 lists the latest five by actual date and keeps all older counts accessible with historical differences', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/La_Paz'));
    $this->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $a = User::factory()->create(['name' => 'Cajera A prueba']);
    $a->assignRole('cajera');
    $b = User::factory()->create(['name' => 'Cajera B prueba']);
    $b->assignRole('cajera');
    $this->actingAs($a);
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 100000, 'initialized_at' => now(), 'initialized_by' => $a->id]);
    foreach ([10, 20] as $face) {
        $product = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => $face * 100, 'purchase_price_cents' => $face * 90, 'normal_price_cents' => $face * 100, 'wholesale_price_cents' => $face * 95, 'is_active' => true, 'revision' => 1, 'created_by' => $a->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $product->id, 'quantity' => 100]);
    }
    $action = app(RecordCardStoreCountAction::class);
    $query = app(CardStoreSessionQuery::class);
    for ($i = 0; $i < 6; $i++) {
        $owner = $i % 2 ? $b : $a;
        $action->open($owner, $query->form($owner, 'OPEN'));
        $this->travel(1)->minutes();
        $form = $query->form($owner, 'CLOSE');
        if ($i === 5) {
            $form['cash'] = '1005';
            $form['stocks'][0]['quantity'] = 99;
            $form['stocks'][1]['quantity'] = 101;
            $form['reason'] = 'Diferencias de prueba separadas';
        }
        $action->close($owner, $form);
        $this->travel(1)->minutes();
    }
    $records = CardStoreCount::orderBy('id')->get();
    $last = $records->last();
    // A historical import inserted later must not displace a genuinely newer count.
    $historicalTime = CarbonImmutable::parse('2026-10-19 23:59:59', 'America/La_Paz')->utc();
    $session = CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $b->id, 'active_slot' => null, 'opened_at' => $historicalTime, 'closed_at' => $historicalTime->addHour()]);
    $oldData = $records->first()->attributesToArray();
    unset($oldData['id']);
    $oldData['card_store_session_id'] = $session->id;
    $oldData['user_id'] = $b->id;
    $oldData['created_at'] = $historicalTime;
    $imported = CardStoreCount::create($oldData);
    $closings = $records->where('kind', 'CLOSE')->values();
    $openings = $records->where('kind', 'OPEN')->push($imported)->values();
    $latest = $closings->reverse()->take(5)->values();
    $older = $closings->take(1);
    $page = Livewire::test(CardStoreCountHistory::class)->assertSee('Historial de cierres')->assertSee('Historial de aperturas')->assertSee('Fecha y hora')->assertSee('Cajera')->assertSee('Diferencias')->assertSee('Cajera A prueba')->assertSee('Cajera B prueba')->assertSee('20/10/2026 10:11')->assertSee('Faltante Bs 10')->assertSee('Sobrante efectivo Bs 5')->assertSee('Sobrante stock 1 unid.')->assertSee('Sin diferencias')->assertCanSeeTableRecords($latest)->assertCanNotSeeTableRecords($older)->assertCanNotSeeTableRecords($openings);
    expect($page->instance()->getTableRecords()->count())->toBe(5);
    expect($page->instance()->getTableRecords()->pluck('id')->all())->toBe($latest->pluck('id')->all());
    $page->call('gotoPage', 2)->assertCanSeeTableRecords($older)->assertCanNotSeeTableRecords($latest);
    $page->set('tableRecordsPerPage', 10)->call('gotoPage', 1)->assertCanSeeTableRecords($closings)->assertCanNotSeeTableRecords($openings);
    $page->mountTableAction('detail', $last);
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain('Faltante efectivo', 'Sobrante efectivo', 'Stock por empresa');
    $page->unmountAction();
    $page->filterTable('history', ['from' => '2026-10-20', 'until' => '2026-10-20', 'user_id' => $b->id])->assertCanSeeTableRecords($closings->where('user_id', $b->id))->assertCanNotSeeTableRecords($closings->where('user_id', $a->id));
    $page->call('selectHistory', 'OPEN')->assertCanSeeTableRecords($openings->where('created_at', '>=', CarbonImmutable::parse('2026-10-20', 'America/La_Paz')))->assertCanNotSeeTableRecords($closings);
    $page->set('tableRecordsPerPage', 10)->call('gotoPage', 1)->assertCanSeeTableRecords($openings);
    $page->filterTable('history', ['until' => '2026-10-19', 'user_id' => $b->id])->assertCanSeeTableRecords([$imported])->assertCanNotSeeTableRecords($records);
    expect($query->filterHistory($query->history($a, 'OPEN'), ['from' => '2026-10-22', 'until' => '2026-10-20'])->count())->toBe(0);
    expect($query->filterHistory($query->history($a, 'CLOSE'), ['user_id' => 'bad'])->count())->toBe(0);
    expect(fn () => $query->history($a, 'INVALID'))->toThrow(ValidationException::class);
    expect(CardStoreCount::count())->toBe(13);
    expect($query->differences($last))->toBe(['shortage_cents' => 1000, 'cash_surplus_cents' => 500, 'stock_surplus' => 1]);
    Role::findByName('cajera')->revokePermissionTo('tarjetas.movimientos.ver');
    expect(fn () => $query->history($a))->toThrow(AuthorizationException::class);
    $page->call('selectHistory', 'CLOSE')->assertForbidden();
    $this->get(CardStoreCountHistory::getUrl())->assertForbidden();
});
