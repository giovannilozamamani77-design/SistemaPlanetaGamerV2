<?php

use App\Domain\Accesorios\Actions\RefreshPricingReferenceAction;
use App\Domain\Accesorios\Actions\SavePricingSettingsAction;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PricingChange;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\PricingQuery;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Infrastructure\Accesorios\ParaleloReferenceClient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryPricingPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function pricingAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'America/La_Paz'));
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryPricingPermissionsSeeder::class]);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $user->assignRole('administrador');

    return $user;
}

function pricingData(array $changes = []): array
{
    return array_replace(['exchange_mode' => 'PHYSICAL', 'physical_rate' => '12.59', 'adjustment' => '0.43', 'auto_refresh' => true,
        'accessory_mode' => 'PERCENT', 'accessory_percent' => '50', 'used_phone_mode' => 'MANUAL', 'new_phone_mode' => 'MANUAL',
        'expected_revision' => 0, 'request_key' => (string) Str::uuid()], $changes);
}

function pricingGlobalRate(string $effective): void
{
    $adjust = PricingSettings::first()?->adjustment_micros ?? 430000;
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => PricingInput::rate($effective, 'test') - $adjust, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', (string) Str::uuid())]);
}

it('C250 saves configuration once and uses the single global API rate plus the difference', function () {
    $admin = pricingAdmin();
    $data = pricingData();
    $action = app(SavePricingSettingsAction::class);
    $event = $action->execute($admin, $data);
    expect($action->execute($admin, $data)->id)->toBe($event->id);
    pricingGlobalRate('12.59');
    $result = app(PricingQuery::class)->simulate($admin, ['kind' => 'NEW_PHONE', 'historical_cost' => '1140', 'replacement_usd' => '100', 'replacement_expenses' => '0', 'manual_price' => '1409']);
    expect($result['replacement_cents'])->toBe(125900)->and($result['base_cents'])->toBe(125900)->and($result['price_cents'])->toBe(140900)->and($result['base_profit_cents'])->toBe(15000)->and($result['effective_rate_micros'])->toBe(12590000);
    expect(PricingSettings::count())->toBe(1)->and(PricingChange::count())->toBe(1)->and(ExchangeQuote::count())->toBe(1);
    Http::assertNothingSent();
});

it('C250 applies markup to BOB accessories without consulting a dollar provider', function () {
    $admin = pricingAdmin();
    app(SavePricingSettingsAction::class)->execute($admin, pricingData());
    $result = app(PricingQuery::class)->simulate($admin, ['kind' => 'ACCESSORY', 'historical_cost' => '10']);
    expect($result['price_cents'])->toBe(1500)->and($result['base_profit_cents'])->toBe(500)->and($result['markup_bps'])->toBe(5000)->and($result['margin_bps'])->toBe(3333)->and($result['effective_rate_micros'])->toBeNull();
    Http::assertNothingSent();
});

it('C250 uses the saved P2P buy reference plus a six-decimal adjustment and does not call the provider during simulation', function () {
    $admin = pricingAdmin();
    app(SavePricingSettingsAction::class)->execute($admin, pricingData(['exchange_mode' => 'REFERENCE', 'adjustment' => '0.442', 'new_phone_mode' => 'PERCENT', 'new_phone_percent' => '20']));
    Http::fake(['paralelo.bo/*' => Http::response(['buy' => 12.148, 'sell' => 12.1, 'timestamp' => now()->toIso8601String()])]);
    $quote = app(RefreshPricingReferenceAction::class)->execute($admin, true);
    $result = app(PricingQuery::class)->simulate($admin, ['kind' => 'NEW_PHONE', 'historical_cost' => '1140', 'replacement_usd' => '100']);
    expect($result['effective_rate_micros'])->toBe(12590000)->and($result['replacement_cents'])->toBe(125900)->and($result['price_cents'])->toBe(151080)->and($result['base_profit_cents'])->toBe(25180)->and($result['quote_id'])->toBe($quote->id);
    Http::assertSentCount(1);
});

