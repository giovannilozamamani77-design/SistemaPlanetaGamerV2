<?php

use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordEntelDigitalFundingAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelDigitalMovement;
use App\Domain\Tarjetas\Queries\EntelDigitalQuery;
use App\Models\User;
use Database\Seeders\Tarjetas\EntelDigitalPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function c153Admin(string $role = 'administrador'): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, EntelDigitalPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}
function c153Funding(string $kind = 'CREDIT', string $operation = 'INITIAL', string $credited = '1000', ?string $paid = null): array
{
    return ['kind' => $kind, 'operation' => $operation, 'credited_amount' => $credited, 'paid_amount' => $paid, 'request_key' => (string) Str::uuid()];
}
it('C153 initializes independent books and records actual credit funding without an automatic fixed bonus', function () {
    $admin = c153Admin();
    $action = app(RecordEntelDigitalFundingAction::class);
    $action->execute($admin, c153Funding());
    $action->execute($admin, c153Funding('PACKAGES', 'INITIAL', '50.03'));
    $movement = $action->execute($admin, c153Funding('CREDIT', 'TOP_UP', '5200', '5000'));
    expect(EntelDigitalAccount::where('kind', 'CREDIT')->sole()->balance_cents)->toBe(620000)
        ->and(EntelDigitalAccount::where('kind', 'PACKAGES')->sole()->balance_cents)->toBe(5003)
        ->and($movement->bonus_cents)->toBe(20000)->and($movement->bonus_rate_bps)->toBe(400)
        ->and(EntelDigitalMovement::where('operation', 'INITIAL')->first()->paid_cents)->toBeNull();
});
it('C153 replays the same initial request without duplicating its balance or history', function () {
    $admin = c153Admin();
    $data = c153Funding();
    $action = app(RecordEntelDigitalFundingAction::class);
    $first = $action->execute($admin, $data);
    expect($action->execute($admin, $data)->id)->toBe($first->id)->and(EntelDigitalMovement::count())->toBe(1);
});

