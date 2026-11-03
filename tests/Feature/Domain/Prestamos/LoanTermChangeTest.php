<?php

use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Actions\SettleLoanAction;
use App\Domain\Prestamos\Calculators\LoanTermCalculator;
use App\Domain\Prestamos\Data\LoanRenewalHistoryData;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanSettlement;
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
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function termFixture(): array
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
    $loan = app(RegisterLoanAction::class)->execute($cashier, ['ci' => 'TERM-01', 'name' => 'Cliente prueba', 'type_id' => $type->id, 'type_revision' => 1, 'description' => 'Garrafa', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'delivery_method' => 'CASH', 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    test()->travelTo(CarbonImmutable::parse('2026-10-13 12:00', 'America/La_Paz'));

    return [$admin, $cashier, $loan, $type];
}
function termData(string $kind = 'RENEW', int $days = 15, int $revision = 0): array
{
    return ['kind' => $kind, 'days' => $days, 'periods' => 1, 'expected_revision' => $revision, 'payment_confirmed' => true, 'reference' => '', 'request_key' => (string) Str::uuid()];
}
it('C210 renews at day thirteen paying the old interest and preserves the selected new period', function (int $days, string $due, int $next) {
    [$admin,$cashier,$loan,$type] = termFixture();
    $data = termData('RENEW', $days);
    $act = app(ChangeLoanTermAction::class);
    $event = $act->execute($cashier, $loan->id, $data);
    $again = $act->execute($cashier, $loan->id, $data);
    expect($again->id)->toBe($event->id)->and($event->summary['paid_cents'])->toBe(1500)->and($event->summary['pending_cents'])->toBe($next)->and($event->summary['due_date'])->toBe($due);
    expect(Loan::count())->toBe(1)->and(LoanTermChange::count())->toBe(1)->and(LoanMovement::where('kind', 'TERM_PAYMENT')->count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(81500);
    $loan->refresh();
    expect($loan->due_date->toDateString())->toBe($due)->and($loan->original_days)->toBe(15)->and($loan->original_due_date->toDateString())->toBe('2026-10-15');
})->with([[15, '2026-10-30', 1500], [30, '2026-11-14', 3000]]);
it('C210 extends the current 15 day period and never charges its supplemental interest twice', function (int $day) {
    [$admin,$cashier,$loan] = termFixture();
    test()->travelTo(CarbonImmutable::parse("2026-10-$day 23:59:59", 'America/La_Paz'));
    $action = app(ChangeLoanTermAction::class);
    $event = $action->execute($cashier, $loan->id, termData('EXTEND', 30));
    expect($event->summary['paid_cents'])->toBe(1500)->and($event->summary['pending_cents'])->toBe(1500)->and($event->summary['interest_cents'])->toBe(3000)->and($event->summary['due_date'])->toBe('2026-10-30');
    expect(fn () => $action->execute($cashier, $loan->id, termData('EXTEND', 30, $event->id)))->toThrow(ValidationException::class);
    $renew = $action->execute($cashier, $loan->id, termData('RENEW', 30, $event->id));
    expect($renew->summary['paid_cents'])->toBe(1500)->and($renew->summary['pending_cents'])->toBe(3000)->and(LoanFund::sole()->balance_cents)->toBe(83000)->and(Loan::count())->toBe(1);
})->with([3, 4, 15]);
it('C210 refuses stale parallel submissions unconfirmed payments late loans and inaccessible loans', function () {
    [$admin,$cashier,$loan] = termFixture();
    $action = app(ChangeLoanTermAction::class);
    expect(fn () => $action->execute($cashier, $loan->id, array_replace(termData(), ['payment_confirmed' => false])))->toThrow(ValidationException::class);
    $event = $action->execute($cashier, $loan->id, termData());
    expect(fn () => $action->execute($cashier, $loan->id, termData()))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $other->restrictedPermissions()->attach(Permission::findByName('prestamos.renovar'));
    expect(fn () => $action->execute($other, $loan->id, termData('RENEW', 15, $event->id)))->toThrow(AuthorizationException::class);
    test()->travelTo(CarbonImmutable::parse('2026-10-31 00:00', 'America/La_Paz'));
    expect(fn () => $action->execute($cashier, $loan->id, termData('RENEW', 15, $event->id)))->toThrow(ValidationException::class);
    expect(LoanTermChange::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(81500);
});
it('C210 rolls back fund dates and receipts on interruption then retries once with the original rate', function () {
    [$admin,$cashier,$loan,$type] = termFixture();
    $action = app(ChangeLoanTermAction::class);
    $data = termData('RENEW', 30);
    app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '20', 'is_active' => true, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()], $type);
    DB::statement("CREATE TRIGGER fail_term BEFORE INSERT ON loan_commands WHEN NEW.kind = 'TERM_CHANGE' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    expect(fn () => $action->execute($cashier, $loan->id, $data))->toThrow(QueryException::class);
    expect(LoanTermChange::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(80000)->and($loan->fresh()->due_date->toDateString())->toBe('2026-10-15');
    DB::statement('DROP TRIGGER fail_term');
    $event = $action->execute($cashier, $loan->id, $data);
    expect($event->summary['rate_bps'])->toBe(1500)->and($event->summary['pending_cents'])->toBe(3000);
});
it('C210 combines four monthly periods without charging the final interest twice', function () {
    [$admin,$cashier,$loan] = termFixture();
    $action = app(ChangeLoanTermAction::class);
    $first = $action->execute($cashier, $loan->id, termData('RENEW', 30));
    $event = $action->execute($cashier, $loan->id, array_replace(termData('RENEW', 30, $first->id), ['periods' => 4]));
    expect($event->summary['paid_cents'])->toBe(12000)->and($event->summary['pending_cents'])->toBe(3000)->and($event->summary['due_date'])->toBe('2027-03-14')->and(Loan::count())->toBe(1);
});

it('C210 rechecks loan read permission even when retrying a previously confirmed command', function () {
    [$admin,$cashier,$loan] = termFixture();
    $cover = User::factory()->create();
    $cover->assignRole('cajera');
    $cover->givePermissionTo('prestamos.ver_todos');
    $data = termData();
    $action = app(ChangeLoanTermAction::class);
    $action->execute($cover, $loan->id, $data);
    $cover->revokePermissionTo('prestamos.ver_todos');
    expect($action->execute($cover, $loan->id, $data)->id)->toBe(LoanTermChange::sole()->id);
    $cover->restrictedPermissions()->attach(Permission::findByName('prestamos.ver'));
    expect(fn () => $action->execute($cover, $loan->id, $data))->toThrow(AuthorizationException::class);
    expect(LoanTermChange::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(81500);
});

function settlementData(int $revision = 0, int $paid = 21500): array
{
    return ['expected_revision' => $revision, 'expected_paid_cents' => $paid, 'payment_confirmed' => true, 'delivery_confirmed' => true, 'reference' => 'PRUEBA-YAPE', 'request_key' => (string) Str::uuid()];
}
it('C234 settles capital and outstanding interest once and preserves original agreement', function () {
    [$admin,$cashier,$loan] = termFixture();
    $data = settlementData();
    $action = app(SettleLoanAction::class);
    $event = $action->execute($cashier, $loan->id, $data);
    expect($action->execute($cashier, $loan->id, $data)->id)->toBe($event->id);
    expect($loan->fresh()->status)->toBe('RECOGIDO_PAGADO')->and($loan->fresh()->original_interest_cents)->toBe(1500)->and(LoanFund::sole()->balance_cents)->toBe(101500);
    expect(LoanMovement::where('kind', 'SETTLEMENT')->count())->toBe(1)->and($event->summary['capital_cents'])->toBe(20000)->and($event->summary['interest_cents'])->toBe(1500);
    expect(fn () => $action->execute($cashier, $loan->id, settlementData()))->toThrow(ValidationException::class);
    expect(fn () => app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, termData()))->toThrow(ValidationException::class);
    expect(app(LoanQuery::class)->summary($cashier)['active'])->toBe(0);
    expect(app(LoanTermCalculator::class)->current($loan->fresh())['pending_cents'])->toBe(0);
    expect(fn () => $event->update(['summary' => []]))->toThrow(LogicException::class);
});
it('C234 recognizes extension credits and refuses an outdated settlement preview', function () {
    [$admin,$cashier,$loan] = termFixture();
    $event = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, termData('EXTEND', 30));
    $action = app(SettleLoanAction::class);
    expect(fn () => $action->execute($cashier, $loan->id, settlementData()))->toThrow(ValidationException::class);
    $settled = $action->execute($cashier, $loan->id, settlementData($event->id));
    expect($settled->summary['interest_cents'])->toBe(1500)->and(LoanFund::sole()->balance_cents)->toBe(103000);
});
it('C234 refuses missing confirmations overdue loans inaccessible records and unauthorized users', function () {
    [$admin,$cashier,$loan] = termFixture();
    $action = app(SettleLoanAction::class);
    foreach (['payment_confirmed', 'delivery_confirmed'] as $field) {
        expect(fn () => $action->execute($cashier, $loan->id, array_replace(settlementData(), [$field => false])))->toThrow(ValidationException::class);
    }
    expect(fn () => $action->execute($cashier, $loan->id, settlementData(0, 1)))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $other->restrictedPermissions()->attach(Permission::findByName('prestamos.pagar_entregar'));
    expect(fn () => $action->execute($other, $loan->id, settlementData()))->toThrow(AuthorizationException::class);
    $cashier->revokePermissionTo('prestamos.pagar_entregar');
    $cashier->givePermissionTo('prestamos.pagar_entregar');
    test()->travelTo(CarbonImmutable::parse('2026-10-16 00:00', 'America/La_Paz'));
    expect(fn () => $action->execute($cashier, $loan->id, settlementData()))->toThrow(ValidationException::class);
    $cashier->forceFill(['is_active' => false])->save();
    expect(fn () => $action->execute($cashier, $loan->id, settlementData()))->toThrow(AuthorizationException::class);
    expect(LoanFund::sole()->balance_cents)->toBe(80000)->and(LoanMovement::where('kind', 'SETTLEMENT')->count())->toBe(0);
});
it('C234 rolls back interrupted settlement and retries without duplicate debt recovery', function () {
    [$admin,$cashier,$loan] = termFixture();
    $action = app(SettleLoanAction::class);
    $data = settlementData();
    DB::statement("CREATE TRIGGER fail_settlement BEFORE INSERT ON loan_commands WHEN NEW.kind = 'SETTLEMENT' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    expect(fn () => $action->execute($cashier, $loan->id, $data))->toThrow(QueryException::class);
    expect(LoanFund::sole()->balance_cents)->toBe(80000)->and($loan->fresh()->status)->toBe('ACTIVO')->and(LoanSettlement::count())->toBe(0);
    DB::statement('DROP TRIGGER fail_settlement');
    $action->execute($cashier, $loan->id, $data);
    expect(LoanFund::sole()->balance_cents)->toBe(101500)->and(LoanMovement::where('kind', 'SETTLEMENT')->count())->toBe(1);
});
it('C234 prints the confirmed settlement rather than the last renewal and scopes its receipt', function () {
    [$admin,$cashier,$loan] = termFixture();
    $term = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, termData('RENEW', 30));
    $event = app(SettleLoanAction::class)->execute($cashier, $loan->id, settlementData($term->id, 23000));
    $this->actingAs($cashier);
    $response = $this->get(route('prestamos.recibo-pdf', ['loan' => $loan->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
    if ($path = getenv('LOAN_SETTLEMENT_QA_PDF')) {
        file_put_contents($path, $response->getContent());
    }
    $detail = app(LoanQuery::class)->detail($cashier, $loan->id);
    expect($detail['settlement']['paid_cents'])->toBe(23000)->and($detail['status'])->toBe('RECOGIDO_PAGADO');
    $html = view('prestamos.settlement-receipt', ['detail' => $detail, 'logoData' => ''])->render();
    expect(substr_count($html, 'COMPROBANTE DE PAGO Y ENTREGA'))->toBe(2)->and($html)->toContain('Deuda cancelada y objeto entregado', 'PRUEBA-YAPE');
    $this->get(route('prestamos.recibo-pdf', ['loan' => $loan->id, 'original' => 1]))->assertOk();
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $this->actingAs($other)->get(route('prestamos.recibo-pdf', ['loan' => $loan->id]))->assertOk();
    $other->restrictedPermissions()->attach(Permission::findByName('prestamos.ver'));
    $this->actingAs($other->fresh())->get(route('prestamos.recibo-pdf', ['loan' => $loan->id]))->assertForbidden();
    expect(LoanFund::sole()->balance_cents)->toBe(104500);
});

it('C245 describes the fifteen added days of an extension without presenting another thirty day period', function () {
    [$admin, $cashier, $loan] = termFixture();
    $event = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, termData('EXTEND', 30));
    $history = app(LoanRenewalHistoryData::class)->present($event);
    expect($history['duration'])->toBe('15 días');
    expect($history['period_from'])->toBe('16/10/2026')->and($history['period_to'])->toBe('30/10/2026');
    expect($event->summary['days'])->toBe(30)->and($event->summary['added_days'])->toBe(15)->and($event->summary['paid_cents'])->toBe(1500);
    expect(Loan::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(81500);
});
