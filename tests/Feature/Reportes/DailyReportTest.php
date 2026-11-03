<?php

use App\Domain\Accesorios\Actions\RecordAccessoryCashDepositAction;
use App\Domain\Accesorios\Actions\VoidAccessoryCashDepositAction;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Envios\Models\ExchangePolicy;
use App\Domain\Prestamos\Actions\AnnulLoanAction;
use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Actions\SettleLoanAction;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Queries\DailyReportQuery;
use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordEntelRechargeAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Filament\Pages\Reportes\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function c351Admin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-30 16:00:00', 'UTC'));
    test()->seed(UsuariosPermissionsSeeder::class);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');

    return $admin;
}

function c351Movement(User $user, string $kind, int $amount, ?string $date = null): LoanMovement
{
    return LoanMovement::create(['user_id' => $user->id, 'kind' => $kind, 'delta_cents' => $amount, 'before_cents' => 0, 'after_cents' => max(0, $amount), 'created_at' => $date ?? now()]);
}

function c351Session(User $user, array $values = []): CashBoxSession
{
    $box = CashBox::factory()->create(['created_by' => $user->id]);
    $config = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $user->id, 'base_capital_cents' => 10000, 'accounts' => ['cash' => ['name' => 'Efectivo', 'initial_cents' => 10000]], 'shifts' => [], 'reason' => 'Prueba', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);

    return CashBoxSession::create(array_replace(['cash_box_id' => $box->id, 'user_id' => $user->id, 'configuration_id' => $config->id, 'status' => 'ABIERTA', 'active_slot' => 1, 'base_capital_cents' => 10000, 'opening_balances' => ['cash' => 10000], 'expected_balances' => ['cash' => 10000], 'current_balances' => ['cash' => 10500], 'comparison' => [], 'opened_at' => now(), 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)], $values));
}

