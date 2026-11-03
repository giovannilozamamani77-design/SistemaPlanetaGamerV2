<?php

use App\Domain\Accesorios\Actions\RefreshAutomaticPhonePricesAction;
use App\Domain\Accesorios\Actions\RegisterPhoneUnitAction;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Filament\Pages\Accesorios\PhoneInventory;
use App\Filament\Pages\Accesorios\RegisterPhone;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessoryPricingPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function phonePageAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class]);
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);

    return $u;
}
function phonePageData(array $change = []): array
{
    return array_replace(['brand' => 'Samsung', 'model' => 'Galaxy A', 'ram_gb' => 8, 'storage_gb' => 256, 'condition' => 'USED', 'color' => 'Negro', 'imei1' => '001234567890123', 'imei2' => '101234567890123', 'purchase_cost' => '850', 'expenses' => '0', 'price_mode' => 'MANUAL', 'sale_price' => '1000', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()], $change);
}
it('C263 opens inventory and registers a reviewed phone before clearing the form', function () {
    $u = phonePageAdmin();
    $this->get(PhoneInventory::getUrl())->assertOk();
    $this->get(RegisterPhone::getUrl())->assertOk()->assertSee('Stock a ingresar');
    $page = Livewire::test(RegisterPhone::class)->fillForm(phonePageData())->call('reviewPhone')->assertHasNoErrors()->assertMountedActionModalSee('1 unidades')->assertMountedActionModalSee('1000');
    expect(PhoneUnit::count())->toBe(0);
    $page->callMountedAction()->assertHasNoActionErrors()->assertSet('data.imei1', null)->assertSet('data.model', null);
    expect(PhoneUnit::count())->toBe(1);
});
it('C263 cancels without writing and rejects fields changed after review', function () {
    phonePageAdmin();
    $page = Livewire::test(RegisterPhone::class)->fillForm(phonePageData())->call('reviewPhone')->unmountAction();
    expect(PhoneUnit::count())->toBe(0);
    $page->call('reviewPhone')->set('data.color', 'Azul')->callMountedAction()->assertHasErrors(['data.sale_price']);
    expect(PhoneUnit::count())->toBe(0);
});
it('C263 gives cashier shared inventory and images without costs or registration', function () {
    $u = phonePageAdmin();
    $p = app(RegisterPhoneUnitAction::class)->execute($u, phonePageData(), UploadedFile::fake()->image('p.jpg'));
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    $this->get(RegisterPhone::getUrl())->assertForbidden();
    $page = Livewire::test(PhoneInventory::class)->assertActionHidden('registerPhone')->assertCanSeeTableRecords([$p]);
    $page->mountAction(TestAction::make('detail')->table($p))->assertMountedActionModalSee('Galaxy A')->assertMountedActionModalDontSee('Costo real')->assertMountedActionModalDontSee('850');
    $this->get(route('accesorios.telefono-imagen', ['phone' => $p->id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $cashier->removeRole('cajera');
    $this->get(route('accesorios.telefono-imagen', ['phone' => $p->id]))->assertForbidden();
});
it('C263 paginates all units and searches IMEI brand model memory color and price', function () {
    $u = phonePageAdmin();
    $units = collect();
    for ($i = 1; $i <= 16; $i++) {
        $units->push(app(RegisterPhoneUnitAction::class)->execute($u, phonePageData(['imei1' => str_pad((string) $i, 15, '0', STR_PAD_LEFT), 'imei2' => null])));
    }
    $page = Livewire::test(PhoneInventory::class)->assertCanSeeTableRecords($units->reverse()->take(15))->assertCanNotSeeTableRecords([$units->first()]);
    $page->call('gotoPage', 2)->assertCanSeeTableRecords([$units->first()]);
    $page->searchTable('000000000000016')->assertCanSeeTableRecords([$units->last()])->assertCanNotSeeTableRecords([$units->first()]);
    foreach (['Samsung', 'Galaxy', '256', 'Negro', '1000'] as $search) {
        $page->searchTable($search)->assertCanSeeTableRecords([$units->last()]);
    }
    $page->searchTable('No existe')->assertCanNotSeeTableRecords($units);
});
it('C288 omits an existing IMEI at registration and rejects forged image paths through the form', function () {
    $u = phonePageAdmin();
    app(RegisterPhoneUnitAction::class)->execute($u, phonePageData());
    Livewire::test(RegisterPhone::class)->fillForm(phonePageData())->call('reviewPhone')->assertHasNoErrors()->assertSet('reviewed.values.imei1', null);
    Livewire::test(RegisterPhone::class)->fillForm(phonePageData(['image' => 'private/foreign.jpg']))->call('reviewPhone')->assertHasErrors(['data.image']);
    expect(PhoneUnit::count())->toBe(1);
});
it('C263 preserves input on interruption and rechecks authorization at confirmation', function () {
    $u = phonePageAdmin();
    $page = Livewire::test(RegisterPhone::class)->fillForm(phonePageData())->call('reviewPhone');
    DB::statement("CREATE TRIGGER fail_phone_page BEFORE INSERT ON phone_units BEGIN SELECT RAISE(ABORT,'interruption'); END");
    $page->callMountedAction()->assertSet('data.model', 'Galaxy A');
    expect(PhoneUnit::count())->toBe(0);
    DB::statement('DROP TRIGGER fail_phone_page');
    $page->call('reviewPhone');
    $u->removeRole('administrador');
    $page->callMountedAction()->assertForbidden();
    expect(PhoneUnit::count())->toBe(0);
});

it('C263 escapes free text in phone details and rejects unavailable or foreign image paths', function () {
    $u = phonePageAdmin();
    $p = app(RegisterPhoneUnitAction::class)->execute($u, phonePageData(['physical_state' => '<script>alert(1)</script>']));
    $page = Livewire::test(PhoneInventory::class)->mountAction(TestAction::make('detail')->table($p));
    $page->assertMountedActionModalDontSee('<script>alert(1)</script>', false);
    $this->get(route('accesorios.telefono-imagen', ['phone' => $p->id]))->assertNotFound();
    $p->update(['image_path' => 'accessories/products/foreign.jpg']);
    $this->get(route('accesorios.telefono-imagen', ['phone' => $p->id]))->assertNotFound();
});

it('C263 permits a fresh review after pricing configuration changes without losing input', function () {
    phonePageAdmin();
    $page = Livewire::test(RegisterPhone::class)->fillForm(phonePageData())->call('reviewPhone');
    $settings = PricingInput::defaults();
    $settings['revision'] = 1;
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    $page->callMountedAction()->assertHasErrors(['data.sale_price']);
    expect(PhoneUnit::count())->toBe(0);
    $page->unmountAction()->call('reviewPhone')->assertSet('data.model', 'Galaxy A')->callMountedAction()->assertHasNoActionErrors();
    expect(PhoneUnit::count())->toBe(1);
});

it('C277 shows automatic conversion and registers the new phone with its frozen BOB cost', function () {
    phonePageAdmin();
    $s = PricingInput::defaults();
    $s['physical_rate_micros'] = 11700000;
    ExchangeQuote::create(['provider' => 'test', 'rate_micros' => 11000000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'page-global')]);
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    Livewire::test(RegisterPhone::class)->fillForm(phonePageData(['condition' => 'NEW', 'purchase_cost' => '100', 'use_operating_rate' => true, 'sale_price' => '1400']))
        ->assertSee('11.7')->assertSee('1170')->call('reviewPhone')->assertHasNoErrors()->callMountedAction()->assertHasNoActionErrors();
    expect(PhoneUnit::first()->purchase_rate_micros)->toBe(11700000)->and(PhoneUnit::first()->historical_cost_cents)->toBe(117000);
});

it('C281 converts USD with the global rate through review and confirmation while preserving manual prices on refresh', function (string $mode, ?string $manualPrice, int $expectedPrice, int $updatedPrice, int $changes) {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00', 'America/La_Paz'));
    $u = phonePageAdmin();
    $this->seed(AccessoryPricingPermissionsSeeder::class);
    Http::preventStrayRequests();
    $settings = PricingInput::defaults();
    $settings['adjustment_micros'] = 700000;
    $settings['rules']['NEW_PHONE'] = ['mode' => 'PERCENT', 'markup_bps' => 1500, 'auto_update' => true];
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    ExchangeQuote::create(['provider' => 'test C281', 'rate_micros' => 12000000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'c281-initial')]);
    $page = Livewire::test(RegisterPhone::class)->fillForm(phonePageData([
        'condition' => 'NEW', 'purchase_cost' => '100', 'price_mode' => $mode, 'sale_price' => $manualPrice,
    ]));

    $page->assertSee('12.7')->assertSee('1270')->call('reviewPhone')->assertHasNoErrors()
        ->assertSet('reviewed.historical_cost_cents', 127000)
        ->assertSet('reviewed.pricing.price_cents', $expectedPrice);
    expect(PhoneUnit::count())->toBe(0);
    $page->callMountedAction()->assertHasNoActionErrors()->assertSet('data.imei1', null);
    $phone = PhoneUnit::sole();
    expect($phone->purchase_currency)->toBe('USD');
    expect($phone->purchase_amount_cents)->toBe(10000);
    expect($phone->purchase_rate_micros)->toBe(12700000);
    expect($phone->historical_cost_cents)->toBe(127000);
    expect($phone->sale_price_cents)->toBe($expectedPrice);

    $this->travel(1)->minutes();
    ExchangeQuote::create(['provider' => 'test C281', 'rate_micros' => 13000000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'c281-refresh')]);
    $refresh = app(RefreshAutomaticPhonePricesAction::class);
    expect($refresh->execute($u))->toBe($changes);
    expect($phone->fresh()->sale_price_cents)->toBe($updatedPrice);
    expect($phone->fresh()->historical_cost_cents)->toBe(127000);
    expect($phone->fresh()->purchase_rate_micros)->toBe(12700000);
    expect($refresh->execute($u))->toBe(0);
    expect(DB::table('phone_price_changes')->count())->toBe($changes);
    expect(PhoneUnit::count())->toBe(1);
    expect(DB::table('accessory_commercial_movements')->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'configured 15 percent' => ['CONFIGURED', null, 146050, 157550, 1],
    'manual remains fixed' => ['MANUAL', '1500', 150000, 150000, 0],
]);

it('C285 shows the global rate before entering USD and registers three phones without an IMEI', function () {
    phonePageAdmin();
    ExchangeQuote::create(['provider' => 'test C285', 'rate_micros' => 12000000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'c285-form')]);
    $page = Livewire::test(RegisterPhone::class)->set('data.condition', 'NEW')->assertSee('12.7')->assertSee('Costo por equipo en bolivianos')->assertDontSee('Gastos adicionales');
    $page->fillForm(phonePageData(['condition' => 'NEW', 'purchase_cost' => '100', 'quantity' => 3, 'imei1' => null, 'imei2' => null, 'sale_price' => '1500']))->assertSee('1270')->call('reviewPhone')->assertHasNoErrors()->assertMountedActionModalSee('3 unidades');
    expect(PhoneUnit::count())->toBe(0);
    $page->callMountedAction()->assertHasNoActionErrors()->assertSet('data.quantity', 1);
    expect(PhoneUnit::count())->toBe(3);
    expect(PhoneUnit::where('historical_cost_cents', 127000)->where('sale_price_cents', 150000)->count())->toBe(3);
});

