<?php

use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
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
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function loanFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'America/La_Paz'));
    test()->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);

    return [$admin, $cashier, $type];
}
function loanData($type, int $days = 15): array
{
    return ['ci' => '00123 LP', 'name' => 'Cliente prueba', 'phone' => '70000000', 'type_id' => $type->id, 'type_revision' => $type->revision, 'description' => 'Garrafa azul', 'condition' => 'Usada en buen estado', 'capital' => '200', 'days' => $days, 'start_date' => '2026-10-01', 'delivery_method' => 'CASH', 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()];
}
it('C209 stores the chosen term money date and cashier once and reads that snapshot later', function (int $days, int $interest, string $due) {
    [$admin,$cashier,$type] = loanFixture();
    $data = loanData($type, $days);
    $action = app(RegisterLoanAction::class);
    $loan = $action->execute($cashier, $data);
    expect($action->execute($cashier, $data)->id)->toBe($loan->id);
    expect($loan->original_days)->toBe($days)->and($loan->original_interest_cents)->toBe($interest)->and($loan->original_due_date->format('Y-m-d'))->toBe($due)->and($loan->user_id)->toBe($cashier->id);
    expect(Loan::count())->toBe(1)->and(Borrower::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(80000)->and(LoanMovement::where('kind', 'DISBURSEMENT')->count())->toBe(1);
    $before = app(LoanQuery::class)->detail($cashier, $loan->id);
    app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa nueva', 'rate' => '20', 'is_active' => true, 'expected_revision' => $type->revision, 'request_key' => (string) Str::uuid()], $type);
    expect(app(LoanQuery::class)->detail($cashier, $loan->id))->toBe($before);
    expect(fn () => $action->execute($cashier, array_replace($data, ['days' => $days === 15 ? 30 : 15])))->toThrow(ValidationException::class);
})->with([[15, 1500, '2026-10-15'], [30, 3000, '2026-10-30']]);
it('C209 refuses insufficient funds unconfirmed delivery and stale tariffs without orphan clients', function () {
    [$admin,$cashier,$type] = loanFixture();
    $action = app(RegisterLoanAction::class);
    foreach ([['capital' => '1001'], ['delivery_confirmed' => false], ['type_revision' => 999], ['days' => 31]] as $change) {
        expect(fn () => $action->execute($cashier, array_replace(loanData($type), $change)))->toThrow(ValidationException::class);
    }
    expect(Borrower::count())->toBe(0)->and(Loan::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});
it('C209 records real funding withdrawals and permission restrictions atomically', function () {
    [$admin,$cashier,$type] = loanFixture();
    $act = app(ManageLoanFundAction::class);
    $data = ['kind' => 'IN', 'amount' => '2000', 'confirmed' => true, 'reference' => 'Aporte real', 'request_key' => (string) Str::uuid()];
    $act->execute($admin, $data);
    $act->execute($admin, $data);
    expect(LoanFund::sole()->balance_cents)->toBe(300000);
    expect(fn () => $act->execute($cashier, $data))->toThrow(AuthorizationException::class);
    expect(fn () => $act->execute($admin, ['kind' => 'OUT', 'amount' => '3001', 'confirmed' => true, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(fn () => app(SaveLoanTypeAction::class)->execute($cashier, ['name' => 'Nuevo', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
});
it('C209 rolls back all disbursement records on interruption and uses the latest remaining funds', function () {
    [$admin,$cashier,$type] = loanFixture();
    $action = app(RegisterLoanAction::class);
    $data = loanData($type);
    DB::statement("CREATE TRIGGER fail_loan BEFORE INSERT ON loan_commands WHEN NEW.kind = 'REGISTER' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    expect(fn () => $action->execute($cashier, $data))->toThrow(QueryException::class);
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
    DB::statement('DROP TRIGGER fail_loan');
    $action->execute($cashier, array_replace($data, ['capital' => '800']));
    expect(fn () => $action->execute($cashier, array_replace(loanData($type), ['capital' => '201'])))->toThrow(ValidationException::class);
    expect(LoanFund::sole()->balance_cents)->toBe(20000);
});

it('C220 keeps manual interest for the loaded period and renews at the original type rate', function () {
    [$admin,$cashier,$type] = loanFixture();
    $data = array_replace(loanData($type), ['start_date' => '2025-10-01', 'schedule_mode' => 'MANUAL', 'due_date' => '2026-10-01', 'interest_mode' => 'MANUAL', 'manual_interest' => '27.50']);
    $loan = app(RegisterLoanAction::class)->execute($admin, $data);
    expect($loan->original_days)->toBe(366)->and($loan->original_interest_cents)->toBe(2750)->and(LoanFund::sole()->balance_cents)->toBe(80000);
    app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa actualizada', 'rate' => '20', 'is_active' => true, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()], $type);
    $event = app(ChangeLoanTermAction::class)->execute($admin, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect($event->summary['paid_cents'])->toBe(2750)->and($event->summary['pending_cents'])->toBe(1500)->and($event->summary['rate_bps'])->toBe(1500)->and(Loan::count())->toBe(1);
    $extension = app(ChangeLoanTermAction::class)->execute($admin, $loan->id, ['kind' => 'EXTEND', 'days' => 30, 'periods' => 1, 'expected_revision' => $event->id, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect($extension->summary['paid_cents'])->toBe(1500);
});
it('C220 prevents unauthorized manual terms even through a forged request', function () {
    [$admin,$cashier,$type] = loanFixture();
    $cashier->restrictedPermissions()->attach(Permission::findByName('prestamos.condiciones.manuales'));
    $data = array_replace(loanData($type), ['schedule_mode' => 'MANUAL', 'due_date' => '2026-10-15', 'interest_mode' => 'MANUAL', 'manual_interest' => '1']);
    expect(fn () => app(RegisterLoanAction::class)->execute($cashier, $data))->toThrow(AuthorizationException::class);
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});
it('C220 refuses invalid manual dates and undefined automatic long term rates without side effects', function (array $change) {
    [$admin,$cashier,$type] = loanFixture();
    $data = array_replace(loanData($type), ['schedule_mode' => 'MANUAL', 'due_date' => '2026-10-15', 'interest_mode' => 'MANUAL', 'manual_interest' => '15'], $change);
    expect(fn () => app(RegisterLoanAction::class)->execute($admin, $data))->toThrow(ValidationException::class);
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
})->with([[['due_date' => '2026-09-30']], [['due_date' => '2026-12-01', 'interest_mode' => 'AUTO']], [['manual_interest' => '-1']]]);
