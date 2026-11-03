<?php

use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\RegisterLoanObjectExpenseAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Actions\SellLoanObjectAction;
use App\Domain\Prestamos\Actions\SettleLoanAction;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanObjectExpense;
use App\Domain\Prestamos\Models\LoanObjectSale;
use App\Domain\Prestamos\Models\LoanTermChange;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function recoveryFixture(int $days = 30): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'America/La_Paz'));
    test()->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Celular', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $loan = app(RegisterLoanAction::class)->execute($cashier, ['ci' => 'RECUPERACION-PRUEBA', 'name' => 'Cliente de prueba', 'type_id' => $type->id, 'type_revision' => 1, 'description' => 'Equipo con pantalla dañada', 'capital' => '200', 'days' => $days, 'start_date' => '2026-10-01', 'request_key' => (string) Str::uuid()]);

    return [$admin, $cashier, $loan];
}
function recoveryExpense(): array
{
    return ['amount' => '200', 'description' => 'Cambio de pantalla de prueba', 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()];
}
function recoverySale(string $price = '600', int $expenses = 20000): array
{
    return ['price' => $price, 'expected_expense_cents' => $expenses, 'payment_confirmed' => true, 'buyer' => 'Comprador de prueba', 'reference' => 'VENTA-PRUEBA', 'request_key' => (string) Str::uuid()];
}

it('C237 charges one fixed penalty during days one through seven and renews from the saved due date', function (int $day) {
    [$admin, $cashier, $loan] = recoveryFixture(15);
    $this->travelTo(CarbonImmutable::parse('2026-10-'.$day.' 12:00', 'America/La_Paz'));
    $data = ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'expected_paid_cents' => 5500, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()];
    $event = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $data);
    expect($event->summary['late_fee_cents'])->toBe(4000)->and($event->summary['paid_cents'])->toBe(5500)->and($event->summary['due_date'])->toBe('2026-10-30')->and($event->summary['pending_cents'])->toBe(1500);
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $data);
    expect(LoanFund::sole()->balance_cents)->toBe(85500)->and(LoanTermChange::count())->toBe(1);
})->with([16, 22]);