it('C153 refuses a second initialization and funding before initialization without creating partial accounts', function () {
    $admin = c153Admin();
    $action = app(RecordEntelDigitalFundingAction::class);
    expect(fn () => $action->execute($admin, c153Funding('PACKAGES', 'TOP_UP', '5000', '5000')))->toThrow(ValidationException::class);
    expect(EntelDigitalAccount::count())->toBe(0);
    $action->execute($admin, c153Funding('CREDIT', 'INITIAL', '0'));
    expect(fn () => $action->execute($admin, c153Funding('CREDIT', 'INITIAL', '900')))->toThrow(ValidationException::class);
    expect(EntelDigitalAccount::sole()->balance_cents)->toBe(0)->and(EntelDigitalMovement::count())->toBe(1);
});
it('C153 refuses contradictory requests and reuse by another administrator', function () {
    $admin = c153Admin();
    $other = c153Admin();
    $data = c153Funding();
    $action = app(RecordEntelDigitalFundingAction::class);
    $action->execute($admin, $data);
    expect(fn () => $action->execute($admin, array_replace($data, ['kind' => 'PACKAGES'])))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($other, $data))->toThrow(AuthorizationException::class);
    expect(EntelDigitalAccount::count())->toBe(1)->and(EntelDigitalMovement::count())->toBe(1);
});
it('C153 normalizes decimal replay and ignores forged balance and bonus fields', function () {
    $admin = c153Admin();
    $data = c153Funding('CREDIT', 'INITIAL', '50.3');
    $action = app(RecordEntelDigitalFundingAction::class);
    $first = $action->execute($admin, $data + ['bonus_cents' => 9999, 'balance_cents' => 9999]);
    expect($action->execute($admin, array_replace($data, ['credited_amount' => '50.30']))->id)->toBe($first->id)
        ->and(EntelDigitalAccount::sole()->balance_cents)->toBe(5030)->and($first->bonus_cents)->toBeNull();
});
it('C153 denies cashier and manager even with direct sensitive permissions', function (string $role) {
    $user = c153Admin($role);
    $user->givePermissionTo(['tarjetas.entel.ver', 'tarjetas.entel.registrar']);
    expect(fn () => app(RecordEntelDigitalFundingAction::class)->execute($user, c153Funding()))->toThrow(AuthorizationException::class);
    expect(fn () => app(EntelDigitalQuery::class)->accounts($user))->toThrow(AuthorizationException::class);
    expect(EntelDigitalAccount::count())->toBe(0);
})->with(['cajera', 'encargado']);
it('C153 rechecks inactive and individually restricted administrators', function () {
    $admin = c153Admin();
    $admin->restrictedPermissions()->attach(Permission::findByName('tarjetas.entel.registrar')->id);
    expect(fn () => app(RecordEntelDigitalFundingAction::class)->execute($admin, c153Funding()))->toThrow(AuthorizationException::class);
    $admin->restrictedPermissions()->detach();
    $admin->forceFill(['is_active' => false])->save();
    expect(fn () => app(RecordEntelDigitalFundingAction::class)->execute($admin, c153Funding()))->toThrow(AuthorizationException::class);
});
it('C153 validates payment minimum and actual credited amount preserving balances', function (string $paid, string $credited, string $field) {
    $admin = c153Admin();
    $action = app(RecordEntelDigitalFundingAction::class);
    $action->execute($admin, c153Funding('CREDIT', 'INITIAL', '0'));
    try {
        $action->execute($admin, c153Funding('CREDIT', 'TOP_UP', $credited, $paid));
        test()->fail('Invalid funding accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
    expect(EntelDigitalAccount::sole()->balance_cents)->toBe(0)->and(EntelDigitalMovement::count())->toBe(1);
})->with([
    ['4999.99', '5250', 'paid_amount'], ['5000', '4999', 'credited_amount'], ['', '5250', 'paid_amount'],
    ['5000', '-1', 'credited_amount'], ['5000', '5000.001', 'credited_amount'], ['5000', '1e4', 'credited_amount'],
]);
it('C153 keeps actual bonuses separate from customer percentages and performs no store cash or stock mutation', function () {
    $admin = c153Admin();
    test()->seed(TarjetasPermissionsSeeder::class);
    app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.4', 'normal_price' => '10', 'wholesale_price' => '9.5', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '3000.03', 'stocks' => [], 'request_key' => (string) Str::uuid()]);
    $before = CardStore::sole()->toArray();
    $action = app(RecordEntelDigitalFundingAction::class);
    $action->execute($admin, c153Funding('PACKAGES', 'INITIAL', '0'));
    $m = $action->execute($admin, c153Funding('PACKAGES', 'TOP_UP', '5250', '5000'));
    expect($m->bonus_cents)->toBe(25000)->and($m->bonus_rate_bps)->toBe(500)
        ->and(CardStore::sole()->toArray())->toBe($before)
        ->and(CardStock::sum('quantity'))->toBe(0)
        ->and(CardInventoryMovement::count())->toBe(1);
});
it('C153 rolls back balance and initial account after a failed audit and safely retries', function () {
    $admin = c153Admin();
    $data = c153Funding();
    $break = true;
    EntelDigitalMovement::creating(function () use (&$break): void {
        if ($break) {
            throw new RuntimeException('test interruption');
        }
    });
    try {
        expect(fn () => app(RecordEntelDigitalFundingAction::class)->execute($admin, $data))->toThrow(RuntimeException::class);
        expect(EntelDigitalAccount::count())->toBe(0)->and(EntelDigitalMovement::count())->toBe(0);
        $break = false;
        app(RecordEntelDigitalFundingAction::class)->execute($admin, $data);
        expect(EntelDigitalAccount::sole()->balance_cents)->toBe(100000)->and(EntelDigitalMovement::count())->toBe(1);
    } finally {
        EntelDigitalMovement::flushEventListeners();
        EntelDigitalMovement::clearBootedModels();
    }
});
it('C153 prevents a balance overflow without losing previous history', function () {
    $admin = c153Admin();
    $action = app(RecordEntelDigitalFundingAction::class);
    $action->execute($admin, c153Funding('CREDIT', 'INITIAL', '999999999.99'));
    expect(fn () => $action->execute($admin, c153Funding('CREDIT', 'TOP_UP', '5000', '5000')))->toThrow(ValidationException::class);
    expect(EntelDigitalAccount::sole()->balance_cents)->toBe(99999999999)->and(EntelDigitalMovement::count())->toBe(1);
});
it('C153 preserves history against update and deletion and does not restore revoked permissions on seeding', function () {
    $admin = c153Admin();
    $m = app(RecordEntelDigitalFundingAction::class)->execute($admin, c153Funding());
    expect(fn () => $m->update(['credited_cents' => 50]))->toThrow(LogicException::class);
    expect(fn () => $m->delete())->toThrow(LogicException::class);
    $role = Role::findByName('administrador');
    $role->revokePermissionTo('tarjetas.entel.registrar');
    test()->seed(EntelDigitalPermissionsSeeder::class);
    expect($role->fresh()->hasPermissionTo('tarjetas.entel.registrar'))->toBeFalse();
});
it('C153 rejects an attempted payment on the initial balance rather than treating it as free bonus', function () {
    $admin = c153Admin();
    expect(fn () => app(RecordEntelDigitalFundingAction::class)->execute($admin, c153Funding('CREDIT', 'INITIAL', '100', '50')))->toThrow(ValidationException::class);
    expect(EntelDigitalAccount::count())->toBe(0);
});

it('C153 rolls back an interrupted subsequent load and applies a retry once', function () {
    $admin = c153Admin();
    $action = app(RecordEntelDigitalFundingAction::class);
    $action->execute($admin, c153Funding());
    $data = c153Funding('CREDIT', 'TOP_UP', '5250', '5000');
    $break = true;
    EntelDigitalMovement::creating(function () use (&$break): void {
        if ($break) {
            throw new RuntimeException('test load interruption');
        }
    });
    try {
        expect(fn () => $action->execute($admin, $data))->toThrow(RuntimeException::class);
        expect(EntelDigitalAccount::sole()->balance_cents)->toBe(100000)->and(EntelDigitalMovement::count())->toBe(1);
        $break = false;
        $movement = $action->execute($admin, $data);
        expect($action->execute($admin, $data)->id)->toBe($movement->id)
            ->and(EntelDigitalAccount::sole()->balance_cents)->toBe(625000)->and(EntelDigitalMovement::count())->toBe(2);
    } finally {
        EntelDigitalMovement::flushEventListeners();
        EntelDigitalMovement::clearBootedModels();
    }
});
