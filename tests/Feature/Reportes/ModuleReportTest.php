<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\RegisterLoanObjectExpenseAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Actions\SellLoanObjectAction;
use App\Domain\Prestamos\Actions\SettleLoanAction;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Queries\ModuleReportQuery;
use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Models\CardSaleLine;
use App\Filament\Pages\Reportes\AccessoryReport;
use App\Filament\Pages\Reportes\CardReport;
use App\Filament\Pages\Reportes\CashBoxReport;
use App\Filament\Pages\Reportes\LoanReport;
use App\Filament\Widgets\Reportes\ModuleReportChart;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c368Admin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-11-01 16:00:00', 'UTC'));
    Http::preventStrayRequests();
    test()->seed(UsuariosPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');

    return $admin;
}
function c368Mixed(User $user): array
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => 'Cajera de prueba', 'payment_method' => 'CASH', 'quantity' => 3, 'total_cents' => 7200, 'cost_cents' => 5000, 'pricing_revision' => 1, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => '2026-10-15 16:00:00']);
    $lines = [];
    foreach ([['ACCESSORY', 2, 1200, 600, 150], ['PHONE', 1, 6000, 4400, 300]] as [$kind, $quantity, $total, $cost, $commission]) {
        $line = AccessorySaleLine::create(['sale_id' => $sale->id, 'kind' => $kind, 'product_name' => 'Producto '.$kind, 'product_code' => 'PRUEBA', 'quantity' => $quantity, 'unit_price_cents' => intdiv($total, $quantity), 'total_cents' => $total, 'cost_cents' => $cost, 'previous_revision' => 0, 'before_quantity' => $quantity, 'after_quantity' => 0, 'before_value_cents' => $cost, 'after_value_cents' => 0]);
        CommissionEntry::create(['sale_line_id' => $line->id, 'user_id' => $user->id, 'actor_name' => $user->name, 'amount_cents' => $commission, 'snapshot' => [], 'created_at' => now()]);
        $lines[] = $line;
    }

    return $lines;
}
function c368LoanReceipt(User $admin, string $date, string $kind = 'TERM_PAYMENT', int $amount = 100): void
{
    LoanMovement::create(['user_id' => $admin->id, 'kind' => $kind, 'delta_cents' => $amount, 'before_cents' => 0, 'after_cents' => max(0, $amount), 'created_at' => $date]);
}

it('C368 separates module totals without duplicating a mixed ticket or treating undocumented cost as zero', function () {
    $admin = c368Admin();
    c368Mixed($admin);
    c368LoanReceipt($admin, '2026-10-15 16:00:00', amount: 1500);
    $query = app(ModuleReportQuery::class);
    $accessories = $query->execute($admin, 'ACCESSORIES', '2026-10');
    expect($accessories['collections_cents'])->toBe(7200)->and($accessories['margin']['known_cents'])->toBe(2200)
        ->and($accessories['margin']['commissions_cents'])->toBe(450)->and($accessories['margin']['after_commissions_cents'])->toBe(1750);
    expect($accessories['rows']['ACCESSORY']['collections_cents'])->toBe(1200)->and($accessories['rows']['PHONE']['collections_cents'])->toBe(6000);
    expect(array_sum(array_column($accessories['trend'], 'collections_cents')))->toBe(7200);
    $loan = $query->execute($admin, 'LOANS', '2026-10');
    expect($loan['collections_cents'])->toBe(1500)->and($loan['margin']['known_cents'])->toBe(0)->and($loan['margin']['pending'])->toBe(1);
    expect($query->execute($admin, 'CARDS', '2026-10')['collections_cents'])->toBe(0);
});

it('C368 counts commission accrual once and ignores later commission payments as a second expense', function () {
    $admin = c368Admin();
    c368Mixed($admin);
    DB::table('commission_payments')->insert(['user_id' => $admin->id, 'payee_name' => $admin->name, 'registered_by_user_id' => $admin->id, 'registered_by_name' => $admin->name, 'source' => 'ADMINISTRATION', 'amount_cents' => 200, 'earned_cents' => 450, 'before_paid_cents' => 0, 'before_pending_cents' => 450, 'after_pending_cents' => 250, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => '2026-10-16 16:00:00']);
    $result = app(ModuleReportQuery::class)->execute($admin, 'ACCESSORIES', '2026-10');
    expect($result['margin']['after_commissions_cents'])->toBe(1750)->and($result['collections_cents'])->toBe(7200);
});

