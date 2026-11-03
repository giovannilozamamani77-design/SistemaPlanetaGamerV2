<?php

use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Filament\Pages\Tarjetas\CardStoreCountHistory;
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

it('C205 explains each historical company and cut without netting shortages or changing the snapshot', function (string $kind) {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $user = User::factory()->create(['name' => 'Cajera del conteo']);
    $user->assignRole('cajera');
    $this->actingAs($user);
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 10000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
    $quantities = [];
    foreach ([['ENTEL', 10, 3, 2], ['ENTEL', 20, 3, 4], ['TIGO', 10, 2, 3], ['VIVA', 40, 1, 1], ['VIVA', 100, 0, 0]] as [$company, $face, $expected, $counted]) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => $face * 100, 'purchase_price_cents' => $face * 90, 'normal_price_cents' => $face * 100, 'wholesale_price_cents' => $face * 95, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => $expected]);
        $quantities[$p->id] = $counted;
    }
    $query = app(CardStoreSessionQuery::class);
    $action = app(RecordCardStoreCountAction::class);
    if ($kind === 'CLOSE') {
        $action->open($user, $query->form($user, 'OPEN'));
    }
    $form = $query->form($user, $kind);
    $form['cash'] = '95';
    $form['reason'] = '<img src=x onerror=alert(1)> Observación de prueba';
    foreach ($form['stocks'] as &$line) {
        $line['quantity'] = $quantities[$line['product_id']];
    }
    unset($line);
    $count = $kind === 'OPEN' ? $action->open($user, $form) : $action->close($user, $form);
    $record = CardStoreCount::where('kind', $kind)->sole();
    $detail = $query->detail($user, $record->id);
    $companies = collect($detail['detail']['companies'])->keyBy('company');
    expect($companies['ENTEL']['expected'])->toBe(6)->and($companies['ENTEL']['counted'])->toBe(6)->and($companies['ENTEL']['missing'])->toBe(1)->and($companies['ENTEL']['surplus'])->toBe(1);
    expect($companies['VIVA']['lines'])->toHaveCount(1);
    expect($detail['detail']['empty_companies'][0]['lines'][0]['label'])->toBe('Cabina Viva de Bs 100');
    expect($detail['summary']['shortage_cents'])->toBe(1500);
    $page = Livewire::test(CardStoreCountHistory::class)->call('selectHistory', $kind)->mountTableAction('detail', $record);
    $html = $page->instance()->getMountedAction()->getModalContent()->render();
    expect($html)->toContain('data-count-company="ENTEL"', 'data-count-company="TIGO"', 'data-count-company="VIVA"', 'Esperado', 'Contado', 'Faltantes', 'Sobrantes', 'Cabina Viva de Bs 40', 'Cortes sin existencias', 'Cajera del conteo', '&lt;img')->not->toContain('<img src=x');
    CardProduct::where('company', 'ENTEL')->update(['normal_price_cents' => 1]);
    CardStock::query()->update(['quantity' => 77]);
    $store->update(['cash_balance_cents' => 123]);
    expect($query->detail($user, $record->id)['detail'])->toBe($detail['detail']);
    expect($query->detail($user, $record->id)['summary'])->toBe($detail['summary']);
})->with(['OPEN', 'CLOSE']);
