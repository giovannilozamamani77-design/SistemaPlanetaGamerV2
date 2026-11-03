<?php

use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Actions\SaveCardWholesaleRulesAction;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardSaleLine;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function c155User(string $role = 'administrador'): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}
function c155Product(User $admin, string $company = 'TIGO', string $face = '10'): CardProduct
{
    return app(SaveCardProductAction::class)->execute($admin, ['company' => $company, 'face_value' => $face, 'purchase_price' => '9.4', 'normal_price' => $face, 'wholesale_price' => '9.5', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
}
function c155Open(User $admin, array $products, string $cash = '10'): void
{
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => $cash, 'stocks' => array_map(fn ($p) => ['product_id' => $p->id, 'quantity' => 100], $products), 'request_key' => (string) Str::uuid()]);
}
function c155Sale(array $products, string $mode = 'NORMAL'): array
{
    return ['mode' => $mode, 'payment_method' => 'CASH', 'lines' => array_map(fn ($p) => ['product_id' => $p->id, 'quantity' => 3, 'unit_price' => '10'], $products), 'request_key' => (string) Str::uuid()];
}
it('C155 sells three cards and adds their actual payment to the store cash', function () {
    $admin = c155User();
    $cashier = c155User('cajera');
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $sale = app(RecordCardSaleAction::class)->execute($cashier, c155Sale([$p]));
    expect(CardStock::sole()->quantity)->toBe(97)->and(CardStore::sole()->cash_balance_cents)->toBe(4000)
        ->and($sale->total_cents)->toBe(3000)->and($sale->user_id)->toBe($cashier->id);
});
it('C155 replays a sale without duplicating cash stock or sale history', function () {
    $admin = c155User();
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $data = c155Sale([$p]);
    $action = app(RecordCardSaleAction::class);
    $first = $action->execute($admin, $data);
    expect($action->execute($admin, $data)->id)->toBe($first->id)->and(CardSale::count())->toBe(1)
        ->and(CardStock::sole()->quantity)->toBe(97)->and(CardStore::sole()->cash_balance_cents)->toBe(4000);
});