it('C368 reports historical cards as pending and uses only frozen cost for new sales', function () {
    $admin = c368Admin();
    test()->seed([TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    test()->travelTo(CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'));
    $p = app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.40', 'normal_price' => '10', 'wholesale_price' => '9.50', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '10', 'stocks' => [['product_id' => $p->id, 'quantity' => 10, 'unit_cost' => '9.40']], 'request_key' => (string) Str::uuid()]);
    app(RecordCardSaleAction::class)->execute($admin, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()]);
    $result = app(ModuleReportQuery::class)->execute($admin, 'CARDS', '2026-10');
    expect($result['collections_cents'])->toBe(2000)->and($result['margin']['known_cents'])->toBe(120)->and($result['margin']['pending'])->toBe(0);
    $p->update(['purchase_price_cents' => 9999]);
    expect(app(ModuleReportQuery::class)->execute($admin, 'CARDS', '2026-10')['margin']['known_cents'])->toBe(120);
    expect(CardSaleLine::count())->toBe(1);
});

it('C368 uses Bolivia month boundaries and compares with the previous complete month', function () {
    $admin = c368Admin();
    foreach ([['2026-10-01 03:59:59', 700], ['2026-10-01 04:00:00', 100], ['2026-11-01 03:59:59', 200], ['2026-11-01 04:00:00', 900]] as [$date, $amount]) {
        c368LoanReceipt($admin, $date, amount: $amount);
    }
    $r = app(ModuleReportQuery::class)->execute($admin, 'LOANS', '2026-10');
    expect($r['collections_cents'])->toBe(300)->and($r['comparison']['collections']['previous_cents'])->toBe(700)
        ->and($r['comparison']['collections']['difference_cents'])->toBe(-400);
    expect($r['trend'][0]['collections_cents'])->toBe(100)->and($r['trend'][30]['collections_cents'])->toBe(200)->and($r['trend'])->toHaveCount(31);
});

it('C368 separates actual interest receipts and recovered loan principal after renewal and settlement', function () {
    $admin = c368Admin();
    test()->seed(LoanPermissionsSeeder::class);
    test()->travelTo(CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'));
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $loan = app(RegisterLoanAction::class)->execute($admin, ['ci' => '1234567', 'name' => 'Cliente de prueba', 'phone' => '70000000', 'type_id' => $type->id, 'type_revision' => $type->revision, 'description' => 'Garrafa', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-15', 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $change = app(ChangeLoanTermAction::class)->execute($admin, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'expected_paid_cents' => 1500, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    app(SettleLoanAction::class)->execute($admin, $loan->id, ['expected_revision' => $change->id, 'expected_paid_cents' => 21500, 'payment_confirmed' => true, 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $r = app(ModuleReportQuery::class)->execute($admin, 'LOANS', '2026-10');
    expect($r['collections_cents'])->toBe(23000)->and($r['margin']['known_cents'])->toBe(3000)->and($r['margin']['capital_recovered_cents'])->toBe(20000)->and($r['margin']['pending'])->toBe(0);
});

it('C368 opens each administrative panel with native charts and validated month selection', function (string $page) {
    $admin = c368Admin();
    test()->actingAs($admin);
    Livewire::test($page)->assertSee('Mes a consultar')->assertSee('Evolución diaria del mes')->assertSee('Distribución de cobros')
        ->set('month', '2026-12')->call('applyMonth')->assertHasErrors(['month'])->assertSet('appliedMonth', '2026-11')
        ->set('month', '2026-10')->call('applyMonth')->assertHasNoErrors()->assertSet('appliedMonth', '2026-10');
})->with([CashBoxReport::class, CardReport::class, LoanReport::class, AccessoryReport::class]);

it('C368 blocks cashier manager and inactive administrator on every new report', function () {
    $admin = c368Admin();
    foreach (['cajera', 'encargado'] as $role) {
        $u = User::factory()->create();
        $u->assignRole($role);
        test()->actingAs($u);
        foreach ([CashBoxReport::class, CardReport::class, LoanReport::class, AccessoryReport::class] as $page) {
            test()->get($page::getUrl())->assertForbidden();
        }
        expect(fn () => app(ModuleReportQuery::class)->execute($u, 'CARDS', '2026-10'))->toThrow(AuthorizationException::class);
    }
    User::whereKey($admin->id)->update(['is_active' => false]);
    expect(fn () => app(ModuleReportQuery::class)->execute($admin, 'CARDS', '2026-10'))->toThrow(AuthorizationException::class);
});

it('C368 preserves paginated detail totals and refuses another module source in the modal', function () {
    $admin = c368Admin();
    test()->actingAs($admin);
    for ($i = 0; $i < 7; $i++) {
        c368LoanReceipt($admin, '2026-10-15 16:00:00', amount: 100);
    }
    $q = app(ModuleReportQuery::class);
    $one = $q->detail($admin, 'LOANS', '2026-10', 'TERM_PAYMENT');
    $two = $q->detail($admin, 'LOANS', '2026-10', 'TERM_PAYMENT', 2);
    expect($one['records']->count())->toBe(5)->and($two['records']->count())->toBe(2)->and($one['total_cents'])->toBe(700)->and($two['total_cents'])->toBe(700);
    Livewire::test(LoanReport::class)->set('month', '2026-10')->call('applyMonth')->call('showDetails', 'TERM_PAYMENT')->assertSet('detailOpen', true)->assertSee('Bs 7');
    Livewire::test(CardReport::class)->call('showDetails', 'TERM_PAYMENT')->assertForbidden();
});

it('C368 locks applied months and chart data against client alteration', function () {
    $admin = c368Admin();
    test()->actingAs($admin);
    expect(fn () => Livewire::test(CardReport::class)->set('appliedMonth', '2026-01'))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(ModuleReportChart::class, ['chartData' => ['labels' => [], 'datasets' => []]])->set('chartData', ['forged' => 1]))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('C368 report reads never write operations snapshots or messages and do not poll', function () {
    $admin = c368Admin();
    test()->actingAs($admin);
    c368Mixed($admin);
    DB::flushQueryLog();
    DB::enableQueryLog();
    foreach (['BOXES', 'CARDS', 'LOANS', 'ACCESSORIES'] as $module) {
        app(ModuleReportQuery::class)->execute($admin, $module, '2026-10');
    }
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->contains(fn ($row) => preg_match('/^\\s*(insert|update|delete|replace|alter|create|drop)\\b/i', $row['query'])))->toBeFalse();
    $html = Livewire::test(AccessoryReport::class)->html();
    expect($html)->not->toContain('wire:poll')->and(DB::table('report_monthly_closes')->count())->toBe(0)->and(DB::table('report_exports')->count())->toBe(0);
    Http::assertNothingSent();
});

function c368Loan(User $admin): Loan
{
    test()->seed(LoanPermissionsSeeder::class);
    test()->travelTo(CarbonImmutable::parse('2026-10-01 16:00:00', 'UTC'));
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);

    return app(RegisterLoanAction::class)->execute($admin, ['ci' => '1234567', 'name' => 'Cliente de prueba', 'phone' => '70000000', 'type_id' => $type->id, 'type_revision' => $type->revision, 'description' => 'Garrafa', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()]);
}

it('C368 counts the fixed late fee once with interest and keeps principal out of the result', function () {
    $admin = c368Admin();
    $loan = c368Loan($admin);
    test()->travelTo(CarbonImmutable::parse('2026-10-16 16:00:00', 'UTC'));
    $change = app(ChangeLoanTermAction::class)->execute($admin, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'expected_paid_cents' => 5500, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    app(SettleLoanAction::class)->execute($admin, $loan->id, ['expected_revision' => $change->id, 'expected_paid_cents' => 21500, 'payment_confirmed' => true, 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $report = app(ModuleReportQuery::class)->execute($admin, 'LOANS', '2026-10');
    expect($report['collections_cents'])->toBe(27000)->and($report['margin']['known_cents'])->toBe(7000)->and($report['margin']['capital_recovered_cents'])->toBe(20000);
});

it('C368 uses the saved result of a sold lost object without deducting its repair twice', function () {
    $admin = c368Admin();
    $loan = c368Loan($admin);
    test()->travelTo(CarbonImmutable::parse('2026-10-24 16:00:00', 'UTC'));
    app(RegisterLoanObjectExpenseAction::class)->execute($admin, $loan->id, ['amount' => '20', 'description' => 'Reparación de prueba', 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    app(SellLoanObjectAction::class)->execute($admin, $loan->id, ['price' => '300', 'expected_expense_cents' => 2000, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $report = app(ModuleReportQuery::class)->execute($admin, 'LOANS', '2026-10');
    expect($report['collections_cents'])->toBe(30000)->and($report['margin']['known_cents'])->toBe(8000)->and($report['margin']['capital_recovered_cents'])->toBe(20000)->and($report['margin']['pending'])->toBe(0);
});

it('C368 shows only valid box fees and distributes collections by box without counting transferred amounts', function () {
    $admin = c368Admin();
    foreach ([100, 200] as $i => $fee) {
        $box = CashBox::factory()->create(['created_by' => $admin->id, 'name' => 'Caja '.($i + 1)]);
        $config = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $admin->id, 'base_capital_cents' => 10000, 'accounts' => ['cash' => ['name' => 'Efectivo', 'initial_cents' => 10000]], 'shifts' => [], 'reason' => 'Prueba', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
        $session = CashBoxSession::create(['cash_box_id' => $box->id, 'user_id' => $admin->id, 'configuration_id' => $config->id, 'status' => 'ABIERTA', 'active_slot' => 1, 'base_capital_cents' => 10000, 'opening_balances' => ['cash' => 10000], 'expected_balances' => ['cash' => 10000], 'current_balances' => ['cash' => 10000], 'comparison' => [], 'opened_at' => now(), 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]);
        foreach (['VIGENTE', 'ANULADA'] as $status) {
            CashBoxOperation::create(['cash_box_session_id' => $session->id, 'user_id' => $admin->id, 'amount_cents' => 999999, 'fee_type' => 'ACH', 'fee_cents' => $fee, 'suggested_fee_cents' => $fee, 'fee_component' => 'cash', 'deltas' => ['cash' => $fee], 'status' => $status, 'created_at' => '2026-10-15 16:00:00']);
        }
    }
    $r = app(ModuleReportQuery::class)->execute($admin, 'BOXES', '2026-10');
    expect($r['collections_cents'])->toBe(300)->and($r['margin']['known_cents'])->toBe(300)->and($r['operations'])->toBe(2)
        ->and($r['charts']['distribution']['labels'])->toBe(['Caja 1', 'Caja 2'])->and($r['charts']['distribution']['datasets'][0]['data'])->toEqual([1, 2]);
});
