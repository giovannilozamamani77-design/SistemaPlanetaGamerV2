<?php

use App\Domain\Reportes\Queries\DailyReportRecordsQuery;
use App\Domain\Reportes\Queries\ModuleMarginQuery;
use App\Domain\Tarjetas\Actions\CancelCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\ReviewCardSaleCancellation;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSaleCancellation;
use App\Domain\Tarjetas\Models\CardSaleCancellationRequest;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Queries\CardCostsQuery;
use App\Domain\Tarjetas\Queries\CardSaleRegisterQuery;
use App\Filament\Pages\Tarjetas\CardSaleCancellations;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Models\User;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\EntelDigitalPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
function c410Fixture(bool $mixed = false): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, EntelDigitalPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 1000, 'initialized_at' => now(), 'initialized_by' => $admin->id, 'session_control_started_at' => now()]);
    $session = CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $cashier->id, 'active_slot' => 1, 'opened_at' => now()]);
    $products = [];
    foreach ($mixed ? ['TIGO', 'ENTEL'] : ['TIGO'] as $company) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => 1000, 'purchase_price_cents' => 900, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 10, 'cost_value_cents' => 9000]);
        $products[] = $p;
    }
    $sale = app(RecordCardSaleAction::class)->execute($cashier, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => array_map(fn ($p) => ['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '10'], $products), 'request_key' => (string) Str::uuid()]);

    return [$admin, $cashier, $store, $session, $sale, $products];
}
function c410Data(): array
{
    return ['reason' => 'Venta duplicada por error de registro', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
}
it('C410 reverses all companies cash and historical cost once without changing the original sale', function () {
    [$a,$u,$store,$session,$sale,$products] = c410Fixture(true);
    $original = $sale->getAttributes();
    $lines = $sale->lines->map->getAttributes()->all();
    $data = c410Data();
    $action = app(CancelCardSaleAction::class);
    $audit = $action->execute($a, $sale->id, $data);
    expect($store->fresh()->cash_balance_cents)->toBe(1000)->and(CardStock::pluck('quantity')->all())->toBe([10, 10])->and(CardStock::pluck('cost_value_cents')->all())->toBe([9000, 9000])->and($sale->fresh()->getAttributes())->toBe($original)->and($sale->lines()->get()->map->getAttributes()->all())->toBe($lines)->and($audit->user_id)->toBe($a->id)->and($audit->card_store_session_id)->toBe($session->id)->and($audit->total_cents)->toBe(4000);
    expect($action->execute($a, $sale->id, $data)->id)->toBe($audit->id)->and(CardSaleCancellation::count())->toBe(1)->and(CardInventoryMovement::where('kind', 'SALE_CANCELLATION')->count())->toBe(2)->and(CardInventoryMovement::where('kind', 'SALE_CANCELLATION')->sum('cash_delta_cents'))->toBe(-4000);
    expect(fn () => $action->execute($a, $sale->id, c410Data()))->toThrow(ValidationException::class);
});
it('C410 only allows an active administrator with sale permission', function (string $role, bool $active, bool $permission) {
    [$admin,$cashier,$store,$session,$sale] = c410Fixture();
    $actor = User::factory()->create(['is_active' => $active]);
    $actor->assignRole($role);
    if (! $permission) {
        $actor->revokePermissionTo('tarjetas.ventas.registrar');
        $actor->roles()->detach();
        $actor->assignRole($role);
        $actor->roles->each(fn ($r) => $r->revokePermissionTo('tarjetas.ventas.registrar'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    expect(fn () => app(CancelCardSaleAction::class)->execute($actor, $sale->id, c410Data()))->toThrow(AuthorizationException::class);
    expect(CardSaleCancellation::count())->toBe(0)->and($store->fresh()->cash_balance_cents)->toBe(3000);
})->with([['cajera', true, true], ['encargado', true, true], ['administrador', false, true], ['administrador', true, false]]);
it('C410 rejects a closed turn even when another turn is open', function (bool $reopen) {
    [$a,$u,$store,$session,$sale] = c410Fixture();
    $session->update(['active_slot' => null, 'closed_at' => now()]);
    if ($reopen) {
        CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $u->id, 'active_slot' => 1, 'opened_at' => now()]);
    }
    expect(fn () => app(CancelCardSaleAction::class)->execute($a, $sale->id, c410Data()))->toThrow(ValidationException::class);
    expect(CardSaleCancellation::count())->toBe(0)->and(CardStock::sole()->quantity)->toBe(8);
})->with([false, true]);
it('C410 refuses insufficient available cash without partial stock changes', function () {
    [$a,$u,$store,$session,$sale] = c410Fixture();
    $store->update(['cash_balance_cents' => 1500]);
    $before = CardStock::sole()->getAttributes();
    expect(fn () => app(CancelCardSaleAction::class)->execute($a, $sale->id, c410Data()))->toThrow(ValidationException::class);
    expect(CardStock::sole()->getAttributes())->toBe($before)->and($store->fresh()->cash_balance_cents)->toBe(1500)->and(CardSaleCancellation::count())->toBe(0);
});
it('C410 rolls back earlier product reversals if a later product cannot be returned', function () {
    [$a,$u,$store,$session,$sale,$products] = c410Fixture(true);
    CardStock::where('card_product_id', $products[1]->id)->update(['quantity' => 1000000]);
    expect(fn () => app(CancelCardSaleAction::class)->execute($a, $sale->id, c410Data()))->toThrow(ValidationException::class);
    expect(CardStock::where('card_product_id', $products[0]->id)->sole()->quantity)->toBe(8)->and($store->fresh()->cash_balance_cents)->toBe(5000)->and(CardInventoryMovement::where('kind', 'SALE_CANCELLATION')->count())->toBe(0);
});
it('C410 restores unknown original cost as pending instead of inventing a margin', function () {
    [$a,$u,$store,$session,$sale,$products] = c410Fixture();
    CardStock::sole()->update(['cost_value_cents' => null]);
    $new = app(RecordCardSaleAction::class)->execute($u, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $products[0]->id, 'quantity' => 1, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()]);
    CardStock::sole()->update(['cost_value_cents' => 6300]);
    app(CancelCardSaleAction::class)->execute($a, $new->id, c410Data());
    expect(CardStock::sole()->cost_value_cents)->toBeNull();
});
it('C410 excludes cancelled sales from turn summaries costs and operational reports while keeping them in history', function () {
    [$a,$u,$store,$session,$sale] = c410Fixture();
    app(CancelCardSaleAction::class)->execute($a, $sale->id, c410Data());
    $q = app(CardSaleRegisterQuery::class);
    expect($q->currentTurnSummary($u)['companies']['TIGO']['total_cents'])->toBe(0)->and($q->companyEntries($u)->sole()->cancelled_at)->not->toBeNull()->and($q->entries($u)->sole()->source_id)->toBe($sale->id);
    expect(app(CardCostsQuery::class)->margins($a)['PHYSICAL']['records'])->toBe(0);
    $period = now()->setTimezone('America/La_Paz')->toDateString();
    $r = app(DailyReportRecordsQuery::class);
    expect($r->selectedContributions($a, $period, $period, ['CARDS'])->count())->toBe(0);
    $margin = app(ModuleMarginQuery::class)->execute($a, 'CARDS', $period, $period, []);
    expect($margin['known_cents'])->toBe(0);
});
it('C410 validates reason confirmation and UUID before changing money', function (string $field, mixed $value) {
    [$a,$u,$store,$session,$sale] = c410Fixture();
    $data = c410Data();
    $data[$field] = $value;
    expect(fn () => app(CancelCardSaleAction::class)->execute($a, $sale->id, $data))->toThrow(ValidationException::class);
    expect($store->fresh()->cash_balance_cents)->toBe(3000)->and(CardSaleCancellation::count())->toBe(0);
})->with([['reason', 'corto'], ['reason', '                '], ['confirmed', false], ['request_key', 'invalid']]);
it('C410 does not allow changed data to reuse an annulment request', function () {
    [$a,$u,$store,$session,$sale] = c410Fixture();
    $data = c410Data();
    app(CancelCardSaleAction::class)->execute($a, $sale->id, $data);
    $data['reason'] = 'Un motivo diferente para el mismo envío';
    expect(fn () => app(CancelCardSaleAction::class)->execute($a, $sale->id, $data))->toThrow(ValidationException::class);
    expect(CardSaleCancellation::count())->toBe(1);
});
it('C410 keeps cancellation audit immutable', function () {
    [$a,$u,$store,$session,$sale] = c410Fixture();
    $audit = app(CancelCardSaleAction::class)->execute($a, $sale->id, c410Data());
    expect(fn () => $audit->update(['reason' => 'cambiar']))->toThrow(LogicException::class);
    expect(fn () => $audit->fresh()->delete())->toThrow(LogicException::class);
});
it('requests a mixed sale reversal and only lets an administrator approve it', function () {
    [$a,$u,$store,$session,$sale] = c410Fixture(true);
    $entry = app(CardSaleRegisterQuery::class)->currentSessionEntries($a)->first();
    test()->actingAs($u);
    Livewire::test(QuickCardSale::class)->callTableAction('cancelSale', $entry, data: ['reason' => 'Venta duplicada por error'])->assertHasNoTableActionErrors();
    test()->get(CardSaleCancellations::getUrl())->assertForbidden();
    test()->actingAs($a);
    $page = Livewire::test(QuickCardSale::class);
    $request = CardSaleCancellationRequest::sole();
    expect($request->status)->toBe('PENDIENTE')->and(CardSaleCancellation::count())->toBe(0)->and($store->fresh()->cash_balance_cents)->toBe(5000);
    Livewire::test(CardSaleCancellations::class)->callTableAction('approve', $request)->assertHasNoTableActionErrors();
    app(ReviewCardSaleCancellation::class)->decide($a, $request->id, true);
    expect(CardSaleCancellation::count())->toBe(1)->and($store->fresh()->cash_balance_cents)->toBe(1000);
    $page->assertTableActionHidden('cancelSale', $entry);
    Livewire::test(CardSaleCancellations::class)->assertCanSeeTableRecords([$request->fresh()])->assertSee('Venta duplicada por error');
});
it('C410 makes cancellation previews read only and escapes reasons in admin history', function () {
    [$a,$u,$store,$session,$sale] = c410Fixture();
    test()->actingAs($a);
    $entry = app(CardSaleRegisterQuery::class)->currentSessionEntries($a)->first();
    Livewire::test(QuickCardSale::class)->mountTableAction('cancelSale', $entry)->unmountTableAction();
    expect(CardSaleCancellation::count())->toBe(0)->and($store->fresh()->cash_balance_cents)->toBe(3000);
    $data = c410Data();
    $data['reason'] = '<script>alert(1)</script> error de registro';
    app(ReviewCardSaleCancellation::class)->request($u, $sale->id, $data['reason']);
    Livewire::test(CardSaleCancellations::class)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
        ->callTableAction('deny', CardSaleCancellationRequest::sole());
    expect(CardSaleCancellation::count())->toBe(0)->and($store->fresh()->cash_balance_cents)->toBe(3000);
});
