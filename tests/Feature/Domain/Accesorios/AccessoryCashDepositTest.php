<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Actions\RecordAccessoryCashDepositAction;
use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Actions\VoidAccessoryCashDepositAction;
use App\Domain\Accesorios\Models\AccessoryCashDeposit;
use App\Domain\Accesorios\Models\AccessoryCashDepositReversal;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function accessoryDepositFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    Http::preventStrayRequests();
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Cajera de prueba']);
    $cashier->assignRole('cajera');
    $account = CardDepositAccount::create(['name' => 'Administración', 'bank' => 'Banco ejemplo', 'holder' => 'Titular ejemplo', 'number' => '123456', 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $fund = CommercialFund::create(['code' => 'GENERAL', 'balance_cents' => 10000, 'revision' => 0]);

    return [$admin, $cashier, $account, $fund];
}
function accessoryDepositData($account, $fund, string $amount = '40'): array
{
    return ['account_id' => $account->id, 'account_revision' => $account->revision, 'expected_revision' => $fund->fresh()->revision, 'amount' => $amount, 'operation_reference' => ' OP-123 ', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
}
it('records a cashier deposit once and restores only that amount after later cash operations', function () {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    $data = accessoryDepositData($account, $fund);
    $deposit = app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    expect($fund->fresh()->balance_cents)->toBe(6000)->and($deposit->actor_name)->toBe('Cajera de prueba')->and($deposit->operation_reference)->toBe('OP-123');
    expect($deposit->destination_snapshot['number'])->toBe('123456');
    $second = app(RecordAccessoryCashDepositAction::class)->execute($cashier, accessoryDepositData($account, $fund, '10'));
    $void = ['deposit_id' => $deposit->id, 'expected_revision' => $fund->fresh()->revision, 'reason' => 'Registro equivocado', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
    $reversal = app(VoidAccessoryCashDepositAction::class)->execute($admin, $void);
    expect($fund->fresh()->balance_cents)->toBe(9000)->and($reversal->amount_cents)->toBe(4000);
    expect(app(VoidAccessoryCashDepositAction::class)->execute($admin, $void)->id)->toBe($reversal->id);
    expect(app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data)->id)->toBe($deposit->id);
    expect(AccessoryCashDeposit::count())->toBe(2)->and(AccessoryCashDepositReversal::count())->toBe(1)->and(CommercialMovement::count())->toBe(3);
    expect($second->reversal)->toBeNull()->and($deposit->fresh()->reversal->id)->toBe($reversal->id);
    Http::assertNothingSent();
});
it('allows a full deposit leaving zero and prevents a second distinct deposit without cash', function () {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    app(RecordAccessoryCashDepositAction::class)->execute($cashier, accessoryDepositData($account, $fund, '100'));
    expect($fund->fresh()->balance_cents)->toBe(0);
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, accessoryDepositData($account, $fund, '0.01')))->toThrow(ValidationException::class);
    expect(AccessoryCashDeposit::count())->toBe(1);
});
it('rejects invalid amounts without changing cash', function ($amount) {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, accessoryDepositData($account, $fund, $amount)))->toThrow(ValidationException::class);
    expect($fund->fresh()->balance_cents)->toBe(10000)->and(CommercialMovement::count())->toBe(0);
})->with(['0', '-1', '100.01', '1.001', 'abc']);
it('rejects changed cash or account before confirming but keeps an immutable historical account', function () {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    $data = accessoryDepositData($account, $fund);
    $fund->update(['revision' => 1]);
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data))->toThrow(ValidationException::class);
    $data = accessoryDepositData($account, $fund);
    $account->update(['revision' => 2]);
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data))->toThrow(ValidationException::class);
    $deposit = app(RecordAccessoryCashDepositAction::class)->execute($cashier, accessoryDepositData($account, $fund));
    $account->update(['name' => 'Nombre nuevo', 'is_active' => false]);
    expect($deposit->fresh()->destination_snapshot['name'])->toBe('Administración');
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, accessoryDepositData($account, $fund, '1')))->toThrow(ValidationException::class);
});
it('restricts reversal to active administrators and does not offer cashier requests', function () {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    $deposit = app(RecordAccessoryCashDepositAction::class)->execute($cashier, accessoryDepositData($account, $fund));
    $void = ['deposit_id' => $deposit->id, 'expected_revision' => 1, 'reason' => 'Error', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
    $manager = User::factory()->create();
    $manager->assignRole('encargado');
    foreach ([$cashier, $manager] as $actor) {
        expect(fn () => app(VoidAccessoryCashDepositAction::class)->execute($actor, $void))->toThrow(AuthorizationException::class);
    }
    $admin->forceFill(['is_active' => false])->save();
    expect(fn () => app(VoidAccessoryCashDepositAction::class)->execute($admin, $void))->toThrow(AuthorizationException::class);
    $cashier->forceFill(['is_active' => false])->save();
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, accessoryDepositData($account, $fund, '1')))->toThrow(AuthorizationException::class);
    expect($fund->fresh()->balance_cents)->toBe(6000)->and(AccessoryCashDepositReversal::count())->toBe(0);
});
it('rejects uuid reuse with different data or actor and a second reversal', function () {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    $data = accessoryDepositData($account, $fund);
    $deposit = app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, array_replace($data, ['amount' => '41'])))->toThrow(ValidationException::class);
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($admin, $data))->toThrow(AuthorizationException::class);
    $v = ['deposit_id' => $deposit->id, 'expected_revision' => 1, 'reason' => 'Error', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
    app(VoidAccessoryCashDepositAction::class)->execute($admin, $v);
    expect(fn () => app(VoidAccessoryCashDepositAction::class)->execute($admin, array_replace($v, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    expect($fund->fresh()->balance_cents)->toBe(10000)->and(CommercialMovement::count())->toBe(2);
});
it('rolls back the cash movement if persisting the deposit fails', function () {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    $data = accessoryDepositData($account, $fund);
    DB::statement("CREATE TRIGGER fail_deposit BEFORE INSERT ON accessory_cash_deposits BEGIN SELECT RAISE(ABORT, 'simulated'); END");
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data))->toThrow(QueryException::class);
    expect($fund->fresh()->balance_cents)->toBe(10000)->and(CommercialMovement::count())->toBe(0);
    DB::statement('DROP TRIGGER fail_deposit');
    app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    expect($fund->fresh()->balance_cents)->toBe(6000)->and(AccessoryCashDeposit::count())->toBe(1);
});

