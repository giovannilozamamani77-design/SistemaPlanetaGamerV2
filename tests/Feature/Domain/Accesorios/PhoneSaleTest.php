<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Actions\RegisterPhoneUnitAction;
use App\Domain\Accesorios\Actions\ReleasePhoneReservationAction;
use App\Domain\Accesorios\Actions\ReservePhoneAction;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Models\CommercialRules;
use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PhoneWarranty;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Domain\Accesorios\Queries\PhoneSaleQuery;
use App\Domain\Accesorios\Queries\SaleCustomerQuery;
use App\Domain\Prestamos\Models\Borrower;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function phoneSaleAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
function phoneSaleUnit(User $u, array $changes = []): PhoneUnit
{
    return app(RegisterPhoneUnitAction::class)->execute($u, array_replace(['brand' => 'Samsung', 'model' => 'Galaxy A', 'ram_gb' => 8, 'storage_gb' => 256, 'condition' => 'USED', 'color' => 'Negro', 'imei1' => '012345678901234', 'imei2' => '112345678901234', 'physical_state' => 'Usado sin golpes', 'included_accessories' => 'Cargador', 'purchase_cost' => '800', 'expenses' => '0', 'price_mode' => 'MANUAL', 'sale_price' => '950', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()], $changes));
}
function phoneSaleData(User $u, PhoneUnit $p): array
{
    $key = (string) Str::uuid();
    $r = app(ReservePhoneAction::class)->execute($u, $p->id, $key, 5);

    return ['lines' => [], 'phone_lines' => [['unit_id' => $p->id, 'reservation_id' => $r->id, 'expected_price_cents' => $p->sale_price_cents]], 'customer' => ['ci' => '123456 LP', 'name' => 'Cliente de prueba', 'phone' => '77712345', 'email' => 'cliente@example.test', 'email2' => null], 'warranty_accepted' => true, 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => $key];
}
it('C264 sells a reserved phone and creates one warranty client receipt and cash movement atomically', function () {
    $u = phoneSaleAdmin();
    $p = phoneSaleUnit($u);
    $d = phoneSaleData($u, $p);
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, $d);
    expect($p->fresh()->status)->toBe('SOLD')->and(PhoneReservation::first()->status)->toBe('CONSUMED')->and($sale->total_cents)->toBe(95000)->and($sale->receipt_required)->toBeTrue();
    expect(PhoneWarranty::count())->toBe(1)->and(Borrower::count())->toBe(1)->and(CommercialMovement::count())->toBe(1)->and(CommercialFund::first()->balance_cents)->toBe(95000);
    $r = app(AccessorySaleQuery::class)->receipt($u, $sale->id);
    expect($r['customer']['ci'])->toBe('123456 LP')->and($r['lines'][0]['phone']['imeis'])->toBe(['012345678901234', '112345678901234'])->and($r['lines'][0]['warranty']['months'])->toBe(2);
    expect(DB::table('loan_movements')->count())->toBe(0)->and(DB::table('loans')->count())->toBe(0);
});
it('C264 combines phone and accessories in the same sale and only credits cash once', function () {
    $u = phoneSaleAdmin();
    $p = phoneSaleUnit($u);
    $d = phoneSaleData($u, $p);
    $a = app(CreateAccessoryProductAction::class)->execute($u, ['code' => 'CABLE', 'name' => 'Cable', 'sale_price' => '20', 'request_key' => (string) Str::uuid()]);
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $a->id, 'kind' => 'INITIAL', 'quantity' => 5, 'unit_cost' => '10', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $a->refresh();
    $d['lines'] = [['product_id' => $a->id, 'quantity' => 2, 'expected_revision' => $a->revision, 'expected_price_cents' => $a->sale_price_cents]];
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, $d);
    expect($sale->total_cents)->toBe(99000)->and($sale->quantity)->toBe(3)->and($sale->cost_cents)->toBe(82000)->and($a->fresh()->quantity)->toBe(3)->and(AccessorySaleLine::count())->toBe(2)->and(CommercialMovement::count())->toBe(1);
});
it('C264 reuses the exact CI identity without exposing loans or overwriting the contact profile', function () {
    $u = phoneSaleAdmin();
    $client = Borrower::create(['ci' => '123456 LP', 'name' => 'Nombre registrado', 'phone' => '77712345']);
    $lookup = app(SaleCustomerQuery::class)->find($u, ' 123456 lp ');
    expect(array_keys($lookup))->toBe(['ci', 'name', 'phone'])->and($lookup['name'])->toBe('Nombre registrado');
    $d = phoneSaleData($u, phoneSaleUnit($u));
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, $d);
    expect(Borrower::count())->toBe(1)->and($sale->customer_id)->toBe($client->id)->and($client->fresh()->name)->toBe('Nombre registrado')->and($sale->customer_snapshot['name'])->toBe('Cliente de prueba');
});
it('C264 rejects missing client guarantee acceptance expired reservation and a different owner', function () {
    $u = phoneSaleAdmin();
    $p = phoneSaleUnit($u);
    $d = phoneSaleData($u, $p);
    $a = app(RegisterAccessorySaleAction::class);
    foreach ([['customer' => []], ['warranty_accepted' => false]] as $bad) {
        expect(fn () => $a->execute($u, array_replace($d, $bad)))->toThrow(ValidationException::class);
    }
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(fn () => $a->execute($other, $d))->toThrow(ValidationException::class);
    $this->travel(5)->minutes();
    expect(fn () => $a->execute($u, $d))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(CommercialMovement::count())->toBe(0);
});
it('C264 retries the same committed sale without another warranty or debit and rejects changed content', function () {
    $u = phoneSaleAdmin();
    $d = phoneSaleData($u, phoneSaleUnit($u));
    $a = app(RegisterAccessorySaleAction::class);
    $s = $a->execute($u, $d);
    $this->travel(10)->minutes();
    expect($a->execute($u, $d)->id)->toBe($s->id)->and(AccessorySale::count())->toBe(1)->and(PhoneWarranty::count())->toBe(1)->and(CommercialMovement::count())->toBe(1);
    $changed = $d;
    $changed['customer']['name'] = 'Otra persona';
    expect(fn () => $a->execute($u, $changed))->toThrow(ValidationException::class);
});
it('C264 rolls back customer stock sale warranty and money together on an interrupted warranty write', function () {
    $u = phoneSaleAdmin();
    $p = phoneSaleUnit($u);
    $d = phoneSaleData($u, $p);
    DB::statement("CREATE TRIGGER fail_warranty BEFORE INSERT ON phone_warranties BEGIN SELECT RAISE(ABORT,'interrupted'); END");
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $d))->toThrow(QueryException::class);
    expect(AccessorySale::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(CommercialMovement::count())->toBe(0)->and($p->fresh()->status)->toBe('RESERVED')->and(PhoneReservation::first()->status)->toBe('ACTIVE');
    DB::statement('DROP TRIGGER fail_warranty');
    app(RegisterAccessorySaleAction::class)->execute($u, $d);
    expect(PhoneWarranty::count())->toBe(1);
});
it('C264 freezes buyer IMEIs condition dates and receipt after catalog or contact edits', function () {
    $u = phoneSaleAdmin();
    $p = phoneSaleUnit($u);
    $d = phoneSaleData($u, $p);
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, $d);
    $before = app(AccessorySaleQuery::class)->receipt($u, $sale->id);
    $p->update(['sale_price_cents' => 150000, 'color' => 'Rojo']);
    Borrower::first()->update(['name' => 'Nuevo nombre']);
    $u->update(['name' => 'Otra cajera']);
    expect(app(AccessorySaleQuery::class)->receipt($u, $sale->id))->toBe($before);
});

