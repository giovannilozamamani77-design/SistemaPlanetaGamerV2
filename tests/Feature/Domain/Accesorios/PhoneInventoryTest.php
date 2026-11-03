<?php

use App\Domain\Accesorios\Actions\RefreshAutomaticPhonePricesAction;
use App\Domain\Accesorios\Actions\RefreshPricingReferenceAction;
use App\Domain\Accesorios\Actions\RegisterPhoneUnitAction;
use App\Domain\Accesorios\Actions\SavePricingSettingsAction;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PhoneImei;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PhoneVariant;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\PhoneInventoryQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessoryPricingPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function phoneAdmin(): User
{
    test()->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'America/La_Paz'));
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class]);
    Storage::fake('local');
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
function phoneData(array $changes = []): array
{
    return array_replace(['brand' => 'Xiaomi', 'model' => 'Redmi Note', 'ram_gb' => 6, 'storage_gb' => 128, 'condition' => 'USED', 'color' => 'Azul', 'imei1' => '012345678901234', 'imei2' => null, 'physical_state' => 'En buen estado', 'included_accessories' => 'Cable', 'origin' => 'Proveedor de prueba', 'purchase_cost' => '800', 'purchase_rate' => null, 'expenses' => '0', 'price_mode' => 'MANUAL', 'sale_price' => '950', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()], $changes);
}
function phoneGlobalRate(int $effective): void
{
    $adjust = PricingSettings::first()?->adjustment_micros ?? 700000;
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => $effective - $adjust, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', (string) Str::uuid())]);
}

it('C263 registers an individual used phone and shared catalog without financial movements', function () {
    $u = phoneAdmin();
    $a = app(RegisterPhoneUnitAction::class);
    $p = $a->execute($u, phoneData());
    $b = $a->execute($u, phoneData(['brand' => ' XIAOMI ', 'model' => ' redmi note ', 'imei1' => '112345678901234']));
    expect($p->historical_cost_cents)->toBe(80000)->and($p->sale_price_cents)->toBe(95000)->and($p->status)->toBe('AVAILABLE');
    expect($p->imeis->first()->imei)->toBe('012345678901234')->and($p->variant_id)->toBe($b->variant_id);
    expect(PhoneVariant::withCount('availableUnits')->first()->available_units_count)->toBe(2);
    expect(DB::table('accessory_commercial_movements')->count())->toBe(0)->and(DB::table('accessory_stock_entries')->count())->toBe(0);
});
it('C263 prevents duplicate IMEIs across both slots and repeated identifiers on the same phone', function () {
    $u = phoneAdmin();
    $a = app(RegisterPhoneUnitAction::class);
    $a->execute($u, phoneData(['imei2' => '212345678901234']));
    foreach ([['imei1' => '212345678901234'], ['imei1' => '312345678901234', 'imei2' => '012345678901234'], ['imei1' => '412345678901234', 'imei2' => '412345678901234'], ['imei1' => '123']] as $change) {
        expect(fn () => $a->execute($u, phoneData($change)))->toThrow(ValidationException::class);
    }
    expect(PhoneUnit::count())->toBe(1)->and(PhoneImei::count())->toBe(2);
});
it('C263 retries without duplicating units and rejects changed requests or another actor', function () {
    $u = phoneAdmin();
    $d = phoneData();
    $a = app(RegisterPhoneUnitAction::class);
    $p = $a->execute($u, $d);
    expect($a->execute($u, $d)->id)->toBe($p->id);
    expect(fn () => $a->execute($u, array_replace($d, ['sale_price' => '960'])))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $a->execute($other, $d))->toThrow(AuthorizationException::class);
    expect(PhoneUnit::count())->toBe(1);
});
it('C263 keeps variants distinct and calculates new USD cost with the actual exchange rate', function () {
    $u = phoneAdmin();
    $s = PricingInput::defaults();
    $s['physical_rate_micros'] = 12590000;
    phoneGlobalRate(12590000);
    $s['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1000];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    $p = app(RegisterPhoneUnitAction::class)->execute($u, phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11.4', 'expenses' => '10', 'price_mode' => 'CONFIGURED', 'sale_price' => null]));
    expect($p->purchase_currency)->toBe('USD')->and($p->historical_cost_cents)->toBe(126900)->and($p->sale_price_cents)->toBe(139590);
    $second = app(RegisterPhoneUnitAction::class)->execute($u, phoneData(['imei1' => '312345678901234', 'ram_gb' => 8, 'storage_gb' => 256]));
    expect($second->variant_id)->not->toBe($p->variant_id)->and(PhoneVariant::count())->toBe(2);
    expect($p->pricing_snapshot['replacement_cents'])->toBe(126900);
});
it('C263 rejects below historical cost and stale configuration or review', function () {
    $u = phoneAdmin();
    $q = app(PhoneInventoryQuery::class);
    $a = app(RegisterPhoneUnitAction::class);
    $d = phoneData();
    $review = $q->review($u, $d);
    expect(fn () => $a->execute($u, phoneData(['sale_price' => '799'])))->toThrow(ValidationException::class);
    expect(fn () => $a->execute($u, phoneData(['expected_pricing_revision' => 5])))->toThrow(ValidationException::class);
    expect(fn () => $a->execute($u, array_replace($d, ['color' => 'Rojo']), null, $review['fingerprint']))->toThrow(ValidationException::class);
    expect(PhoneUnit::count())->toBe(0);
});
it('C263 rolls back catalog and image on interrupted IMEI persistence and permits retry', function () {
    $u = phoneAdmin();
    $d = phoneData();
    $file = UploadedFile::fake()->image('phone.jpg');
    DB::statement("CREATE TRIGGER fail_phone_imei BEFORE INSERT ON phone_imeis BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    expect(fn () => app(RegisterPhoneUnitAction::class)->execute($u, $d, $file))->toThrow(QueryException::class);
    expect(PhoneUnit::count())->toBe(0)->and(DB::table('phone_brands')->count())->toBe(0)->and(Storage::disk('local')->allFiles('accessories/phones'))->toBe([]);
    DB::statement('DROP TRIGGER fail_phone_imei');
    $p = app(RegisterPhoneUnitAction::class)->execute($u, $d, $file);
    expect(Storage::disk('local')->exists($p->image_path))->toBeTrue();
});
it('C263 denies cashier registration and rechecks revoked permissions', function () {
    $u = phoneAdmin();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => app(RegisterPhoneUnitAction::class)->execute($cashier, phoneData()))->toThrow(AuthorizationException::class);
    $u->removeRole('administrador');
    expect(fn () => app(RegisterPhoneUnitAction::class)->execute($u, phoneData()))->toThrow(AuthorizationException::class);
    expect(PhoneUnit::count())->toBe(0);
});