it('rolls back a failed reversal and safely retries without losing later sales cash', function () {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    $d = app(RecordAccessoryCashDepositAction::class)->execute($cashier, accessoryDepositData($account, $fund));
    // A subsequent cash sale changes the same fund; reversal must add, never overwrite it.
    $fund->refresh()->update(['balance_cents' => 7500, 'revision' => 2]);
    $v = ['deposit_id' => $d->id, 'expected_revision' => 2, 'reason' => 'Error de registro', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
    DB::statement("CREATE TRIGGER fail_deposit_void BEFORE INSERT ON accessory_cash_deposit_reversals BEGIN SELECT RAISE(ABORT, 'simulated'); END");
    expect(fn () => app(VoidAccessoryCashDepositAction::class)->execute($admin, $v))->toThrow(QueryException::class);
    expect($fund->fresh()->balance_cents)->toBe(7500)->and(CommercialMovement::count())->toBe(1);
    DB::statement('DROP TRIGGER fail_deposit_void');
    app(VoidAccessoryCashDepositAction::class)->execute($admin, $v);
    expect($fund->fresh()->balance_cents)->toBe(11500)->and(CommercialMovement::count())->toBe(2);
});
it('requires confirmation and a real reason and rejects stale reversal cash', function () {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    $data = accessoryDepositData($account, $fund);
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, array_replace($data, ['confirmed' => false])))->toThrow(ValidationException::class);
    $d = app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    $v = ['deposit_id' => $d->id, 'expected_revision' => 0, 'reason' => 'Error', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
    expect(fn () => app(VoidAccessoryCashDepositAction::class)->execute($admin, $v))->toThrow(ValidationException::class);
    expect(fn () => app(VoidAccessoryCashDepositAction::class)->execute($admin, array_replace($v, ['expected_revision' => 1, 'reason' => '   '])))->toThrow(ValidationException::class);
    expect($fund->fresh()->balance_cents)->toBe(6000);
});

it('coexists with actual isolated sales without modifying stock costs or earned commissions on reversal', function () {
    [$admin,$cashier,$account,$fund] = accessoryDepositFixture();
    $p = app(CreateAccessoryProductAction::class)->execute($admin, ['code' => 'TEST-DEPOSIT', 'name' => 'Cable de carga', 'sale_price' => '20', 'request_key' => (string) Str::uuid()]);
    app(ReceiveAccessoryStockAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 3, 'unit_cost' => '10', 'kind' => 'INITIAL', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $sell = function () use ($cashier, $p) {
        $p->refresh();

        return app(RegisterAccessorySaleAction::class)->execute($cashier, ['lines' => [['product_id' => $p->id, 'quantity' => 1, 'expected_revision' => $p->revision, 'expected_price_cents' => $p->sale_price_cents]], 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => (string) Str::uuid()]);
    };
    $sell();
    $data = accessoryDepositData($account, $fund, '40');
    $d = app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    $sell();
    $stock = $p->fresh()->getAttributes();
    $commissions = CommissionEntry::get()->toArray();
    app(VoidAccessoryCashDepositAction::class)->execute($admin, ['deposit_id' => $d->id, 'expected_revision' => $fund->fresh()->revision, 'reason' => 'Depósito equivocado', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect($fund->fresh()->balance_cents)->toBe(14000)->and($p->fresh()->getAttributes())->toBe($stock)->and(CommissionEntry::get()->toArray())->toBe($commissions);
    expect(AccessorySale::count())->toBe(2)->and(CommercialMovement::count())->toBe(4);
    Http::assertNothingSent();
});