it('C264 emits three calendar months for new phones and rejects a phone price changed after reservation', function () {
    $u = phoneSaleAdmin();
    $settings = PricingInput::defaults();
    $settings['physical_rate_micros'] = 12000000;
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 11300000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', (string) Str::uuid())]);
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    $p = phoneSaleUnit($u, ['condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11', 'sale_price' => '1400']);
    $d = phoneSaleData($u, $p);
    $p->update(['sale_price_cents' => 150000]);
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $d))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(0);
    $p->update(['sale_price_cents' => 140000]);
    $this->travelTo(CarbonImmutable::parse('2026-01-31 20:00:00', 'America/La_Paz'));
    // A fresh reservation is required at the test date; the original one belongs to a later clock.
    app(ReleasePhoneReservationAction::class)->execute($u, $d['request_key']);
    $d = phoneSaleData($u, $p->fresh());
    $s = app(RegisterAccessorySaleAction::class)->execute($u, $d);
    $w = PhoneWarranty::first();
    expect($w->starts_on->toDateString())->toBe('2026-01-31')->and($w->expires_on->toDateString())->toBe('2026-04-30')->and($w->months)->toBe(3);
});
it('C264 requires receipt permission for phone sales and blocks a second sale of the same unit', function () {
    $u = phoneSaleAdmin();
    $p = phoneSaleUnit($u);
    $d = phoneSaleData($u, $p);
    app(RegisterAccessorySaleAction::class)->execute($u, $d);
    $other = $d;
    $other['request_key'] = (string) Str::uuid();
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $other))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(1)->and(PhoneWarranty::count())->toBe(1)->and(CommercialMovement::count())->toBe(1);
});