it('C250 preserves history while references and settings change and leaves financial ledgers empty', function () {
    $admin = pricingAdmin();
    $first = app(SavePricingSettingsAction::class)->execute($admin, pricingData());
    pricingGlobalRate('12.59');
    $snapshot = app(PricingQuery::class)->simulate($admin, ['kind' => 'NEW_PHONE', 'historical_cost' => '1140', 'replacement_usd' => '100', 'manual_price' => '1290']);
    expect($snapshot['historic_profit_cents'])->toBe(15000)->and($snapshot['base_profit_cents'])->toBe(3100);
    app(SavePricingSettingsAction::class)->execute($admin, pricingData(['physical_rate' => '13.40', 'expected_revision' => 1]));
    pricingGlobalRate('13.40');
    $new = app(PricingQuery::class)->simulate($admin, ['kind' => 'NEW_PHONE', 'historical_cost' => '1140', 'replacement_usd' => '100', 'manual_price' => '1290']);
    expect($new['below_replacement'])->toBeTrue()->and($new['base_profit_cents'])->toBe(-5000);
    expect($snapshot['replacement_cents'])->toBe(125900)->and($first->fresh()->after['physical_rate_micros'])->toBe(12590000);
    expect(LoanMovement::count())->toBe(0)->and(Loan::count())->toBe(0);
});

it('C250 warns below replacement but never permits a price below the historical cost', function () {
    $admin = pricingAdmin();
    app(SavePricingSettingsAction::class)->execute($admin, pricingData());
    pricingGlobalRate('12.59');
    $q = app(PricingQuery::class);
    $result = $q->simulate($admin, ['kind' => 'NEW_PHONE', 'historical_cost' => '1140', 'replacement_usd' => '100', 'manual_price' => '1140']);
    expect($result['below_replacement'])->toBeTrue()->and($result['historic_profit_cents'])->toBe(0)->and($result['base_profit_cents'])->toBe(-11900);
    expect(PricingInput::signedAmount(-11901))->toBe('-119.01');
    expect(fn () => $q->simulate($admin, ['kind' => 'NEW_PHONE', 'historical_cost' => '1140', 'replacement_usd' => '100', 'manual_price' => '1139.99']))->toThrow(ValidationException::class);
    expect(PricingChange::count())->toBe(1);
});

it('C250 keeps the historical floor when the dollar falls and counts replacement expenses once', function () {
    $admin = pricingAdmin();
    app(SavePricingSettingsAction::class)->execute($admin, pricingData(['physical_rate' => '10', 'new_phone_mode' => 'PERCENT', 'new_phone_percent' => '0']));
    pricingGlobalRate('10');
    $q = app(PricingQuery::class);
    $result = $q->simulate($admin, ['kind' => 'NEW_PHONE', 'historical_cost' => '1140', 'replacement_usd' => '100', 'replacement_expenses' => '20']);
    expect($result['replacement_cents'])->toBe(102000)->and($result['base_cents'])->toBe(114000)->and($result['price_cents'])->toBe(114000);
    app(SavePricingSettingsAction::class)->execute($admin, pricingData(['expected_revision' => 1, 'new_phone_mode' => 'PERCENT', 'new_phone_percent' => '0']));
    pricingGlobalRate('12.59');
    $result = $q->simulate($admin, ['kind' => 'NEW_PHONE', 'historical_cost' => '1160', 'replacement_usd' => '100', 'replacement_expenses' => '20']);
    expect($result['replacement_cents'])->toBe(127900)->and($result['price_cents'])->toBe(127900);
});

it('C250 validates invalid settings and money without creating any rows', function (array $change) {
    $admin = pricingAdmin();
    expect(fn () => app(SavePricingSettingsAction::class)->execute($admin, pricingData($change)))->toThrow(ValidationException::class);
    expect(PricingSettings::count())->toBe(0)->and(PricingChange::count())->toBe(0);
})->with([
    'negative rate' => [['physical_rate' => '-1']],
    'zero rate' => [['physical_rate' => '0']],
    'invalid decimal' => [['physical_rate' => '12.34.56']],
    'excess precision' => [['adjustment' => '0.1234567']],
    'invalid percentage' => [['accessory_percent' => '-1']],
    'overflow percentage' => [['accessory_percent' => '10000.01']],
    'unsupported mode' => [['exchange_mode' => 'GOOGLE']],
    'missing percent' => [['accessory_percent' => '']],
]);

