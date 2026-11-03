<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Actions\SaveCommercialRulesAction;
use App\Domain\Accesorios\Calculators\SaleDiscountCalculator;
use App\Domain\Accesorios\Data\AccessorySaleInput;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Models\CommercialRuleChange;
use App\Domain\Accesorios\Models\CommercialRules;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Domain\Comisiones\Calculators\CommissionCalculator;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Filament\Pages\Accesorios\SalesRulesConfiguration;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessoryPricingPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);
it('C293 enforces configured accessory and phone caps independently of cost', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $c = app(SaleDiscountCalculator::class);
    expect($c->limited(5000, 2000, 500, 'ACCESSORY', 1))->toBe(4500);
    expect(fn () => $c->limited(4999, 1000, 1, 'ACCESSORY', 1))->toThrow(ValidationException::class);
    expect($c->limited(139000, 126800, 3000, 'PHONE', 1))->toBe(136000);
    expect(fn () => $c->limited(139000, 100000, 3001, 'PHONE', 1))->toThrow(ValidationException::class);
});
it('C293 commissions use continuous original accessory brackets and final phone profit', function () {
    $c = app(CommissionCalculator::class);
    foreach ([999 => 0, 1000 => 100, 10000 => 100, 10001 => 200, 20000 => 200, 20001 => 400, 49999 => 400, 50000 => 600] as $price => $commission) {
        expect($c->calculate(['unit_price_cents' => $price, 'quantity' => 1, 'total_cents' => $price, 'cost_cents' => 0]))->toBe($commission);
    }
    expect($c->calculate(['unit_price_cents' => 5000, 'quantity' => 2, 'total_cents' => 9000, 'cost_cents' => 2000]))->toBe(200);
    expect($c->calculate(['unit_price_cents' => 5000, 'quantity' => 1, 'total_cents' => 4500, 'cost_cents' => 4450]))->toBe(50);
    expect($c->calculate(['kind' => 'PHONE', 'unit_price_cents' => 95000, 'quantity' => 1, 'total_cents' => 92000, 'cost_cents' => 80000]))->toBe(2400);
});

