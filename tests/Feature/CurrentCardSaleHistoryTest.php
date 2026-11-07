<?php

use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordEntelRechargeAction;
use App\Domain\Tarjetas\Actions\ReviewCardSaleCancellation;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardSaleCancellation;
use App\Domain\Tarjetas\Models\CardSaleCancellationRequest;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function c411Sale(User $u, array $products): CardSale
{
    return app(RecordCardSaleAction::class)->execute($u, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => array_map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '10'], $products), 'request_key' => (string) Str::uuid()]);
}
function c411Fixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, EntelDigitalPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $oldUser = User::factory()->create();
    $oldUser->assignRole('cajera');
    $current = User::factory()->create();
    $current->assignRole('cajera');
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 0, 'initialized_at' => now(), 'initialized_by' => $admin->id, 'session_control_started_at' => now()]);
    $products = [];
    foreach (['ENTEL', 'TIGO'] as $company) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => 1000, 'purchase_price_cents' => 900, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 100]);
        $products[] = $p;
    }
    EntelDigitalAccount::create(['kind' => 'CREDIT', 'balance_cents' => 100000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
    $first = CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $current->id, 'active_slot' => 1, 'opened_at' => now()]);
    $sameCashierOld = c411Sale($current, $products);
    $first->update(['active_slot' => null, 'closed_at' => now()]);
    $second = CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $oldUser->id, 'active_slot' => 1, 'opened_at' => now()]);
    $otherOld = c411Sale($oldUser, [$products[0]]);
    $second->update(['active_slot' => null, 'closed_at' => now()]);
    $session = CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $current->id, 'active_slot' => 1, 'opened_at' => now()]);
    $sale = c411Sale($current, $products);
    test()->travel(1)->seconds();
    $recharge = app(RecordEntelRechargeAction::class)->execute($current, ['kind' => 'CREDIT', 'amount' => '100', 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);

    return [$admin, $oldUser, $current, $store, $session, $sale, $recharge, $sameCashierOld, $otherOld];
}
it('C411 shows one complete receipt per sale only from the serving cashier current session for all viewers', function (string $viewer) {
    [$a,$old,$u,$store,$session,$sale,$recharge,$sameOld,$otherOld] = c411Fixture();
    $actor = match ($viewer) {
        'admin' => $a,'previous' => $old,default => $u
    };
    test()->actingAs($actor);
    $page = Livewire::test(QuickCardSale::class);
    $records = $page->instance()->getTableRecords();
    expect($records->total())->toBe(2)->and($records->pluck('id')->all())->toBe([(string) -$recharge->id, (string) $sale->id]);
    $card = $records->firstWhere('entry_type', 'CARD');
    expect($card->total_cents)->toBe(2000)->and($card->lines)->toHaveCount(2)->and($card->user_id)->toBe($u->id);
    $page->assertSee('Historial de ventas')->mountTableAction('detail', $card);
    $html = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
    expect($html)->toContain('ENTEL')->toContain('TIGO');
})->with(['admin', 'current', 'previous']);
it('C411 filters a company without splitting its mixed sale or changing its total', function () {
    [$a,$old,$u,$store,$session,$sale] = c411Fixture();
    test()->actingAs($u);
    $page = Livewire::test(QuickCardSale::class)->filterTable('history', ['company' => 'TIGO']);
    $rows = $page->instance()->getTableRecords();
    expect($rows->total())->toBe(1)->and($rows->first()->source_id)->toBe($sale->id)->and($rows->first()->total_cents)->toBe(2000)->and($rows->first()->lines)->toHaveCount(2);
});
it('C411 denies direct detail retrieval for past turns even of the same cashier', function () {
    [$a,$old,$u,$store,$session,$sale,$recharge,$sameOld,$otherOld] = c411Fixture();
    $q = app(CardSaleRegisterQuery::class);
    foreach ([$sameOld, $otherOld] as $past) {
        $entry = $q->entries($a)->where('sale_register.id', (string) $past->id)->firstOrFail();
        expect(fn () => $q->currentSessionDetail($a, $entry))->toThrow(ModelNotFoundException::class);
    }
});
it('C411 empties the history when the serving session ends and exposes only the next cashier new receipts', function () {
    [$a,$old,$u,$store,$session,$sale] = c411Fixture();
    $q = app(CardSaleRegisterQuery::class);
    $session->update(['active_slot' => null, 'closed_at' => now()]);
    expect($q->currentSessionEntries($a)->count())->toBe(0);
    CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $old->id, 'active_slot' => 1, 'opened_at' => now()]);
    expect($q->currentSessionEntries($u)->count())->toBe(0);
    $new = c411Sale($old, [CardProduct::firstOrFail()]);
    expect($q->currentSessionEntries($a)->sole()->source_id)->toBe($new->id)->and(CardSale::count())->toBe(4);
});

it('C411 cancels the correct complete receipt after previous turns have different line IDs', function () {
    [$a,$old,$u,$store,$session,$sale] = c411Fixture();
    test()->actingAs($a);
    $entry = app(CardSaleRegisterQuery::class)->currentSessionEntries($a)->where('entry_type', 'CARD')->firstOrFail();
    expect($entry->id)->toBe((string) $sale->id)->and($sale->lines->first()->id)->not->toBe($sale->id);
    $before = $store->fresh()->cash_balance_cents;
    $review = app(ReviewCardSaleCancellation::class);
    $review->request($u, $entry->source_id, 'Registro duplicado de venta completa');
    $review->decide($a, CardSaleCancellationRequest::sole()->id, true);
    expect(CardSaleCancellation::sole()->card_sale_id)->toBe($sale->id)->and($store->fresh()->cash_balance_cents)->toBe($before - $sale->total_cents)->and(CardSale::count())->toBe(3);
});