it('C264 issues separate new and used warranties for multiple IMEIs under one client and receipt', function () {
    $u = phoneSaleAdmin();
    $settings = PricingInput::defaults();
    $settings['physical_rate_micros'] = 12000000;
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 11300000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', (string) Str::uuid())]);
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    $used = phoneSaleUnit($u);
    $d = phoneSaleData($u, $used);
    $new = phoneSaleUnit($u, ['imei1' => '212345678901234', 'imei2' => null, 'condition' => 'NEW', 'purchase_cost' => '100', 'purchase_rate' => '11', 'sale_price' => '1400']);
    $r = app(ReservePhoneAction::class)->execute($u, $new->id, $d['request_key']);
    $d['phone_lines'][] = ['unit_id' => $new->id, 'reservation_id' => $r->id, 'expected_price_cents' => 140000];
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, $d);
    expect(PhoneWarranty::orderBy('id')->pluck('months')->all())->toBe([2, 3])->and($sale->quantity)->toBe(2)->and($sale->total_cents)->toBe(235000)->and(CommercialMovement::count())->toBe(1)->and(Borrower::count())->toBe(1);
    $receipt = app(AccessorySaleQuery::class)->receipt($u, $sale->id);
    expect($receipt['lines'])->toHaveCount(2);
});

it('C285 rejects missing duplicate or foreign IMEIs at confirmation without any partial sale', function () {
    $u = phoneSaleAdmin();
    $existing = phoneSaleUnit($u);
    $p = phoneSaleUnit($u, ['imei1' => null, 'imei2' => null]);
    $data = phoneSaleData($u, $p);
    $action = app(RegisterAccessorySaleAction::class);
    foreach ([[], ['imei1' => '012345678901234'], ['imei1' => '212345678901234', 'imei2' => '212345678901234']] as $imeis) {
        $invalid = $data;
        $invalid['phone_lines'][0] += $imeis;
        expect(fn () => $action->execute($u, $invalid))->toThrow(ValidationException::class);
        expect(AccessorySale::count())->toBe(0);
        expect($p->fresh()->imeis->count())->toBe(0);
        expect(CommercialMovement::count())->toBe(0);
    }
    $data['phone_lines'][0] += ['imei1' => '212345678901234'];
    $sale = $action->execute($u, $data);
    expect($action->execute($u, $data)->id)->toBe($sale->id);
    expect(AccessorySale::count())->toBe(1);
    expect(PhoneWarranty::count())->toBe(1);
    expect($existing->fresh()->status)->toBe('AVAILABLE');
    expect($p->fresh()->imeis->pluck('imei')->all())->toBe(['212345678901234']);
});