it('C237 refuses a stale zero-penalty quote and ordinary payments after day eight', function () {
    [$admin, $cashier, $loan] = recoveryFixture(15);
    $data = ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'expected_paid_cents' => 1500, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()];
    $this->travelTo(CarbonImmutable::parse('2026-10-16 00:00', 'America/La_Paz'));
    expect(fn () => app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $data))->toThrow(ValidationException::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-23 00:00', 'America/La_Paz'));
    $data['expected_paid_cents'] = 5500;
    expect(fn () => app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $data))->toThrow(ValidationException::class);
    expect(fn () => app(SettleLoanAction::class)->execute($cashier, $loan->id, ['expected_revision' => 0, 'expected_paid_cents' => 25500, 'payment_confirmed' => true, 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(LoanFund::sole()->balance_cents)->toBe(80000);
});

it('C237 accepts a full late payment with a single penalty', function () {
    [$admin, $cashier, $loan] = recoveryFixture(15);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 23:59:59', 'America/La_Paz'));
    $event = app(SettleLoanAction::class)->execute($cashier, $loan->id, ['expected_revision' => 0, 'expected_paid_cents' => 25500, 'payment_confirmed' => true, 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect($event->summary['late_fee_cents'])->toBe(4000)->and(LoanFund::sole()->balance_cents)->toBe(105500)->and($loan->fresh()->status)->toBe('RECOGIDO_PAGADO');
});

it('C237 records repair spending and a single profitable sale or minimum-price sale', function (string $price, int $saleProfit, int $balance) {
    [$admin, $cashier, $loan] = recoveryFixture();
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00', 'America/La_Paz'));
    $expenseData = recoveryExpense();
    $expense = app(RegisterLoanObjectExpenseAction::class)->execute($admin, $loan->id, $expenseData);
    app(RegisterLoanObjectExpenseAction::class)->execute($admin, $loan->id, $expenseData);
    expect(LoanFund::sole()->balance_cents)->toBe(60000)->and(LoanObjectExpense::count())->toBe(1)->and($loan->fresh()->capital_cents)->toBe(20000)->and($loan->fresh()->status)->toBe('PERDIDA_TOTAL');
    expect(fn () => app(SellLoanObjectAction::class)->execute($cashier, $loan->id, recoverySale('469.99')))->toThrow(ValidationException::class);
    $data = recoverySale($price);
    $sale = app(SellLoanObjectAction::class)->execute($cashier, $loan->id, $data);
    expect(app(SellLoanObjectAction::class)->execute($cashier, $loan->id, $data)->id)->toBe($sale->id)->and($sale->summary['minimum_cents'])->toBe(47000)->and($sale->summary['sale_profit_cents'])->toBe($saleProfit)->and($sale->summary['loan_profit_cents'])->toBe(7000)->and(LoanFund::sole()->balance_cents)->toBe($balance)->and($loan->fresh()->status)->toBe('VENDIDO');
    expect(LoanMovement::where('kind', 'OBJECT_SALE')->count())->toBe(1);
    expect(fn () => app(RegisterLoanObjectExpenseAction::class)->execute($admin, $loan->id, recoveryExpense()))->toThrow(ValidationException::class);
    expect(fn () => app(SellLoanObjectAction::class)->execute($cashier, $loan->id, recoverySale($price)))->toThrow(ValidationException::class);
    expect(app(LoanQuery::class)->detail($cashier, $loan->id)['sale'])->not->toHaveKey('sale_profit_cents');
    expect(app(LoanQuery::class)->detail($admin, $loan->id)['sale']['sale_profit_cents'])->toBe($saleProfit);
})->with([['600', 13000, 120000], ['470', 0, 107000]]);

it('C237 refuses unauthorized repair costs insufficient funds and sales before total loss', function () {
    [$admin, $cashier, $loan] = recoveryFixture();
    expect(fn () => app(SellLoanObjectAction::class)->execute($cashier, $loan->id, recoverySale('600', 0)))->toThrow(ValidationException::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00', 'America/La_Paz'));
    expect(fn () => app(RegisterLoanObjectExpenseAction::class)->execute($cashier, $loan->id, recoveryExpense()))->toThrow(AuthorizationException::class);
    expect(fn () => app(RegisterLoanObjectExpenseAction::class)->execute($admin, $loan->id, array_replace(recoveryExpense(), ['amount' => '801'])))->toThrow(ValidationException::class);
    expect(LoanFund::sole()->balance_cents)->toBe(80000)->and(LoanObjectExpense::count())->toBe(0)->and(LoanObjectSale::count())->toBe(0);
});

it('C237 rolls back interrupted expenses and sales then retries without duplicating financial movements', function () {
    [$admin, $cashier, $loan] = recoveryFixture();
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00', 'America/La_Paz'));
    $expense = recoveryExpense();
    DB::statement("CREATE TRIGGER fail_expense BEFORE INSERT ON loan_commands WHEN NEW.kind = 'OBJECT_EXPENSE' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    expect(fn () => app(RegisterLoanObjectExpenseAction::class)->execute($admin, $loan->id, $expense))->toThrow(QueryException::class);
    expect(LoanObjectExpense::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(80000)->and($loan->fresh()->status)->toBe('ACTIVO');
    DB::statement('DROP TRIGGER fail_expense');
    app(RegisterLoanObjectExpenseAction::class)->execute($admin, $loan->id, $expense);
    $sale = recoverySale();
    DB::statement("CREATE TRIGGER fail_sale BEFORE INSERT ON loan_commands WHEN NEW.kind = 'OBJECT_SALE' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    expect(fn () => app(SellLoanObjectAction::class)->execute($cashier, $loan->id, $sale))->toThrow(QueryException::class);
    expect(LoanObjectSale::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(60000)->and($loan->fresh()->status)->toBe('PERDIDA_TOTAL');
    DB::statement('DROP TRIGGER fail_sale');
    app(SellLoanObjectAction::class)->execute($cashier, $loan->id, $sale);
    expect(LoanFund::sole()->balance_cents)->toBe(120000)->and(LoanObjectSale::count())->toBe(1);
});

it('C237 applies only one penalty to multiple renewal periods and preserves the contractual fee in a later delay', function () {
    [$admin, $cashier, $loan] = recoveryFixture();
    config(['prestamos.receipt.late_fee_cents' => 5000]);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00', 'America/La_Paz'));
    $data = ['kind' => 'RENEW', 'days' => 30, 'periods' => 4, 'expected_revision' => 0, 'expected_paid_cents' => 16000, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()];
    $event = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $data);
    expect($event->summary['late_fee_cents'])->toBe(4000)->and($event->summary['interest_paid_cents'])->toBe(12000)->and($event->summary['due_date'])->toBe('2027-02-27');
    $this->travelTo(CarbonImmutable::parse('2027-02-28 12:00', 'America/La_Paz'));
    $data = array_replace($data, ['periods' => 1, 'expected_revision' => $event->id, 'expected_paid_cents' => 7000, 'request_key' => (string) Str::uuid()]);
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $data);
    expect(LoanFund::sole()->balance_cents)->toBe(103000)->and(LoanTermChange::count())->toBe(2);
});

it('C237 requires a new sale review after an additional repair and retains the object in lost counters', function () {
    [$admin, $cashier, $loan] = recoveryFixture();
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00', 'America/La_Paz'));
    $quote = recoverySale('600', 0);
    app(RegisterLoanObjectExpenseAction::class)->execute($admin, $loan->id, recoveryExpense());
    expect(app(LoanQuery::class)->summary($cashier)['lost'])->toBe(1);
    expect(app(LoanQuery::class)->filterStatus(app(LoanQuery::class)->loans($cashier), 'PERDIDA_TOTAL')->count())->toBe(1);
    expect(fn () => app(SellLoanObjectAction::class)->execute($cashier, $loan->id, $quote))->toThrow(ValidationException::class);
    expect(LoanFund::sole()->balance_cents)->toBe(60000)->and(LoanObjectSale::count())->toBe(0);
});

it('C237 sells an unrepaired lost object without inventing expenses or changing its original capital', function (string $price, int $saleProfit, int $balance) {
    [$admin, $cashier, $loan] = recoveryFixture();
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00', 'America/La_Paz'));
    $sale = app(SellLoanObjectAction::class)->execute($cashier, $loan->id, recoverySale($price, 0));
    expect($sale->summary['minimum_cents'])->toBe(27000)->and($sale->summary['expense_cents'])->toBe(0)->and($sale->summary['sale_profit_cents'])->toBe($saleProfit)->and(LoanFund::sole()->balance_cents)->toBe($balance)->and(LoanObjectExpense::count())->toBe(0)->and($loan->fresh()->capital_cents)->toBe(20000);
})->with([['270', 0, 107000], ['500', 23000, 130000]]);