it('C263 freezes historical costs after configuration changes and warns about replacement shortfall', function () {
    $u = phoneAdmin();
    $s = PricingInput::defaults();
    $s['physical_rate_micros'] = 12590000;
    phoneGlobalRate(11400000);
    $settings = PricingSettings::create(['code' => 'GENERAL'] + $s);
    $d = phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11.4', 'sale_price' => '1200', 'expenses' => '0']);
    $p = app(RegisterPhoneUnitAction::class)->execute($u, $d);
    expect($p->pricing_snapshot['below_replacement'])->toBeFalse()->and($p->historical_cost_cents)->toBe(114000);
    $settings->update(['physical_rate_micros' => 14000000, 'revision' => 1]);
    phoneGlobalRate(14000000);
    expect($p->fresh()->historical_cost_cents)->toBe(114000)->and($p->fresh()->sale_price_cents)->toBe(120000)->and($p->fresh()->pricing_snapshot['effective_rate_micros'])->toBe(11400000);
    expect(app(RegisterPhoneUnitAction::class)->execute($u, $d)->id)->toBe($p->id);
});
it('C263 enforces global IMEI uniqueness at the database boundary and ignores forged stock or status', function () {
    $u = phoneAdmin();
    $a = app(RegisterPhoneUnitAction::class);
    $p = $a->execute($u, phoneData(['status' => 'SOLD', 'historical_cost_cents' => 1]));
    $b = $a->execute($u, phoneData(['imei1' => '512345678901234']));
    expect($p->status)->toBe('AVAILABLE')->and($p->historical_cost_cents)->toBe(80000);
    expect(fn () => PhoneImei::create(['unit_id' => $b->id, 'slot' => 2, 'imei' => '012345678901234']))->toThrow(QueryException::class);
    expect(PhoneImei::count())->toBe(2);
});
it('C263 rechecks the current reference between review and confirm without changing prices silently', function () {
    $u = phoneAdmin();
    $s = PricingInput::defaults();
    $s['physical_rate_micros'] = 12000000;
    phoneGlobalRate(12000000);
    $s['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1000];
    $settings = PricingSettings::create(['code' => 'GENERAL'] + $s);
    $d = phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11', 'price_mode' => 'CONFIGURED', 'sale_price' => null]);
    $review = app(PhoneInventoryQuery::class)->review($u, $d);
    $settings->update(['physical_rate_micros' => 13000000]);
    phoneGlobalRate(13000000);
    expect(fn () => app(RegisterPhoneUnitAction::class)->execute($u, $d, null, $review['fingerprint']))->toThrow(ValidationException::class);
    expect(PhoneUnit::count())->toBe(0);
});

it('C277 updates only automatic available new phones with reference plus adjustment and keeps original costs', function () {
    $u = phoneAdmin();
    test()->seed(AccessoryPricingPermissionsSeeder::class);
    $s = PricingInput::defaults();
    $s['exchange_mode'] = 'REFERENCE';
    $s['adjustment_micros'] = 700000;
    $s['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1000, 'auto_update' => true];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    $q = ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 11000000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'first')]);
    $a = app(RegisterPhoneUnitAction::class);
    $new = $a->execute($u, phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11.7', 'price_mode' => 'CONFIGURED', 'sale_price' => null]));
    $manual = $a->execute($u, phoneData(['imei1' => '112345678901234', 'condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11.7', 'sale_price' => '1400']));
    $used = $a->execute($u, phoneData(['imei1' => '212345678901234']));
    $reserved = $a->execute($u, phoneData(['imei1' => '312345678901234', 'condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11.7', 'price_mode' => 'CONFIGURED', 'sale_price' => null]));
    $reserved->update(['status' => 'RESERVED']);
    $sold = $a->execute($u, phoneData(['imei1' => '412345678901234', 'condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11.7', 'price_mode' => 'CONFIGURED', 'sale_price' => null]));
    $sold->update(['status' => 'SOLD']);
    test()->travel(1)->minutes();
    $q = ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 12000000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'second')]);
    $action = app(RefreshAutomaticPhonePricesAction::class);
    expect($action->execute($u))->toBe(1)->and($new->fresh()->sale_price_cents)->toBe(139700);
    expect($new->fresh()->historical_cost_cents)->toBe(117000)->and($new->fresh()->purchase_rate_micros)->toBe(11700000);
    expect($manual->fresh()->sale_price_cents)->toBe(140000)->and($used->fresh()->sale_price_cents)->toBe(95000);
    expect($reserved->fresh()->sale_price_cents)->toBe(128700)->and($sold->fresh()->sale_price_cents)->toBe(128700);
    expect($action->execute($u))->toBe(0)->and(DB::table('phone_price_changes')->count())->toBe(1);
    $settings = PricingSettings::first();
    $rules = $settings->rules;
    $rules['NEW_PHONE']['auto_update'] = false;
    $settings->update(['rules' => $rules]);
    test()->travel(1)->minutes();
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 13000000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'third')]);
    expect($action->execute($u))->toBe(0)->and($new->fresh()->sale_price_cents)->toBe(139700);
});

