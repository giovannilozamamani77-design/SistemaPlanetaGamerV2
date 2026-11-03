<?php

use App\Domain\Tarjetas\Actions\RecordEntelRechargeAction;
use App\Domain\Tarjetas\Actions\SaveEntelRechargeRatesAction;
use App\Domain\Tarjetas\Calculators\CardSaleCalculator;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelDigitalMovement;
use App\Domain\Tarjetas\Models\EntelRecharge;
use App\Domain\Tarjetas\Queries\EntelRechargeQuery;
use App\Models\User;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\EntelDigitalPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function c157Setup(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole('cajera');
    CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 1000, 'initialized_at' => now(), 'initialized_by' => $u->id]);
    foreach (['CREDIT' => 30000, 'PACKAGES' => 50000] as $kind => $balance) {
        EntelDigitalAccount::create(['kind' => $kind, 'balance_cents' => $balance, 'initialized_at' => now(), 'initialized_by' => $u->id]);
    }

    return $u;
}
function c157Data(string $kind = 'CREDIT', string $amount = '100'): array
{
    return ['kind' => $kind, 'amount' => $amount, 'external_confirmed' => true, 'request_key' => (string) Str::uuid()];
}
it('C157 debits only the selected book and credits cash once with external bonus information', function (string $kind, int $bps, int $customer) {
    $u = c157Setup();
    $data = c157Data($kind);
    $a = app(RecordEntelRechargeAction::class);
    $r = $a->execute($u, $data);
    expect($a->execute($u, $data)->id)->toBe($r->id);
    expect(EntelDigitalAccount::where('kind', $kind)->sole()->balance_cents)->toBe(($kind === 'CREDIT' ? 30000 : 50000) - 10000)
        ->and(EntelDigitalAccount::where('kind', $kind === 'CREDIT' ? 'PACKAGES' : 'CREDIT')->sole()->balance_cents)->toBe($kind === 'CREDIT' ? 50000 : 30000)
        ->and(CardStore::sole()->cash_balance_cents)->toBe(11000)->and($r->bonus_rate_bps)->toBe($bps)->and($r->customer_total_cents)->toBe($customer)
        ->and(EntelRecharge::count())->toBe(1)->and(EntelDigitalMovement::sole()->debited_cents)->toBe(10000);
    expect(json_encode($r->toArray()))->not->toContain('balance', 'digital_account', 'payload_hash');
})->with([['CREDIT', 800, 10800], ['PACKAGES', 1500, 11500]]);
it('C157 rejects below minimum unconfirmed and insufficient recharges without partial writes', function () {
    $u = c157Setup();
    $a = app(RecordEntelRechargeAction::class);
    foreach ([c157Data('CREDIT', '99.99'), array_replace(c157Data(), ['external_confirmed' => false]), c157Data('CREDIT', '400')] as $data) {
        expect(fn () => $a->execute($u, $data))->toThrow(ValidationException::class);
    }
    expect(EntelRecharge::count())->toBe(0)->and(EntelDigitalMovement::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
});

it('C157 preserves full atomicity on interrupted audit and accepts retry without duplicate ledgers', function () {
    $u = c157Setup();
    $data = c157Data();
    $a = app(RecordEntelRechargeAction::class);
    DB::unprepared("CREATE TRIGGER c157_interrupt BEFORE INSERT ON card_inventory_commands WHEN NEW.action = 'ENTEL_RECHARGE' BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    expect(fn () => $a->execute($u, $data))->toThrow(QueryException::class);
    expect(EntelRecharge::count())->toBe(0)->and(EntelDigitalMovement::count())->toBe(0)->and(CardInventoryMovement::count())->toBe(0)
        ->and(EntelDigitalAccount::where('kind', 'CREDIT')->sole()->balance_cents)->toBe(30000)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
    DB::unprepared('DROP TRIGGER c157_interrupt');
    $a->execute($u, $data);
    $a->execute($u, $data);
    expect(EntelRecharge::count())->toBe(1)->and(EntelDigitalMovement::count())->toBe(1);
});
it('C157 rejects contradictory reuse and another actor without another receipt', function () {
    $u = c157Setup();
    $data = c157Data();
    $a = app(RecordEntelRechargeAction::class);
    $a->execute($u, $data);
    expect(fn () => $a->execute($u, array_replace($data, ['kind' => 'PACKAGES'])))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(fn () => $a->execute($other, $data))->toThrow(AuthorizationException::class);
    expect(EntelRecharge::count())->toBe(1)->and(CardStore::sole()->cash_balance_cents)->toBe(11000);
});
it('C157 hides absent and insufficient books behind the same message and prevents unauthorized access', function () {
    $u = c157Setup();
    $q = app(EntelRechargeQuery::class);
    $message = $q->availability($u, 'CREDIT', '400');
    expect($message)->toBe('No hay saldo disponible; comuníquese con administración');
    EntelDigitalAccount::where('kind', 'CREDIT')->delete();
    expect($q->availability($u, 'CREDIT', '100'))->toBe($message)->and($q->availability($u, 'PACKAGES', '100'))->toBe('Saldo disponible');
    expect(fn () => app(RecordEntelRechargeAction::class)->execute($u, c157Data()))->toThrow(ValidationException::class, $message);
    $u->forceFill(['is_active' => false])->save();
    expect(fn () => $q->availability($u, 'PACKAGES', '100'))->toThrow(AuthorizationException::class);
});
it('C157 snapshots configured customer bonus without changing funding bonus or past sales', function () {
    $u = c157Setup();
    test()->seed(EntelDigitalPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $a = app(RecordEntelRechargeAction::class);
    $first = $a->execute($u, c157Data());
    $rates = app(SaveEntelRechargeRatesAction::class);
    $data = ['credit' => '10', 'packages' => '20', 'request_key' => (string) Str::uuid()];
    expect(fn () => $rates->execute($u, $data))->toThrow(AuthorizationException::class);
    $rates->execute($admin, $data);
    $rates->execute($admin, $data);
    $second = $a->execute($u, c157Data());
    expect($first->fresh()->customer_total_cents)->toBe(10800)->and($second->customer_total_cents)->toBe(11000)
        ->and(EntelDigitalMovement::whereNotNull('bonus_cents')->count())->toBe(0)->and(CardInventoryCommand::where('action', 'ENTEL_RATES')->count())->toBe(1);
    expect(fn () => $rates->execute($admin, array_replace($data, ['credit' => '101', 'request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
});
it('C157 spends exact balance without overdraft and rejects a second sale with fresh key', function () {
    $u = c157Setup();
    $a = app(RecordEntelRechargeAction::class);
    $a->execute($u, c157Data('CREDIT', '300'));
    expect(EntelDigitalAccount::where('kind', 'CREDIT')->sole()->balance_cents)->toBe(0);
    expect(fn () => $a->execute($u, c157Data()))->toThrow(ValidationException::class);
    expect(EntelRecharge::count())->toBe(1)->and(CardStore::sole()->cash_balance_cents)->toBe(31000);
});
it('C157 rejects forged amounts and preserves immutable receipts', function () {
    $u = c157Setup();
    $a = app(RecordEntelRechargeAction::class);
    foreach (['-100', '1e3', '100.001', '1000000000'] as $amount) {
        expect(fn () => $a->execute($u, c157Data('CREDIT', $amount)))->toThrow(ValidationException::class);
    }
    $r = $a->execute($u, c157Data() + ['bonus_rate_bps' => 9999, 'balance_cents' => 9999, 'customer_total_cents' => 1]);
    expect($r->bonus_rate_bps)->toBe(800);
    expect(fn () => $r->update(['amount_cents' => 1]))->toThrow(LogicException::class);
    expect(fn () => $r->delete())->toThrow(LogicException::class);
});
it('C157 refuses a sale before store initialization and cash overflow without debiting a book', function () {
    $u = c157Setup();
    $store = CardStore::sole();
    $store->update(['initialized_at' => null]);
    $a = app(RecordEntelRechargeAction::class);
    expect(fn () => $a->execute($u, c157Data()))->toThrow(ValidationException::class);
    $store->update(['initialized_at' => now(), 'cash_balance_cents' => CardSaleCalculator::MAX_CENTS]);
    expect(fn () => $a->execute($u, c157Data()))->toThrow(ValidationException::class);
    expect(EntelDigitalAccount::where('kind', 'CREDIT')->sole()->balance_cents)->toBe(30000)->and(EntelRecharge::count())->toBe(0);
});