it('C285 rolls back newly assigned IMEIs when the sale is interrupted and permits the same retry', function () {
    $u = phoneSaleAdmin();
    $p = phoneSaleUnit($u, ['imei1' => null, 'imei2' => null]);
    $data = phoneSaleData($u, $p);
    $data['phone_lines'][0] += ['imei1' => '212345678901234'];
    DB::unprepared("CREATE TRIGGER c285_sale_failure BEFORE INSERT ON phone_warranties BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    $action = app(RegisterAccessorySaleAction::class);
    expect(fn () => $action->execute($u, $data))->toThrow(QueryException::class);
    expect($p->fresh()->imeis->count())->toBe(0);
    expect(AccessorySale::count())->toBe(0);
    expect(CommercialMovement::count())->toBe(0);
    expect($p->fresh()->status)->toBe('RESERVED');
    DB::unprepared('DROP TRIGGER c285_sale_failure');
    $action->execute($u, $data);
    expect($p->fresh()->status)->toBe('SOLD');
    expect($p->fresh()->imeis->count())->toBe(1);
});

it('C285 rejects the same new IMEI on two units in one checkout atomically', function () {
    $u = phoneSaleAdmin();
    $p = phoneSaleUnit($u, ['quantity' => 2, 'imei1' => null, 'imei2' => null]);
    $other = PhoneUnit::where('id', '!=', $p->id)->sole();
    $data = phoneSaleData($u, $p);
    $reservation = app(ReservePhoneAction::class)->execute($u, $other->id, $data['request_key'], 5);
    $data['phone_lines'][0]['imei1'] = '212345678901234';
    $data['phone_lines'][] = ['unit_id' => $other->id, 'reservation_id' => $reservation->id, 'expected_price_cents' => $other->sale_price_cents, 'imei1' => '212345678901234'];
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $data))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(0);
    expect(CommercialMovement::count())->toBe(0);
    expect($p->fresh()->imeis->count())->toBe(0);
    expect($other->fresh()->imeis->count())->toBe(0);
    expect(PhoneUnit::where('status', 'RESERVED')->count())->toBe(2);
});

it('C287 groups the available catalogue by stock and excludes reserved units until their reservation expires', function () {
    $u = phoneSaleAdmin();
    $p = phoneSaleUnit($u, ['quantity' => 3, 'imei1' => null, 'imei2' => null]);
    $query = app(PhoneSaleQuery::class);
    $cards = $query->catalogue($u);
    expect($cards->total())->toBe(1);
    expect($cards->items()[0]['stock'])->toBe(3);
    $candidate = $cards->items()[0]['id'];
    app(ReservePhoneAction::class)->execute($u, $candidate, (string) Str::uuid(), 5);
    $cards = $query->catalogue($u);
    expect($cards->total())->toBe(1);
    expect($cards->items()[0]['stock'])->toBe(2);
    expect($cards->items()[0]['id'])->not->toBe($candidate);
    $this->travel(6)->minutes();
    $cards = $query->catalogue($u);
    expect($cards->total())->toBe(1);
    expect($cards->items()[0]['stock'])->toBe(3);
    expect(PhoneUnit::count())->toBe(3);
});

it('C291 discounts a phone down to its original cost without changing its reserved list price or warranty', function () {
    $u = phoneSaleAdmin();
    CommercialRules::create(['scope' => 'DISCOUNTS', 'rules' => ['phone_max_cents' => 20000, 'ranges' => [['from_cents' => 0, 'amount_cents' => 20000]]], 'revision' => 0]);
    $phone = phoneSaleUnit($u);
    $data = phoneSaleData($u, $phone);
    $data['phone_lines'][0]['discount'] = '150.01';
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $data))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(0);
    $data['phone_lines'][0]['discount'] = '150';
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, $data);
    expect($sale->total_cents)->toBe(80000);
    expect($sale->lines->sole()->unit_price_cents)->toBe(95000);
    expect($phone->fresh()->sale_price_cents)->toBe(95000);
    expect($phone->fresh()->historical_cost_cents)->toBe(80000);
    expect(PhoneWarranty::count())->toBe(1);
    expect(app(AccessorySaleQuery::class)->receipt($u, $sale->id)['discount_cents'])->toBe(15000);
});

it('C310 returns readable Spanish validation when a phone or IMEI is unavailable', function () {
    Http::preventStrayRequests();
    $u = phoneSaleAdmin();
    $query = app(PhoneSaleQuery::class);
    expect(fn () => $query->byImei($u, '999999999999999'))
        ->toThrow(ValidationException::class, 'No se encontró ese IMEI.');
    expect(fn () => $query->preview($u, ['request_key' => (string) Str::uuid(), 'phone_lines' => [
        ['unit_id' => 999999, 'reservation_id' => 999999, 'expected_price_cents' => 10000],
    ]]))->toThrow(ValidationException::class, 'No se encontró el equipo reservado.');
    expect(AccessorySale::count())->toBe(0)->and(PhoneReservation::count())->toBe(0);
});