it('C250 supports BOB previews without a configured dollar and rounds half cents explicitly', function () {
    $admin = pricingAdmin();
    app(SavePricingSettingsAction::class)->execute($admin, pricingData(['physical_rate' => '']));
    $q = app(PricingQuery::class);
    $result = $q->simulate($admin, ['kind' => 'ACCESSORY', 'historical_cost' => '0.01']);
    expect($result['price_cents'])->toBe(2);
    expect(fn () => $q->simulate($admin, ['kind' => 'NEW_PHONE', 'historical_cost' => '1', 'replacement_usd' => '1', 'manual_price' => '2']))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('C250 rejects stale configuration conflicting UUID payloads and another actor replay', function () {
    $admin = pricingAdmin();
    $act = app(SavePricingSettingsAction::class);
    $data = pricingData();
    $act->execute($admin, $data);
    expect(fn () => $act->execute($admin, pricingData()))->toThrow(ValidationException::class);
    expect(fn () => $act->execute($admin, array_replace($data, ['physical_rate' => '13'])))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $act->execute($other, $data))->toThrow(AuthorizationException::class);
    expect(PricingSettings::sole()->revision)->toBe(1)->and(PricingChange::count())->toBe(1);
});

it('C250 refuses cashier inactive and restricted administrator access to every operation', function (string $case) {
    $admin = pricingAdmin();
    $actor = $admin;
    if ($case === 'cashier') {
        $actor = User::factory()->create();
        $actor->assignRole('cajera');
        $actor->givePermissionTo('accesorios.precios.configurar');
    }
    if ($case === 'inactive') {
        $actor->forceFill(['is_active' => false])->save();
    }
    if ($case === 'restricted') {
        $actor->restrictedPermissions()->attach(Permission::findByName('accesorios.precios.configurar'));
    }
    expect(fn () => app(SavePricingSettingsAction::class)->execute($actor, pricingData()))->toThrow(AuthorizationException::class);
    expect(fn () => app(PricingQuery::class)->simulate($actor, ['kind' => 'ACCESSORY', 'historical_cost' => '10']))->toThrow(AuthorizationException::class);
    expect(fn () => app(RefreshPricingReferenceAction::class)->execute($actor, true))->toThrow(AuthorizationException::class);
    expect(PricingSettings::count())->toBe(0);
    Http::assertNothingSent();
})->with(['cashier', 'inactive', 'restricted']);

it('C250 rolls back an interrupted change and retries once without rewriting the previous audit', function () {
    $admin = pricingAdmin();
    $act = app(SavePricingSettingsAction::class);
    $first = $act->execute($admin, pricingData());
    $data = pricingData(['expected_revision' => 1, 'physical_rate' => '13']);
    DB::statement("CREATE TRIGGER fail_pricing BEFORE INSERT ON accessory_pricing_changes BEGIN SELECT RAISE(ABORT,'interrupted'); END");
    expect(fn () => $act->execute($admin, $data))->toThrow(QueryException::class);
    expect(PricingSettings::sole()->revision)->toBe(1)->and(PricingSettings::sole()->physical_rate_micros)->toBe(12590000);
    DB::statement('DROP TRIGGER fail_pricing');
    $second = $act->execute($admin, $data);
    $act->execute($admin, $data);
    expect(PricingChange::count())->toBe(2)->and($first->fresh()->after['revision'])->toBe(1)->and($second->after['revision'])->toBe(2);
    expect(fn () => $first->update(['actor_name' => 'Changed']))->toThrow(LogicException::class);
});

it('C250 rejects broken external references while retaining the previous valid value', function (array $body, int $status) {
    $admin = pricingAdmin();
    $action = app(RefreshPricingReferenceAction::class);
    Http::fake(['paralelo.bo/*' => Http::sequence()->push(['buy' => 12.16, 'timestamp' => now()->toIso8601String()])->push($body, $status)]);
    $first = $action->execute($admin, true);
    expect(fn () => $action->execute($admin, true))->toThrow(ValidationException::class);
    expect(ExchangeQuote::count())->toBe(1)->and(app(PricingQuery::class)->latestQuote()->id)->toBe($first->id);
})->with([
    'http failure' => [[], 500],
    'zero price' => [['buy' => 0, 'timestamp' => '2026-10-28T16:00:00Z'], 200],
    'missing timestamp' => [['buy' => 12.16], 200],
    'future timestamp' => [['buy' => 12.16, 'timestamp' => '2035-01-01T00:00:00Z'], 200],
    'bad value' => [['buy' => 'not-a-rate', 'timestamp' => '2026-10-28T16:00:00Z'], 200],
]);

it('C250 deduplicates reference snapshots ignores older responses and never writes pricing history from refresh', function () {
    $admin = pricingAdmin();
    $a = app(RefreshPricingReferenceAction::class);
    Http::fake(['paralelo.bo/*' => Http::sequence()->push(['buy' => 12.16, 'timestamp' => '2026-10-28T16:00:00Z'])->push(['buy' => 12.16, 'timestamp' => '2026-10-28T16:00:00Z'])->push(['buy' => 11, 'timestamp' => '2026-10-28T15:00:00Z'])]);
    $first = $a->execute($admin, true);
    $same = $a->execute($admin, true);
    $older = $a->execute($admin, true);
    expect($same->id)->toBe($first->id)->and($older->id)->toBe($first->id)->and(ExchangeQuote::count())->toBe(1)->and(PricingChange::count())->toBe(0);
});

it('C250 schedules refresh only when requested and throttles recent references without network calls from reads', function () {
    $admin = pricingAdmin();
    $a = app(RefreshPricingReferenceAction::class);
    app(SavePricingSettingsAction::class)->execute($admin, pricingData(['auto_refresh' => false]));
    expect($a->execute())->toBeNull();
    Http::assertNothingSent();
    app(SavePricingSettingsAction::class)->execute($admin, pricingData(['exchange_mode' => 'REFERENCE', 'expected_revision' => 1]));
    Http::fake(['paralelo.bo/*' => Http::response(['buy' => 12.16, 'timestamp' => now()->toIso8601String()])]);
    $a->execute();
    $a->execute();
    Http::assertSentCount(1);
    $this->artisan('accesorios:actualizar-referencia')->assertSuccessful();
    Http::assertSentCount(1);
    expect(PricingChange::count())->toBe(2);
});

it('C277 requires percentage for automatic prices and preserves a configurable seventy cent adjustment', function () {
    $u = pricingAdmin();
    expect(fn () => app(SavePricingSettingsAction::class)->execute($u, pricingData(['new_phone_auto_update' => true])))->toThrow(ValidationException::class);
    $event = app(SavePricingSettingsAction::class)->execute($u, pricingData(['adjustment' => '0.70', 'new_phone_mode' => 'PERCENT', 'new_phone_percent' => '15', 'new_phone_auto_update' => true]));
    expect($event->after['rules']['NEW_PHONE']['auto_update'])->toBeTrue()->and($event->after['adjustment_micros'])->toBe(700000);
    app(SavePricingSettingsAction::class)->execute($u, pricingData(['expected_revision' => 1, 'new_phone_auto_update' => false]));
    expect(app(PricingQuery::class)->form($u)['new_phone_auto_update'])->toBeFalse();
});

it('C278 validates HTTPS using the configured trusted CA bundle instead of disabling certificate checks', function () {
    pricingAdmin();
    config(['accesorios.reference_ca_bundle' => 'C:/test/trusted-ca.pem']);
    $seen = null;
    Http::fake(function ($request, array $options) use (&$seen) {
        $seen = $options['verify'] ?? null;

        return Http::response(['buy' => 11, 'timestamp' => now()->toIso8601String()]);
    });
    app(ParaleloReferenceClient::class)->fetch();
    expect($seen)->toBe('C:/test/trusted-ca.pem');
});

it('C278 converts one USD purchase with expenses once and keeps original-cost protection optional', function () {
    $u = pricingAdmin();
    app(SavePricingSettingsAction::class)->execute($u, pricingData(['physical_rate' => '11.7', 'new_phone_mode' => 'PERCENT', 'new_phone_percent' => '10']));
    pricingGlobalRate('11.7');
    $q = app(PricingQuery::class);
    $d = ['kind' => 'NEW_PHONE', 'purchase_usd' => '100', 'replacement_expenses' => '10', 'compare_original_cost' => false];
    $a = $q->simulate($u, $d);
    expect($a['historical_cents'])->toBe(118000)->and($a['replacement_cents'])->toBe(118000)->and($a['price_cents'])->toBe(129800);
    $b = $q->simulate($u, array_replace($d, ['compare_original_cost' => true, 'historical_cost' => '1300']));
    expect($b['base_cents'])->toBe(130000)->and($b['price_cents'])->toBe(143000);
    Http::assertNothingSent();
});

it('C279 keeps sale rules when saving the dollar and keeps the dollar when saving rules', function () {
    $u = pricingAdmin();
    $a = app(SavePricingSettingsAction::class);
    $a->execute($u, pricingData());
    $rules = PricingSettings::first()->rules;
    $d = ['adjustment' => '0.70', 'expected_revision' => 1, 'request_key' => (string) Str::uuid()];
    $event = $a->executeDollar($u, $d);
    expect(PricingSettings::first()->rules)->toBe($rules)->and($a->executeDollar($u, $d)->id)->toBe($event->id);
    $a->executeRules($u, ['accessory_mode' => 'PERCENT', 'accessory_percent' => '60', 'expected_revision' => 2, 'request_key' => (string) Str::uuid(), 'adjustment' => '99']);
    expect(PricingSettings::first()->adjustment_micros)->toBe(700000)->and(PricingSettings::first()->rules['ACCESSORY']['markup_bps'])->toBe(6000);
    expect(fn () => $a->executeDollar($u, ['adjustment' => '0.20', 'expected_revision' => 1, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(fn () => $a->executeDollar($u, ['adjustment' => '0.20', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(PricingChange::count())->toBe(3);
});