it('C351 permits only active administrators even with stale identities and extra cashier permissions', function () {
    $admin = c351Admin();
    expect(Gate::forUser($admin)->allows('viewDailyReports'))->toBeTrue();
    foreach (['cajera', 'encargado'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->givePermissionTo('usuarios.ver');
        expect(fn () => app(DailyReportQuery::class)->execute($user, '2026-10-30'))->toThrow(AuthorizationException::class);
    }
    User::whereKey($admin->id)->update(['is_active' => false]);
    expect(fn () => app(DailyReportQuery::class)->execute($admin, '2026-10-30'))->toThrow(AuthorizationException::class);
});

it('C351 refuses revoked roles and restricted panel access on subsequent requests', function () {
    $admin = c351Admin();
    test()->actingAs($admin);
    $page = Livewire::test(DailyReport::class)->assertSuccessful();
    $fresh = User::findOrFail($admin->id);
    $fresh->removeRole('administrador');
    $page->call('applyDate')->assertForbidden();
    expect(fn () => app(DailyReportQuery::class)->execute($admin, '2026-10-30'))->toThrow(AuthorizationException::class);
    $fresh->assignRole('administrador');
    $fresh->restrictedPermissions()->attach(Permission::findByName('panel.acceder', 'web'));
    expect(fn () => app(DailyReportQuery::class)->execute($admin, '2026-10-30'))->toThrow(AuthorizationException::class);
});

it('C351 protects the direct URL and hides its navigation for cashiers and guests', function () {
    $admin = c351Admin();
    $url = DailyReport::getUrl();
    test()->get($url)->assertRedirect();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    test()->actingAs($cashier)->get($url)->assertForbidden();
    expect(DailyReport::shouldRegisterNavigation() && DailyReport::canAccess())->toBeFalse();
    test()->actingAs($admin)->get($url)->assertOk()->assertSee('Resumen diario')->assertSee('Dinero disponible ahora');
});

it('C351 uses Bolivia midnight boundaries and actual collection dates without counting capital movements', function () {
    $admin = c351Admin();
    c351Movement($admin, 'TERM_PAYMENT', 1, '2026-10-30 03:59:59');
    c351Movement($admin, 'TERM_PAYMENT', 1500, '2026-10-30 04:00:00');
    c351Movement($admin, 'SETTLEMENT', 21500, '2026-10-31 03:59:59');
    c351Movement($admin, 'TERM_PAYMENT', 9999, '2026-10-31 04:00:00');
    foreach (['INITIAL', 'IN', 'OUT', 'DISBURSEMENT', 'ANNULMENT', 'OBJECT_EXPENSE'] as $kind) {
        c351Movement($admin, $kind, 100000);
    }
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    expect($report['collections_cents'])->toBe(23000);
    expect($report['sales_cents'])->toBe(0);
    expect(collect($report['rows'])->firstWhere('label', 'Préstamos · Pago y entrega')['collections_cents'])->toBe(21500);
});

it('C351 separates mixed accessory and phone sales using stored final line totals only', function () {
    $admin = c351Admin();
    $sale = AccessorySale::create(['user_id' => $admin->id, 'actor_name' => $admin->name, 'payment_method' => 'CASH', 'quantity' => 4, 'total_cents' => 149000, 'cost_cents' => 100000, 'pricing_revision' => 1, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    foreach ([['ACCESSORY', 2, 5000], ['ACCESSORY', 1, 9000], ['PHONE', 1, 135000]] as [$kind, $quantity, $amount]) {
        AccessorySaleLine::create(['sale_id' => $sale->id, 'product_id' => null, 'kind' => $kind, 'product_code' => 'PRUEBA', 'product_name' => 'Producto', 'quantity' => $quantity, 'unit_price_cents' => $amount, 'total_cents' => $amount, 'cost_cents' => 1000, 'previous_revision' => 0, 'before_quantity' => $quantity, 'after_quantity' => 0, 'before_value_cents' => 1000, 'after_value_cents' => 0]);
    }
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    $rows = collect($report['rows'])->keyBy('label');
    expect($report['sales_cents'])->toBe(149000);
    expect($report['collections_cents'])->toBe(149000);
    expect($rows['Accesorios'])->toMatchArray(['sales_cents' => 14000, 'operations' => 1]);
    expect($rows['Teléfonos'])->toMatchArray(['sales_cents' => 135000, 'operations' => 1]);
});

it('C351 reads real card sale replays once and does not count their cash movement twice', function () {
    $admin = c351Admin();
    test()->seed([TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    $product = app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.4', 'normal_price' => '10', 'wholesale_price' => '9.5', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '100', 'stocks' => [['product_id' => $product->id, 'quantity' => 10]], 'request_key' => (string) Str::uuid()]);
    $data = ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()];
    $action = app(RecordCardSaleAction::class);
    $action->execute($admin, $data);
    $action->execute($admin, $data);
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    expect($report['sales_cents'])->toBe(3000);
    expect($report['collections_cents'])->toBe(3000);
    expect($report['rows'][0]['operations'])->toBe(1);
    expect($report['balances'][0]['amount_cents'])->toBe(13000);
});

it('C351 excludes invalidated box commissions and transfer principals from collections', function () {
    $admin = c351Admin();
    $session = c351Session($admin);
    foreach (['VIGENTE' => 150, 'ANULADA' => 300, 'INVALIDADA' => 500] as $status => $fee) {
        CashBoxOperation::create(['cash_box_session_id' => $session->id, 'user_id' => $admin->id, 'amount_cents' => 9000000, 'fee_type' => 'ACH', 'fee_cents' => $fee, 'suggested_fee_cents' => $fee, 'fee_component' => 'cash', 'deltas' => ['cash' => $fee], 'status' => $status, 'created_at' => now()]);
    }
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    expect($report['collections_cents'])->toBe(150);
    expect($report['sales_cents'])->toBe(0);
});

it('C351 uses current balances independently of the selected activity date and never initial capital as counted money', function () {
    $admin = c351Admin();
    CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 7000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
    DB::table('loan_funds')->updateOrInsert(['code' => 'YAPE'], ['balance_cents' => 80000, 'revision' => 1, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
    DB::table('accessory_commercial_funds')->updateOrInsert(['code' => 'GENERAL'], ['balance_cents' => 5000, 'revision' => 1]);
    CashBox::factory()->create(['created_by' => $admin->id, 'base_capital_cents' => 99999999]);
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-01');
    expect($report['sales_cents'])->toBe(0);
    expect(array_column(array_slice($report['balances'], 0, 3), 'amount_cents'))->toBe([7000, 80000, 5000]);
    expect(collect($report['balances'])->last()['amount_cents'])->toBeNull();
});

it('C351 counts only the latest box session and marks a pending close as unavailable', function () {
    $admin = c351Admin();
    $first = c351Session($admin, ['status' => 'CERRADA', 'active_slot' => null, 'released_at' => now(), 'handover_balances' => ['cash' => 10500]]);
    $latest = $first->replicate();
    $latest->forceFill(['status' => 'CIERRE_REGISTRADO', 'active_slot' => 1, 'released_at' => null, 'handover_balances' => null, 'current_balances' => ['cash' => 9500], 'request_key' => (string) Str::uuid()])->save();
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    $rows = collect($report['balances'])->filter(fn ($row) => str_starts_with($row['label'], 'Cajas ·'));
    expect($rows)->toHaveCount(1);
    expect($rows->first()['amount_cents'])->toBeNull();
    expect($rows->first()['note'])->toContain('Bs 95;');
});

it('C351 renders empty states and validates the date without changing the displayed period', function () {
    $admin = c351Admin();
    test()->actingAs($admin);
    Livewire::test(DailyReport::class)->assertSee('Bs 0')->assertSee('Sin conteo inicial confirmado.')
        ->set('date', '2026-02-30')->call('applyDate')->assertHasErrors(['date'])->assertSet('appliedDate', '2026-10-30')
        ->set('date', '2026-10-01')->call('applyDate')->assertHasNoErrors()->assertSet('appliedDate', '2026-10-01')->assertSee('01/10/2026');
});

it('C351 rejects invalid direct query dates', function (string $date) {
    $admin = c351Admin();
    expect(fn () => app(DailyReportQuery::class)->execute($admin, $date))->toThrow(ValidationException::class);
})->with(['empty' => '', 'format' => '30/10/2026', 'invalid day' => '2026-02-30', 'future' => '2026-10-31', 'too old' => '1899-12-31', 'injection' => "2026-10-30' OR 1=1"]);

it('C351 repeated report reads make no writes or outbound requests and stay bounded as boxes grow', function () {
    $admin = c351Admin();
    c351Session($admin);
    $query = app(DailyReportQuery::class);
    $query->execute($admin, '2026-10-30');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $query->execute($admin, '2026-10-30');
    $firstCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($i = 0; $i < 5; $i++) {
        c351Session($admin);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $before = LoanMovement::count();
    $query->execute($admin, '2026-10-30');
    $secondCount = count(DB::getQueryLog()) - 1;
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($secondCount)->toBe($firstCount);
    expect($secondCount)->toBeLessThan(25);
    expect(collect($queries)->contains(fn ($row) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $row['query'])))->toBeFalse();
    expect(LoanMovement::count())->toBe($before);
    Http::assertNothingSent();
});

it('C351 separates credit and packages and never adds their bonus or debit as a second collection', function () {
    $admin = c351Admin();
    test()->seed([TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 1000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
    $action = app(RecordEntelRechargeAction::class);
    foreach (['CREDIT' => '100', 'PACKAGES' => '200'] as $kind => $amount) {
        EntelDigitalAccount::create(['kind' => $kind, 'balance_cents' => 50000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
        $data = ['kind' => $kind, 'amount' => $amount, 'external_confirmed' => true, 'request_key' => (string) Str::uuid()];
        $action->execute($admin, $data);
        $action->execute($admin, $data);
    }
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    expect($report['sales_cents'])->toBe(30000);
    expect($report['collections_cents'])->toBe(30000);
    expect($report['rows'][1])->toMatchArray(['operations' => 1, 'sales_cents' => 10000]);
    expect($report['rows'][2])->toMatchArray(['operations' => 1, 'sales_cents' => 20000]);
    expect($report['balances'][0]['amount_cents'])->toBe(31000);
});

it('C351 reconciles actual loan payments renewals and annulments without treating disbursement as a sale', function () {
    $admin = c351Admin();
    test()->seed(LoanPermissionsSeeder::class);
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $data = ['ci' => '111111', 'name' => 'Cliente de prueba', 'phone' => '70000000', 'type_id' => $type->id, 'type_revision' => $type->revision, 'description' => 'Garrafa', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-30', 'delivery_confirmed' => true];
    $register = app(RegisterLoanAction::class);
    $loan = $register->execute($admin, $data + ['request_key' => (string) Str::uuid()]);
    $settle = app(SettleLoanAction::class);
    $payment = ['expected_revision' => 0, 'expected_paid_cents' => 21500, 'payment_confirmed' => true, 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()];
    $settle->execute($admin, $loan->id, $payment);
    $settle->execute($admin, $loan->id, $payment);
    $second = $register->execute($admin, $data + ['request_key' => (string) Str::uuid()]);
    $renew = app(ChangeLoanTermAction::class);
    $renewal = ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'expected_paid_cents' => 1500, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()];
    $renew->execute($admin, $second->id, $renewal);
    $renew->execute($admin, $second->id, $renewal);
    $third = $register->execute($admin, $data + ['request_key' => (string) Str::uuid()]);
    app(AnnulLoanAction::class)->execute($admin, $third->id, ['capital_returned' => true, 'request_key' => (string) Str::uuid()]);
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    expect($report['sales_cents'])->toBe(0);
    expect($report['collections_cents'])->toBe(23000);
    expect($report['balances'][1]['amount_cents'])->toBe(83000);
    expect(Loan::count())->toBe(3);
});

it('C351 excludes international custody from usable box balances without changing held money', function () {
    $admin = c351Admin();
    $session = c351Session($admin);
    $policy = ExchangePolicy::create(['created_by' => $admin->id, 'base_rate' => 1000, 'sell_rate' => 1100, 'buy_rate' => 900, 'cost_source' => 'Prueba', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    $id = DB::table('international_transfers')->insertGetId(['cash_box_id' => $session->cash_box_id, 'created_in_session_id' => $session->id, 'requested_by' => $admin->id, 'exchange_policy_id' => $policy->id, 'direction' => 'OUT', 'status' => 'PENDIENTE', 'component' => 'cash', 'sender_name' => 'Prueba', 'sender_whatsapp' => '70000000', 'recipient_name' => 'Prueba', 'quote' => '{}', 'bob_cents' => 3000, 'brl_cents' => 1000, 'principal_cents' => 3000, 'margin_cents' => 0, 'local_held_cents' => 3000, 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(), 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]);
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    $box = collect($report['balances'])->first(fn ($row) => str_starts_with($row['label'], 'Cajas ·'));
    expect($box['amount_cents'])->toBe(7500);
    expect(DB::table('international_transfers')->where('id', $id)->value('local_held_cents'))->toBe(3000);
    expect($session->fresh()->current_balances)->toBe(['cash' => 10500]);
});

it('C351 reads deposits and reversals through their fund balance without counting them as customer collections', function () {
    $admin = c351Admin();
    test()->seed([AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    $account = CardDepositAccount::create(['name' => 'Prueba', 'bank' => 'Banco', 'holder' => 'Prueba', 'number' => '123', 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $fund = CommercialFund::create(['code' => 'GENERAL', 'balance_cents' => 10000, 'revision' => 0]);
    $deposit = app(RecordAccessoryCashDepositAction::class)->execute($admin, ['account_id' => $account->id, 'account_revision' => 1, 'expected_revision' => 0, 'amount' => '40', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $query = app(DailyReportQuery::class);
    expect($query->execute($admin, '2026-10-30')['balances'][2]['amount_cents'])->toBe(6000);
    app(VoidAccessoryCashDepositAction::class)->execute($admin, ['deposit_id' => $deposit->id, 'expected_revision' => $fund->fresh()->revision, 'reason' => 'Error de prueba', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $report = $query->execute($admin, '2026-10-30');
    expect($report['sales_cents'])->toBe(0);
    expect($report['collections_cents'])->toBe(0);
    expect($report['balances'][2]['amount_cents'])->toBe(10000);
});

it('C351 never advertises a released session with an unrecognized status as available', function () {
    $admin = c351Admin();
    c351Session($admin, ['status' => 'RETIRADA', 'released_at' => now(), 'handover_balances' => ['cash' => 10500]]);
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    expect(collect($report['balances'])->last()['amount_cents'])->toBeNull();
});

it('C351 uses the daily date indexes rather than scanning all history', function () {
    c351Admin();
    foreach (['card_sales', 'entel_recharges', 'accessory_sales', 'loan_movements', 'cash_box_operations'] as $table) {
        $where = $table === 'cash_box_operations' ? "status = 'VIGENTE' AND " : '';
        $plan = DB::select('EXPLAIN QUERY PLAN SELECT id FROM '.$table.' WHERE '.$where.'created_at >= ? AND created_at < ?', ['2026-10-30 04:00:00', '2026-10-31 04:00:00']);
        expect(collect($plan)->pluck('detail')->implode(' '))->toContain('SEARCH', 'reports_'.$table.'_day_index');
    }
});

it('C351 includes a released closed box and then excludes it when deactivated', function () {
    $admin = c351Admin();
    $session = c351Session($admin, ['status' => 'CERRADA_CON_INCIDENCIAS', 'active_slot' => null, 'released_at' => now(), 'handover_balances' => ['cash' => 10500]]);
    $query = app(DailyReportQuery::class);
    expect(collect($query->execute($admin, '2026-10-30')['balances'])->last()['amount_cents'])->toBe(10500);
    $session->box->update(['is_active' => false]);
    expect(collect($query->execute($admin, '2026-10-30')['balances'])->last()['amount_cents'])->toBeNull();
});

it('C351 escapes names and locks the applied period against client changes', function () {
    $admin = c351Admin();
    $session = c351Session($admin);
    $session->box->update(['name' => '<script>private()</script>']);
    test()->actingAs($admin);
    Livewire::test(DailyReport::class)->assertSee('&lt;script&gt;private()&lt;/script&gt;', false)->assertDontSee('<script>private()</script>', false);
    expect(fn () => Livewire::test(DailyReport::class)->set('appliedDate', '2026-10-01'))->toThrow(CannotUpdateLockedPropertyException::class);
});