function c293Admin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryPricingPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    Http::preventStrayRequests();
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
function c293Config(string $scope): array
{
    $r = CommercialRules::defaults($scope);

    return ['scope' => $scope, 'expected_revision' => 0, 'request_key' => (string) Str::uuid(), 'enabled' => true, 'phone_max' => '30', 'phone_percent' => '20', 'ranges' => array_map(fn ($v) => ['from' => number_format($v['from_cents'] / 100, 2, '.', ''), 'amount' => number_format($v['amount_cents'] / 100, 2, '.', '')], $r['ranges'])];
}
it('C293 audits settings with retries, rejects stale changes, and denies cashier configuration', function () {
    $u = c293Admin();
    $a = app(SaveCommercialRulesAction::class);
    $data = c293Config('DISCOUNTS');
    $a->execute($u, $data);
    $a->execute($u, $data);
    expect(CommercialRules::sole()->revision)->toBe(1)->and(CommercialRuleChange::count())->toBe(1);
    $data['request_key'] = (string) Str::uuid();
    expect(fn () => $a->execute($u, $data))->toThrow(ValidationException::class);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => $a->execute($cashier, $data))->toThrow(HttpException::class);
    foreach ([['from' => '1', 'amount' => '0'], ['from' => '0', 'amount' => '1']] as $row) {
        $bad = c293Config('DISCOUNTS');
        $bad['ranges'] = [$row];
        if ($row['from'] === '0') {
            $bad['ranges'][] = $row;
        }
        expect(fn () => $a->execute($u, $bad))->toThrow(ValidationException::class);
    }
});
it('C293 freezes commissions exactly once and preserves cash and cost when retrying or disabling incentives', function () {
    $u = c293Admin();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $p = app(CreateAccessoryProductAction::class)->execute($u, ['code' => 'C293', 'name' => 'Cable prueba', 'sale_price' => '100', 'request_key' => (string) Str::uuid()]);
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $p->id, 'quantity' => 4, 'unit_cost' => '50', 'kind' => 'INITIAL', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $p->refresh();
    $data = ['lines' => [['product_id' => $p->id, 'quantity' => 2, 'expected_revision' => $p->revision, 'expected_price_cents' => $p->sale_price_cents]], 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => (string) Str::uuid()];
    $data = AccessorySaleInput::withFinalPrices($data, ['a_'.$p->id => '190']);
    $preview = app(AccessorySaleQuery::class)->preview($cashier, $data);
    expect($preview['commission_cents'])->toBe(200)->and($preview['total_cents'])->toBe(19000);
    expect($preview['lines'][0])->not->toHaveKey('cost_cents');
    $data['rules_revisions'] = $preview['rules_revisions'];
    $action = app(RegisterAccessorySaleAction::class);
    $sale = $action->execute($cashier, $data);
    $entry = CommissionEntry::sole();
    expect($entry->amount_cents)->toBe(200)->and($entry->user_id)->toBe($cashier->id)->and($entry->snapshot['commission_rules']['phone_bps'])->toBe(2000);
    expect($sale->total_cents)->toBe(19000)->and(CommercialFund::sole()->balance_cents)->toBe(19000);
    $settings = c293Config('COMMISSIONS');
    $settings['enabled'] = false;
    app(SaveCommercialRulesAction::class)->execute($u, $settings);
    expect($action->execute($cashier, $data)->id)->toBe($sale->id)->and(CommissionEntry::count())->toBe(1)->and($entry->fresh()->amount_cents)->toBe(200);
    $p->refresh();
    $data['request_key'] = (string) Str::uuid();
    $data['lines'][0]['expected_revision'] = $p->revision;
    expect(fn () => $action->execute($cashier, $data))->toThrow(ValidationException::class);
    unset($data['rules_revisions']);
    $new = $action->execute($cashier, $data);
    expect(CommissionEntry::where('sale_line_id', $new->lines->sole()->id)->sole()->amount_cents)->toBe(0);
    expect(CommercialFund::sole()->balance_cents)->toBe(38000)->and($p->fresh()->quantity)->toBe(0);
    Http::assertNothingSent();
});
it('C293 configuration modals open directly, persist approved values and are inaccessible to cashiers', function () {
    $u = c293Admin();
    test()->actingAs($u);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $page = Livewire::test(SalesRulesConfiguration::class)->assertSee('Descuentos')->assertSee('Comisiones')->mountAction('discounts')->assertActionMounted('discounts');
    $data = c293Config('DISCOUNTS');
    unset($data['scope']);
    $page->setActionData($data)->callMountedAction()->assertHasNoErrors();
    expect(CommercialRules::where('scope', 'DISCOUNTS')->sole()->rules['phone_max_cents'])->toBe(3000);
    $page->mountAction('commissions')->assertActionMounted('commissions');
    $data = c293Config('COMMISSIONS');
    unset($data['scope']);
    $page->setActionData($data)->callMountedAction()->assertHasNoErrors();
    expect(CommercialRules::where('scope', 'COMMISSIONS')->sole()->rules['phone_bps'])->toBe(2000);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    test()->actingAs($cashier);
    expect(SalesRulesConfiguration::canAccess())->toBeFalse();
    Livewire::test(SalesRulesConfiguration::class)->assertForbidden();
});

it('C293 rejects a rebate outside its band and rolls back commission stock and cash on interruption', function () {
    $u = c293Admin();
    $p = app(CreateAccessoryProductAction::class)->execute($u, ['code' => 'INT-C293', 'name' => 'Cable prueba', 'sale_price' => '50', 'request_key' => (string) Str::uuid()]);
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $p->id, 'quantity' => 2, 'unit_cost' => '10', 'kind' => 'INITIAL', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $p->refresh();
    $data = ['lines' => [['product_id' => $p->id, 'quantity' => 1, 'expected_revision' => $p->revision, 'expected_price_cents' => 5000, 'discount' => '5.01']], 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => (string) Str::uuid()];
    $action = app(RegisterAccessorySaleAction::class);
    expect(fn () => $action->execute($u, $data))->toThrow(ValidationException::class);
    expect(CommissionEntry::count())->toBe(0);
    $data['lines'][0]['discount'] = '5';
    DB::unprepared("CREATE TRIGGER c293_interrupt BEFORE INSERT ON commission_entries BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    expect(fn () => $action->execute($u, $data))->toThrow(QueryException::class);
    expect(AccessorySale::count())->toBe(0)->and(CommissionEntry::count())->toBe(0)->and(CommercialMovement::count())->toBe(0)->and($p->fresh()->quantity)->toBe(2);
    DB::unprepared('DROP TRIGGER c293_interrupt');
    $sale = $action->execute($u, $data);
    $action->execute($u, $data);
    expect($sale->total_cents)->toBe(4500)->and(CommissionEntry::sole()->amount_cents)->toBe(100)->and(CommercialMovement::count())->toBe(1);
});
