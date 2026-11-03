<?php

use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordEntelRechargeAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Queries\CardSaleRegisterQuery;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Models\User;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\EntelDigitalPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
it('C198 separates a mixed receipt by historical company without duplicating cash or private digital lines', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, EntelDigitalPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('cajera');
    test()->actingAs($u);
    CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 0, 'initialized_at' => now(), 'initialized_by' => $u->id]);
    foreach (['CREDIT' => 12345678, 'PACKAGES' => 87654321] as $kind => $amount) {
        EntelDigitalAccount::create(['kind' => $kind, 'balance_cents' => $amount, 'initialized_at' => now(), 'initialized_by' => $u->id]);
    }

    CardStore::sole()->update(['session_control_started_at' => now()]);
    CardStoreSession::create(['card_store_id' => CardStore::sole()->id, 'user_id' => $u->id, 'active_slot' => 1, 'opened_at' => now()]);

    $products = [];
    foreach (['ENTEL' => 940, 'TIGO' => 950] as $company => $price) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => 1000, 'purchase_price_cents' => 900, 'normal_price_cents' => 1000, 'wholesale_price_cents' => $price, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $u->id]);
        CardStock::create(['card_store_id' => CardStore::sole()->id, 'card_product_id' => $p->id, 'quantity' => 10]);
        $products[$company] = $p;
    }
    $sale = app(RecordCardSaleAction::class)->execute($u, ['mode' => 'WHOLESALE', 'payment_method' => 'CASH', 'lines' => [['product_id' => $products['ENTEL']->id, 'quantity' => 4, 'unit_price' => '9.40'], ['product_id' => $products['TIGO']->id, 'quantity' => 1, 'unit_price' => '9.50']], 'request_key' => (string) Str::uuid()]);
    app(RecordEntelRechargeAction::class)->execute($u, ['kind' => 'CREDIT', 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $query = app(CardSaleRegisterQuery::class);
    $summary = $query->currentTurnSummary($u);
    expect($summary['companies']['ENTEL'])->toBe(['quantity' => 4, 'total_cents' => 3760, 'credit_cents' => 10000, 'packages_cents' => 0, 'collected_cents' => 13760]);
    expect($summary['companies']['TIGO']['quantity'])->toBe(1)->and($summary['companies']['TIGO']['collected_cents'])->toBe(950);
    expect($summary['companies']['VIVA']['quantity'])->toBe(0);
    $entries = $query->companyEntries($u)->get();
    expect($entries)->toHaveCount(3)->and($entries->pluck('id')->unique())->toHaveCount(3);
    $cards = $entries->where('entry_type', 'CARD')->keyBy('company');
    expect(fn () => $cards['ENTEL']->save())->toThrow(LogicException::class);
    expect($cards['ENTEL']->total_cents)->toBe(3760)->and($cards['TIGO']->total_cents)->toBe(950)->and($cards->sum('total_cents'))->toBe($sale->total_cents);
    expect($cards['ENTEL']->companyLines())->toHaveCount(1)->and($cards['TIGO']->companyLines())->toHaveCount(1)->and($entries->where('entry_type', 'RECHARGE')->sole()->companyLines())->toHaveCount(0);
    expect($query->filterHistory($query->companyEntries($u), ['company' => 'TIGO'])->get()->sum('total_cents'))->toBe(950);
    $products['ENTEL']->update(['company' => 'VIVA']);
    $page = Livewire::test(QuickCardSale::class)->assertCanSeeTableRecords($entries);
    expect($page->instance()->getTable()->getDefaultGroup()->getId())->toBe('company');
    $page->mountTableAction('detail', $cards['ENTEL']);
    $html = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
    expect($html)->toContain('Entel')->not->toContain('Tigo');
    expect(CardStore::sole()->cash_balance_cents)->toBe(14710);
    expect(CardSale::count())->toBe(1);
    $page->assertSee('Resumen del turno abierto')->assertSee('Tarjetas vendidas');
    app(RecordEntelRechargeAction::class)->execute($u, ['kind' => 'PACKAGES', 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect($query->currentTurnSummary($u)['companies']['ENTEL'])->toBe(['quantity' => 4, 'total_cents' => 3760, 'credit_cents' => 10000, 'packages_cents' => 10000, 'collected_cents' => 23760]);
    expect(CardStore::sole()->cash_balance_cents)->toBe(24710);
    CardStoreSession::query()->update(['active_slot' => null, 'closed_at' => now()]);
    expect($query->currentTurnSummary($u))->toBeNull();
    CardStoreSession::create(['card_store_id' => CardStore::sole()->id, 'user_id' => $u->id, 'active_slot' => 1, 'opened_at' => now()]);
    expect($query->currentTurnSummary($u)['companies']['ENTEL']['collected_cents'])->toBe(0);
    expect($query->currentTurnSummary($u)['companies']['TIGO']['quantity'])->toBe(0);
});