it('C155 combines eligible companies under the explicit wholesale mode', function () {
    $admin = c155User();
    $t = c155Product($admin);
    $e = c155Product($admin, 'ENTEL');
    c155Open($admin, [$t, $e]);
    $data = c155Sale([$t, $e], 'WHOLESALE');
    $data['lines'][0]['quantity'] = 3;
    $data['lines'][1]['quantity'] = 2;
    foreach ($data['lines'] as &$line) {
        $line['unit_price'] = '9.50';
    }unset($line);
    $sale = app(RecordCardSaleAction::class)->execute($admin, $data);
    expect($sale->total_cents)->toBe(4750)->and($sale->total_quantity)->toBe(5)->and($sale->lines)->toHaveCount(2)
        ->and(CardStock::where('card_product_id', $t->id)->sole()->quantity)->toBe(97)
        ->and(CardStock::where('card_product_id', $e->id)->sole()->quantity)->toBe(98)
        ->and(CardStore::sole()->cash_balance_cents)->toBe(5750);
});
it('C155 never converts a normal sale into wholesale merely because quantity exceeds five', function () {
    $admin = c155User();
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $data = c155Sale([$p]);
    $data['lines'][0]['quantity'] = 6;
    $sale = app(RecordCardSaleAction::class)->execute($admin, $data);
    expect($sale->mode)->toBe('NORMAL')->and($sale->total_cents)->toBe(6000);
});
it('C155 refuses wholesale below the combined minimum or with an ineligible Cabina without partial mutations', function (bool $below) {
    $admin = c155User();
    $t = c155Product($admin);
    $c = c155Product($admin, 'VIVA', '40');
    c155Open($admin, [$t, $c]);
    $data = c155Sale($below ? [$t] : [$t, $c], 'WHOLESALE');
    expect(fn () => app(RecordCardSaleAction::class)->execute($admin, $data))->toThrow(ValidationException::class);
    expect(CardSale::count())->toBe(0)->and(CardStock::sum('quantity'))->toBe(200)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
})->with([true, false]);
it('C155 allows applied prices below catalogue cost without rewriting prices or historical snapshots', function () {
    $admin = c155User();
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $data = c155Sale([$p]);
    $data['lines'][0]['unit_price'] = '1.03';
    $sale = app(RecordCardSaleAction::class)->execute($admin, $data);
    app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.80', 'normal_price' => '11', 'wholesale_price' => '10', 'is_active' => true, 'expected_revision' => $p->revision, 'request_key' => (string) Str::uuid()], $p);
    expect($sale->lines->sole()->unit_price_cents)->toBe(103)->and($sale->lines->sole()->product_snapshot['normal_price_cents'])->toBe(1000)
        ->and($sale->lines->sole()->product_snapshot['purchase_price_cents'])->toBe(940)->and($p->fresh()->normal_price_cents)->toBe(1100)
        ->and(CardStore::sole()->cash_balance_cents)->toBe(1309);
});
it('C155 consumes current stock for a second cashier and never oversells it', function () {
    $admin = c155User();
    $other = c155User('cajera');
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $data = c155Sale([$p]);
    $data['lines'][0]['quantity'] = 99;
    app(RecordCardSaleAction::class)->execute($admin, $data);
    expect(fn () => app(RecordCardSaleAction::class)->execute($other, c155Sale([$p])))->toThrow(ValidationException::class);
    expect(CardStock::sole()->quantity)->toBe(1)->and(CardSale::count())->toBe(1);
});
it('C155 permits selling the exact remaining stock down to zero', function () {
    $admin = c155User();
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $data = c155Sale([$p]);
    $data['lines'][0]['quantity'] = 100;
    app(RecordCardSaleAction::class)->execute($admin, $data);
    expect(CardStock::sole()->quantity)->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(101000);
});
it('C155 rejects invalid cart inputs before changing any balance', function (string $case) {
    $admin = c155User();
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $data = c155Sale([$p]);
    switch ($case) {
        case 'duplicate':$data['lines'][] = $data['lines'][0];
            break;
        case 'fraction':$data['lines'][0]['quantity'] = '1.5';
            break;
        case 'negative':$data['lines'][0]['quantity'] = -1;
            break;
        case 'zero':$data['lines'][0]['quantity'] = 0;
            break;
        case 'price':$data['lines'][0]['unit_price'] = '1.001';
            break;
        case 'exponent':$data['lines'][0]['unit_price'] = '1e2';
            break;
        case 'payment':$data['payment_method'] = 'QR';
            break;
        case 'mode':$data['mode'] = 'AUTO';
            break;
        case 'missing':$data['lines'][0]['product_id'] = 99999;
            break;
        case 'inactive':$p->update(['is_active' => false]);
            break;
        case 'empty':$data['lines'] = [];
            break;
    }
    expect(fn () => app(RecordCardSaleAction::class)->execute($admin, $data))->toThrow(ValidationException::class);
    expect(CardSale::count())->toBe(0)->and(CardStock::sole()->quantity)->toBe(100)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
})->with(['duplicate', 'fraction', 'negative', 'zero', 'price', 'exponent', 'payment', 'mode', 'missing', 'inactive', 'empty']);
it('C155 requires initialization without inventing opening funds', function () {
    $admin = c155User();
    $p = c155Product($admin);
    expect(fn () => app(RecordCardSaleAction::class)->execute($admin, c155Sale([$p])))->toThrow(ValidationException::class);
    expect(CardStore::sole()->initialized_at)->toBeNull()->and(CardStore::sole()->cash_balance_cents)->toBe(0)->and(CardStock::sole()->quantity)->toBe(0);
});
it('C155 normalizes decimal retry and rejects changed data or another actor reusing the key', function () {
    $admin = c155User();
    $other = c155User('cajera');
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $data = c155Sale([$p]);
    $data['lines'][0]['unit_price'] = '9.5';
    $a = app(RecordCardSaleAction::class);
    $sale = $a->execute($admin, $data);
    $data['lines'][0]['unit_price'] = '9.50';
    expect($a->execute($admin, $data)->id)->toBe($sale->id);
    expect(fn () => $a->execute($other, $data))->toThrow(AuthorizationException::class);
    $data['lines'][0]['quantity'] = 4;
    expect(fn () => $a->execute($admin, $data))->toThrow(ValidationException::class);
    expect(CardSale::count())->toBe(1)->and(CardStock::sole()->quantity)->toBe(97);
});
it('C155 rolls back all sale lines stock cash and audit after a mid cart interruption then retries once', function () {
    $admin = c155User();
    $t = c155Product($admin);
    $e = c155Product($admin, 'ENTEL');
    c155Open($admin, [$t, $e]);
    $data = c155Sale([$t, $e]);
    $count = 0;
    $break = true;
    CardSaleLine::creating(function () use (&$count, &$break) {
        if ($break && ++$count === 2) {
            throw new RuntimeException('test interruption');
        }
    });
    try {
        expect(fn () => app(RecordCardSaleAction::class)->execute($admin, $data))->toThrow(RuntimeException::class);
        expect(CardSale::count())->toBe(0)->and(CardSaleLine::count())->toBe(0)->and(CardStock::sum('quantity'))->toBe(200)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
        expect(CardInventoryMovement::where('kind', 'like', 'SALE_%')->count())->toBe(0);
        $break = false;
        $a = app(RecordCardSaleAction::class);
        $a->execute($admin, $data);
        $a->execute($admin, $data);
        expect(CardSale::count())->toBe(1)->and(CardStock::sum('quantity'))->toBe(194)->and(CardStore::sole()->cash_balance_cents)->toBe(7000);
    } finally {
        CardSaleLine::flushEventListeners();
        CardSaleLine::clearBootedModels();
    }
});
it('C155 rolls back even when the final idempotency audit fails', function () {
    $admin = c155User();
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $data = c155Sale([$p]);
    $break = true;
    CardInventoryCommand::creating(function ($cmd) use (&$break) {
        if ($break && $cmd->action === 'SALE') {
            throw new RuntimeException('audit interruption');
        }
    });
    try {
        expect(fn () => app(RecordCardSaleAction::class)->execute($admin, $data))->toThrow(RuntimeException::class);
        expect(CardSale::count())->toBe(0)->and(CardStock::sole()->quantity)->toBe(100)->and(CardStore::sole()->cash_balance_cents)->toBe(1000);
        $break = false;
        app(RecordCardSaleAction::class)->execute($admin, $data);
        expect(CardSale::count())->toBe(1)->and(CardStock::sole()->quantity)->toBe(97);
    } finally {
        CardInventoryCommand::flushEventListeners();
        CardInventoryCommand::clearBootedModels();
    }
});
it('C155 respects current permission restrictions inactive users and administrative wholesale control', function () {
    $admin = c155User();
    $cashier = c155User('cajera');
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $cashier->restrictedPermissions()->attach(Permission::findByName('tarjetas.ventas.registrar'));
    expect(fn () => app(RecordCardSaleAction::class)->execute($cashier, c155Sale([$p])))->toThrow(AuthorizationException::class);
    $cashier->restrictedPermissions()->detach();
    $cashier->forceFill(['is_active' => false])->save();
    expect(fn () => app(RecordCardSaleAction::class)->execute($cashier, c155Sale([$p])))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveCardWholesaleRulesAction::class)->execute($cashier, ['minimum' => 1, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect(CardSale::count())->toBe(0);
});
it('C155 ignores client forged totals and prevents money overflow', function () {
    $admin = c155User();
    $p = c155Product($admin);
    c155Open($admin, [$p], '999999999.99');
    $data = c155Sale([$p]);
    expect(fn () => app(RecordCardSaleAction::class)->execute($admin, $data + ['total_cents' => 1, 'cash_after_cents' => 1]))->toThrow(ValidationException::class);
    expect(CardSale::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(99999999999);
});
it('C155 preserves immutable sale detail and keeps receipt total authoritative', function () {
    $admin = c155User();
    $p = c155Product($admin);
    c155Open($admin, [$p]);
    $sale = app(RecordCardSaleAction::class)->execute($admin, c155Sale([$p]) + ['total_cents' => 1, 'cash_after_cents' => 1]);
    expect($sale->total_cents)->toBe(3000);
    expect(fn () => $sale->update(['total_cents' => 1]))->toThrow(LogicException::class);
    expect(fn () => $sale->delete())->toThrow(LogicException::class);
    expect(fn () => $sale->lines->sole()->update(['quantity' => 50]))->toThrow(LogicException::class);
    expect(fn () => $sale->lines->sole()->delete())->toThrow(LogicException::class);
});

it('C155 prevents a manager from enabling a nonstandard wholesale cut through product creation', function () {
    $manager = c155User('encargado');
    expect(fn () => app(SaveCardProductAction::class)->execute($manager, ['company' => 'VIVA', 'face_value' => '40', 'purchase_price' => '39.50', 'normal_price' => '40', 'wholesale_price' => '40', 'wholesale_enabled' => true, 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect(CardProduct::count())->toBe(0);
});