it('C287 shows one inventory card with stock three for identical unidentified phones without changing their records', function () {
    $u = phonePageAdmin();
    app(RegisterPhoneUnitAction::class)->execute($u, phonePageData(['quantity' => 3, 'imei1' => null, 'imei2' => null]));
    $units = PhoneUnit::orderBy('id')->get();
    Livewire::test(PhoneInventory::class)->assertCountTableRecords(1)->assertCanSeeTableRecords([$units->last()])->assertCanNotSeeTableRecords([$units->first()])->assertSee('Stock: 3');
    expect(PhoneUnit::count())->toBe(3);
});

it('C287 keeps different capacities prices and stock entries separate while paginating grouped cards', function () {
    $u = phonePageAdmin();
    $a = app(RegisterPhoneUnitAction::class);
    $first = $a->execute($u, phonePageData(['quantity' => 3, 'imei1' => null, 'imei2' => null]));
    $second = $a->execute($u, phonePageData(['quantity' => 2, 'imei1' => null, 'imei2' => null, 'storage_gb' => 128, 'sale_price' => '1100']));
    Livewire::test(PhoneInventory::class)->assertCountTableRecords(2)->assertSee('Stock: 3')->assertSee('Stock: 2')->searchTable('1100')->assertCountTableRecords(1)->assertSee('Stock: 2');
    expect(PhoneUnit::count())->toBe(5);
    expect(PhoneUnit::where('variant_id', $first->variant_id)->count())->toBe(3);
    expect(PhoneUnit::where('variant_id', $second->variant_id)->count())->toBe(2);
});

it('C288 omits IMEI at registration even for one unit and ignores identifiers injected into form state', function () {
    phonePageAdmin();
    $page = Livewire::test(RegisterPhone::class)->assertDontSee('IMEI 1 (opcional al ingreso)')->assertDontSee('IMEI 2 (opcional)')->fillForm(phonePageData())->call('reviewPhone')->assertHasNoErrors();
    $page->assertSet('reviewed.values.imei1', null)->assertSet('reviewed.values.imei2', null)->callMountedAction()->assertHasNoActionErrors();
    expect(PhoneUnit::count())->toBe(1);
    expect(PhoneUnit::sole()->imeis->count())->toBe(0);
});