it('C277 refuses stale automatic references and unauthorised repricing', function () {
    $u = phoneAdmin();
    test()->seed(AccessoryPricingPermissionsSeeder::class);
    $s = PricingInput::defaults();
    $s['exchange_mode'] = 'REFERENCE';
    $s['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1000, 'auto_update' => true];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 11000000, 'source_at' => now()->subDays(2), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'old')]);
    expect(app(RefreshAutomaticPhonePricesAction::class)->execute($u))->toBe(0);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => app(RefreshAutomaticPhonePricesAction::class)->execute($cashier))->toThrow(AuthorizationException::class);
});

it('C277 calculates automatic USD registration server side and ignores a forged manual exchange rate', function () {
    $u = phoneAdmin();
    $s = PricingInput::defaults();
    $s['exchange_mode'] = 'REFERENCE';
    $s['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1000];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 11000000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'register-auto')]);
    $d = phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '1', 'use_operating_rate' => true, 'price_mode' => 'CONFIGURED', 'sale_price' => null]);
    $preview = app(PhoneInventoryQuery::class)->registrationPreview($u, $d);
    expect($preview['rate'])->toBe('11.7')->and($preview['cost'])->toBe('1170');
    $phone = app(RegisterPhoneUnitAction::class)->execute($u, $d);
    expect($phone->purchase_rate_micros)->toBe(11700000)->and($phone->historical_cost_cents)->toBe(117000)->and($phone->sale_price_cents)->toBe(128700);
    expect(app(RegisterPhoneUnitAction::class)->execute($u, $d)->id)->toBe($phone->id);
});

it('C277 rolls back price and audit together when writing the price fails', function () {
    $u = phoneAdmin();
    test()->seed(AccessoryPricingPermissionsSeeder::class);
    $s = PricingInput::defaults();
    $s['physical_rate_micros'] = 11000000;
    phoneGlobalRate(11000000);
    $s['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1000, 'auto_update' => true];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    $phone = app(RegisterPhoneUnitAction::class)->execute($u, phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11', 'price_mode' => 'CONFIGURED', 'sale_price' => null]));
    PricingSettings::first()->update(['physical_rate_micros' => 12000000]);
    phoneGlobalRate(12000000);
    DB::unprepared("CREATE TRIGGER c277_price_failure BEFORE UPDATE OF sale_price_cents ON phone_units BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    expect(fn () => app(RefreshAutomaticPhonePricesAction::class)->execute($u))->toThrow(QueryException::class);
    expect($phone->fresh()->sale_price_cents)->toBe(121000)->and(DB::table('phone_price_changes')->count())->toBe(0);
});

it('C277 refreshes automatic prices once per quote and keeps the price when the API fails', function () {
    $u = phoneAdmin();
    test()->seed(AccessoryPricingPermissionsSeeder::class);
    Http::preventStrayRequests();
    $s = PricingInput::defaults();
    $s['exchange_mode'] = 'REFERENCE';
    $s['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1000, 'auto_update' => true];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 11000000, 'source_at' => now()->subMinutes(31), 'fetched_at' => now()->subMinutes(31), 'fingerprint' => hash('sha256', 'integration-old')]);
    $phone = app(RegisterPhoneUnitAction::class)->execute($u, phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11.7', 'price_mode' => 'CONFIGURED', 'sale_price' => null]));
    Http::fake(['paralelo.bo/*' => Http::sequence()->push(['buy' => 12, 'timestamp' => now()->toIso8601String()])->push([], 503)]);
    $action = app(RefreshPricingReferenceAction::class);
    $action->execute();
    $action->execute();
    expect($phone->fresh()->sale_price_cents)->toBe(139700)->and(DB::table('phone_price_changes')->count())->toBe(1);
    Http::assertSentCount(1);
    test()->travel(31)->minutes();
    expect(fn () => $action->execute())->toThrow(ValidationException::class);
    expect($phone->fresh()->sale_price_cents)->toBe(139700)->and(DB::table('phone_price_changes')->count())->toBe(1);
});

it('C277 respects active reservations but updates logically available expired reservations', function () {
    $u = phoneAdmin();
    test()->seed(AccessoryPricingPermissionsSeeder::class);
    $s = PricingInput::defaults();
    $s['physical_rate_micros'] = 11000000;
    phoneGlobalRate(11000000);
    $s['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1000, 'auto_update' => true];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    $p = app(RegisterPhoneUnitAction::class)->execute($u, phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11', 'price_mode' => 'CONFIGURED', 'sale_price' => null]));
    $reservation = PhoneReservation::create(['unit_id' => $p->id, 'user_id' => $u->id, 'actor_name' => $u->name, 'cart_key' => (string) Str::uuid(), 'status' => 'ACTIVE', 'price_cents' => $p->sale_price_cents, 'expires_at' => now()->addMinutes(5), 'created_at' => now()]);
    $p->update(['status' => 'RESERVED', 'reservation_id' => $reservation->id]);
    PricingSettings::first()->update(['physical_rate_micros' => 12000000]);
    phoneGlobalRate(12000000);
    $a = app(RefreshAutomaticPhonePricesAction::class);
    expect($a->execute($u))->toBe(0)->and($p->fresh()->sale_price_cents)->toBe(121000);
    test()->travel(6)->minutes();
    expect($a->execute($u))->toBe(1)->and($p->fresh()->sale_price_cents)->toBe(132000);
    expect($reservation->fresh()->price_cents)->toBe(121000)->and($reservation->fresh()->status)->toBe('ACTIVE');
});

it('C278 reads a display quote without repricing even when automatic prices are enabled', function () {
    $u = phoneAdmin();
    test()->seed(AccessoryPricingPermissionsSeeder::class);
    $s = PricingInput::defaults();
    $s['exchange_mode'] = 'REFERENCE';
    $s['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1000, 'auto_update' => true];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 11000000, 'source_at' => now()->subMinutes(31), 'fetched_at' => now()->subMinutes(31), 'fingerprint' => hash('sha256', 'display-old')]);
    $p = app(RegisterPhoneUnitAction::class)->execute($u, phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11.7', 'price_mode' => 'CONFIGURED', 'sale_price' => null]));
    Http::fake(['paralelo.bo/*' => Http::response(['buy' => 12, 'timestamp' => now()->toIso8601String()])]);
    app(RefreshPricingReferenceAction::class)->execute($u, false, true);
    expect($p->fresh()->sale_price_cents)->toBe(128700)->and(DB::table('phone_price_changes')->count())->toBe(0)->and(ExchangeQuote::count())->toBe(2);
});

it('C279 uses one global USD rate even with forged per-phone manual values and preserves already registered costs', function () {
    $u = phoneAdmin();
    test()->seed(AccessoryPricingPermissionsSeeder::class);
    phoneGlobalRate(11700000);
    $a = app(RegisterPhoneUnitAction::class);
    $d = phoneData(['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '1', 'use_operating_rate' => false, 'sale_price' => '1500']);
    $first = $a->execute($u, $d);
    $second = $a->execute($u, array_replace($d, ['imei1' => '612345678901234', 'purchase_rate' => '999', 'request_key' => (string) Str::uuid()]));
    expect($first->purchase_rate_micros)->toBe(11700000)->and($second->purchase_rate_micros)->toBe(11700000);
    $save = app(SavePricingSettingsAction::class);
    $data = ['adjustment' => '0.50', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()];
    $event = $save->executeDollar($u, $data);
    expect($save->executeDollar($u, $data)->id)->toBe($event->id);
    $third = $a->execute($u, array_replace($d, ['imei1' => '712345678901234', 'expected_pricing_revision' => 1, 'request_key' => (string) Str::uuid()]));
    expect($third->purchase_rate_micros)->toBe(11500000)->and($first->fresh()->historical_cost_cents)->toBe(117000)->and($first->fresh()->sale_price_cents)->toBe(150000);
    expect($a->execute($u, $d)->id)->toBe($first->id)->and(PhoneUnit::count())->toBe(3);
});

it('C281 rounds fractional global USD costs and configured sale prices to cents once', function () {
    $u = phoneAdmin();
    Http::preventStrayRequests();
    $settings = PricingInput::defaults();
    $settings['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1250];
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    ExchangeQuote::create(['provider' => 'test C281', 'rate_micros' => 12175000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'c281-fractional')]);
    $data = phoneData(['condition' => 'NEW', 'purchase_cost' => '99,99', 'price_mode' => 'CONFIGURED', 'sale_price' => null]);

    $review = app(PhoneInventoryQuery::class)->review($u, $data);
    expect($review['historical_cost_cents'])->toBe(128737);
    expect($review['pricing']['price_cents'])->toBe(144829);
    $action = app(RegisterPhoneUnitAction::class);
    $phone = $action->execute($u, $data, null, $review['fingerprint']);
    expect($phone->purchase_rate_micros)->toBe(12875000);
    expect($phone->historical_cost_cents)->toBe(128737);
    expect($phone->sale_price_cents)->toBe(144829);
    expect($action->execute($u, $data)->id)->toBe($phone->id);
    expect(PhoneUnit::count())->toBe(1);
    Http::assertNothingSent();
});

it('C283 keeps the demo price paused and reprices it after a dollar rise only when enabled without touching manual records', function () {
    $u = phoneAdmin();
    test()->seed(AccessoryPricingPermissionsSeeder::class);
    Http::preventStrayRequests();
    $values = PricingInput::defaults();
    $values['adjustment_micros'] = 500000;
    $values['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1500, 'auto_update' => false];
    $settings = PricingSettings::create(['code' => 'GENERAL'] + $values);
    ExchangeQuote::create(['provider' => 'test C283', 'rate_micros' => 12180000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'c283-original')]);
    $register = app(RegisterPhoneUnitAction::class);
    $data = phoneData(['brand' => 'PRUEBA', 'model' => 'Ejemplo USD 100 — NO VENDER', 'ram_gb' => 8, 'storage_gb' => 128, 'condition' => 'NEW', 'imei1' => '000000000000281', 'purchase_cost' => '100', 'price_mode' => 'CONFIGURED', 'sale_price' => null]);
    $phone = $register->execute($u, $data);
    $manual = $register->execute($u, phoneData(['condition' => 'NEW', 'imei1' => '000000000000282', 'purchase_cost' => '100', 'sale_price' => '1500']));
    $used = $register->execute($u, phoneData(['imei1' => '000000000000283']));
    $manualBefore = $manual->fresh()->getRawOriginal();
    $usedBefore = $used->fresh()->getRawOriginal();
    expect($phone->sale_price_cents)->toBe(145820);
    expect($phone->historical_cost_cents)->toBe(126800);

    test()->travel(1)->minutes();
    Http::fake(['paralelo.bo/*' => Http::response(['buy' => 13.18, 'timestamp' => now()->toIso8601String()])]);
    $quote = app(RefreshPricingReferenceAction::class)->execute($u, true);
    expect($quote->rate_micros)->toBe(13180000);
    expect($phone->fresh()->sale_price_cents)->toBe(145820);
    expect(DB::table('phone_price_changes')->count())->toBe(0);

    $rules = $settings->rules;
    $rules['NEW_PHONE']['auto_update'] = true;
    $settings->update(['rules' => $rules]);
    $refresh = app(RefreshAutomaticPhonePricesAction::class);
    expect($refresh->execute($u))->toBe(1);
    expect($phone->fresh()->sale_price_cents)->toBe(157320);
    expect($phone->fresh()->pricing_snapshot['replacement_cents'])->toBe(136800);
    expect($phone->fresh()->historical_cost_cents)->toBe(126800);
    expect($phone->fresh()->purchase_rate_micros)->toBe(12680000);
    expect($manual->fresh()->getRawOriginal())->toBe($manualBefore);
    expect($used->fresh()->getRawOriginal())->toBe($usedBefore);
    $audit = DB::table('phone_price_changes')->sole();
    expect($audit->phone_unit_id)->toBe($phone->id);
    expect($audit->previous_price_cents)->toBe(145820);
    expect($audit->price_cents)->toBe(157320);
    expect($refresh->execute($u))->toBe(0);
    expect(DB::table('phone_price_changes')->count())->toBe(1);
    expect(PhoneUnit::count())->toBe(3);
    expect(DB::table('accessory_commercial_movements')->count())->toBe(0);
    Http::assertSentCount(1);
});

it('C284 lowers the demo automatic price after a rise but keeps the original cost as its base when the dollar falls further', function () {
    $u = phoneAdmin();
    test()->seed(AccessoryPricingPermissionsSeeder::class);
    Http::preventStrayRequests();
    $settings = PricingInput::defaults();
    $settings['adjustment_micros'] = 500000;
    $settings['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1500, 'auto_update' => true];
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    ExchangeQuote::create(['provider' => 'test C284', 'rate_micros' => 12180000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'c284-original')]);
    $register = app(RegisterPhoneUnitAction::class);
    $phone = $register->execute($u, phoneData(['brand' => 'PRUEBA', 'model' => 'Ejemplo USD 100 — NO VENDER', 'ram_gb' => 8, 'storage_gb' => 128, 'condition' => 'NEW', 'imei1' => '000000000000281', 'purchase_cost' => '100', 'price_mode' => 'CONFIGURED', 'sale_price' => null]));
    $manual = $register->execute($u, phoneData(['condition' => 'NEW', 'imei1' => '000000000000282', 'purchase_cost' => '100', 'sale_price' => '1500']));
    $manualBefore = $manual->fresh()->getRawOriginal();
    $refresh = app(RefreshPricingReferenceAction::class);

    Http::fake(['paralelo.bo/*' => Http::sequence()
        ->push(['buy' => 13.18, 'timestamp' => now()->addMinutes(1)->toIso8601String()])
        ->push(['buy' => 12.50, 'timestamp' => now()->addMinutes(2)->toIso8601String()])
        ->push(['buy' => 11.50, 'timestamp' => now()->addMinutes(3)->toIso8601String()])
        ->push(['buy' => 1.00, 'timestamp' => now()->addMinutes(4)->toIso8601String()])]);

    foreach ([
        [13.18, 157320, 136800, 1],
        [12.50, 149500, 130000, 2],
        [11.50, 145820, 120000, 3],
        [1.00, 145820, 15000, 3],
    ] as [$rate, $expectedSale, $replacement, $auditCount]) {
        test()->travel(1)->minutes();
        $quote = $refresh->execute($u, true);
        expect($quote)->not->toBeNull();
        expect($phone->fresh()->sale_price_cents)->toBe($expectedSale)->toBeGreaterThanOrEqual(126800);
        expect($phone->fresh()->historical_cost_cents)->toBe(126800);
        expect($phone->fresh()->purchase_rate_micros)->toBe(12680000);
        expect($manual->fresh()->getRawOriginal())->toBe($manualBefore);
        expect(DB::table('phone_price_changes')->count())->toBe($auditCount);
        if ($rate !== 1.00) {
            $audit = DB::table('phone_price_changes')->latest('id')->first();
            expect($audit->phone_unit_id)->toBe($phone->id);
            expect($audit->price_cents)->toBe($expectedSale);
            expect(json_decode($audit->pricing_snapshot, true)['replacement_cents'])->toBe($replacement);
        }
    }
    expect(app(RefreshAutomaticPhonePricesAction::class)->execute($u))->toBe(0);
    expect(DB::table('phone_price_changes')->count())->toBe(3);
    expect(PhoneUnit::count())->toBe(2);
    expect(DB::table('accessory_commercial_movements')->count())->toBe(0);
    Http::assertSentCount(4);
});

it('C285 registers five identical units without IMEI atomically and retries without increasing stock', function () {
    $u = phoneAdmin();
    $data = phoneData(['quantity' => 5, 'imei1' => null, 'imei2' => null]);
    $action = app(RegisterPhoneUnitAction::class);
    $review = app(PhoneInventoryQuery::class)->review($u, $data);
    $first = $action->execute($u, $data, null, $review['fingerprint']);
    expect(PhoneUnit::count())->toBe(5);
    expect(PhoneImei::count())->toBe(0);
    expect(PhoneVariant::count())->toBe(1);
    expect($action->execute($u, $data)->id)->toBe($first->id);
    expect(PhoneUnit::count())->toBe(5);
    expect(fn () => $action->execute($u, array_replace($data, ['quantity' => 3])))->toThrow(ValidationException::class);
    expect(DB::table('accessory_commercial_movements')->count())->toBe(0);
});

it('C285 rolls back every unit when a multiple phone registration is interrupted', function () {
    $u = phoneAdmin();
    $data = phoneData(['quantity' => 3, 'imei1' => null]);
    DB::unprepared("CREATE TRIGGER c285_batch_failure BEFORE INSERT ON phone_units WHEN (SELECT count(*) FROM phone_units) = 1 BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    expect(fn () => app(RegisterPhoneUnitAction::class)->execute($u, $data))->toThrow(QueryException::class);
    expect(PhoneUnit::count())->toBe(0);
    expect(PhoneVariant::count())->toBe(0);
    DB::unprepared('DROP TRIGGER c285_batch_failure');
    app(RegisterPhoneUnitAction::class)->execute($u, $data);
    expect(PhoneUnit::count())->toBe(3);
});

it('C285 rejects invalid stock and prevents repeating a single IMEI across a batch', function () {
    $u = phoneAdmin();
    $action = app(RegisterPhoneUnitAction::class);
    foreach ([['quantity' => 0], ['quantity' => -1], ['quantity' => '1.5'], ['quantity' => 1001], ['quantity' => 3], ['imei1' => null, 'imei2' => '212345678901234']] as $invalid) {
        expect(fn () => $action->execute($u, phoneData($invalid)))->toThrow(ValidationException::class);
    }
    expect(PhoneUnit::count())->toBe(0);
    expect(PhoneImei::count())->toBe(0);
});
