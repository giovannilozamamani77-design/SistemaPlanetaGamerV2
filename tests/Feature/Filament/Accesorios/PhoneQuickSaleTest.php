<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Actions\RegisterPhoneUnitAction;
use App\Domain\Accesorios\Actions\ReservePhoneAction;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PhoneImei;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PhoneWarranty;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Domain\Accesorios\Queries\PhoneInventoryQuery;
use App\Domain\Accesorios\Queries\PhoneSaleQuery;
use App\Domain\Accesorios\Queries\SaleCustomerQuery;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Prestamos\Models\Borrower;
use App\Filament\Pages\Accesorios\AccessoryQuickSale;
use App\Filament\Pages\Accesorios\PhoneInventory;
use App\Infrastructure\Accesorios\AccessoryReceiptPdf;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function phoneQuickAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);

    return $u;
}
function phoneQuickUnit(User $u): PhoneUnit
{
    return app(RegisterPhoneUnitAction::class)->execute($u, ['brand' => 'Samsung', 'model' => 'Galaxy A', 'ram_gb' => 8, 'storage_gb' => 256, 'condition' => 'USED', 'color' => 'Negro', 'imei1' => '012345678901234', 'imei2' => '112345678901234', 'physical_state' => 'Buen estado', 'included_accessories' => 'Cable y cargador', 'purchase_cost' => '800', 'expenses' => '0', 'price_mode' => 'MANUAL', 'sale_price' => '950', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()]);
}
function phoneQuickCustomer(): array
{
    return ['customer_ci' => '123456', 'customer_name' => 'Cliente de prueba', 'customer_phone' => '77712345', 'customer_email' => 'cliente@example.test', 'warranty_accepted' => true, 'cash_received' => true];
}
it('C264 scans either IMEI once and confirms customer warranty and mandatory receipt in the same cart', function () {
    $u = phoneQuickAdmin();
    $p = phoneQuickUnit($u);
    $a = app(CreateAccessoryProductAction::class)->execute($u, ['code' => 'CABLE', 'name' => 'Cable', 'sale_price' => '20', 'request_key' => (string) Str::uuid()]);
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $a->id, 'kind' => 'INITIAL', 'quantity' => 3, 'unit_cost' => '10', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addProduct', $a->id)->call('setCatalogue', 'PHONE')->call('scanCode', '012345678901234')->call('scanCode', '112345678901234')->assertHasNoErrors();
    expect($page->get('phoneCart'))->toHaveCount(1)->and($page->get('cart'))->toHaveCount(1)->and(PhoneReservation::count())->toBe(1);
    $page->call('setCatalogue', 'ACCESSORY')->call('reviewSale')->assertMountedActionModalSee('012345678901234')->assertMountedActionModalSee('2 meses');
    $page->setActionData(phoneQuickCustomer())->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt')->assertMountedActionModalSee('Venta registrada correctamente')->assertMountedActionModalSee('Entrega')->assertMountedActionModalSee('Cliente de prueba')->assertMountedActionModalSee('Bs 970');
    expect(AccessorySale::count())->toBe(1)->and(CommercialFund::first()->balance_cents)->toBe(97000)->and(PhoneWarranty::count())->toBe(1)->and($p->fresh()->status)->toBe('SOLD')->and($page->get('phoneCart'))->toBe([]);
});
it('C264 requires customer data while preserving the reservation on back to cart', function () {
    $u = phoneQuickAdmin();
    $p = phoneQuickUnit($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $p->id)->call('reviewSale');
    $page->setActionData(['cash_received' => true])->callMountedAction()->assertHasActionErrors(['customer_ci', 'customer_name', 'customer_phone']);
    $page->unmountAction();
    expect(AccessorySale::count())->toBe(0)->and($p->fresh()->status)->toBe('RESERVED');
    $page->call('clearCart');
    expect($p->fresh()->status)->toBe('AVAILABLE')->and(PhoneReservation::first()->status)->toBe('RELEASED')->and(Borrower::count())->toBe(0);
});
it('C264 finds shared contact by CI in checkout without needing loan permissions', function () {
    $u = phoneQuickAdmin();
    $p = phoneQuickUnit($u);
    Borrower::create(['ci' => '123456', 'name' => 'Cliente registrado', 'phone' => '+59177712345']);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $p->id)->call('reviewSale')->setActionData(['customer_ci' => '123456']);
    $page->assertActionDataSet(['customer_name' => 'Cliente registrado', 'customer_phone' => '77712345']);
    expect(Borrower::count())->toBe(1);
});
it('C264 handles five-minute expiry and permits a fresh selection without false sales', function () {
    $u = phoneQuickAdmin();
    $p = phoneQuickUnit($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $p->id);
    $this->travel(5)->minutes();
    $page->call('reviewSale')->assertHasErrors(['cart']);
    $page->call('removePhone', $p->id)->call('addPhone', $p->id)->assertHasNoErrors()->call('reviewSale');
    expect(PhoneReservation::count())->toBe(2)->and(AccessorySale::count())->toBe(0);
});
it('C264 refuses selecting phones without receipt permission and rechecks a revoked seller', function () {
    $u = phoneQuickAdmin();
    $p = phoneQuickUnit($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $p->id)->call('reviewSale')->setActionData(phoneQuickCustomer());
    $u->removeRole('administrador');
    $page->callMountedAction()->assertForbidden();
    expect(AccessorySale::count())->toBe(0);
});
it('C264 prints a frozen private receipt and PDF without repeating the sale even when PDF rendering fails', function () {
    $u = phoneQuickAdmin();
    $p = phoneQuickUnit($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $p->id)->call('reviewSale')->setActionData(phoneQuickCustomer())->callMountedAction();
    $sale = AccessorySale::first();
    $url = route('accesorios.recibo', ['sale' => $sale->id]);
    $this->get($url)->assertOk()->assertSee('Cliente de prueba')->assertSee('012345678901234')->assertSee('112345678901234')->assertSee('GARANTÍA')->assertSee('Cable y cargador')->assertDontSee('Costo real');
    $pdf = $this->get(route('accesorios.recibo-pdf', ['sale' => $sale->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-');
    $layout = app(AccessoryReceiptPdf::class)->render(app(AccessorySaleQuery::class)->receipt($u, $sale->id));
    $layout->output();
    expect($layout->getDomPDF()->getCanvas()->get_page_count())->toBe(1);
    if ($path = getenv('C264_RECEIPT_QA_PATH')) {
        file_put_contents($path, $pdf->getContent());
    }
    $this->mock(AccessoryReceiptPdf::class)->shouldReceive('render')->once()->andThrow(new RuntimeException('Printer unavailable'));
    $this->get(route('accesorios.recibo-pdf', ['sale' => $sale->id]))->assertStatus(500);
    expect(AccessorySale::count())->toBe(1)->and(PhoneWarranty::count())->toBe(1)->and(CommercialMovement::count())->toBe(1)->and(CommercialFund::first()->balance_cents)->toBe(95000);
});
it('C264 keeps the cart on a failed warranty write and never gives a false success receipt', function () {
    $u = phoneQuickAdmin();
    $p = phoneQuickUnit($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $p->id)->call('reviewSale')->setActionData(phoneQuickCustomer());
    DB::statement("CREATE TRIGGER failed_phone_ui BEFORE INSERT ON phone_warranties BEGIN SELECT RAISE(ABORT,'failed'); END");
    $page->callMountedAction()->assertSet('lastSaleId', null);
    expect($page->get('phoneCart'))->toHaveCount(1)->and(AccessorySale::count())->toBe(0)->and(Borrower::count())->toBe(0);
    DB::statement('DROP TRIGGER failed_phone_ui');
    $page->call('reviewSale')->setActionData(phoneQuickCustomer())->callMountedAction();
    expect(AccessorySale::count())->toBe(1);
});

it('C264 denies phone reservation and contact lookup when receipt access is restricted', function () {
    $u = phoneQuickAdmin();
    $p = phoneQuickUnit($u);
    $seller = User::factory()->create();
    $seller->assignRole('cajera');
    $seller->restrictedPermissions()->attach(Permission::findByName('accesorios.ventas.ver', 'web'));
    $this->actingAs($seller);
    Livewire::test(AccessoryQuickSale::class)->call('addPhone', $p->id)->assertForbidden();
    expect(fn () => app(ReservePhoneAction::class)->execute($seller, $p->id, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaleCustomerQuery::class)->find($seller, '123456'))->toThrow(AuthorizationException::class);
    expect(PhoneReservation::count())->toBe(0)->and($p->fresh()->status)->toBe('AVAILABLE');
});

it('C285 requires an IMEI at checkout for an unidentified phone and freezes it on its receipt', function () {
    $u = phoneQuickAdmin();
    $p = app(RegisterPhoneUnitAction::class)->execute($u, ['brand' => 'Samsung', 'model' => 'Lote', 'ram_gb' => 8, 'storage_gb' => 128, 'condition' => 'USED', 'color' => 'Negro', 'quantity' => 2, 'purchase_cost' => '800', 'price_mode' => 'MANUAL', 'sale_price' => '950', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $p->id)->call('reviewSale');
    $page->setActionData(phoneQuickCustomer())->callMountedAction()->assertHasActionErrors(['phone_imeis.'.$p->id.'.imei1']);
    expect(AccessorySale::count())->toBe(0);
    $page->setActionData(phoneQuickCustomer() + ['phone_imeis' => [$p->id => ['imei1' => '012345678901234', 'imei2' => '112345678901234']]])->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt');
    expect($p->fresh()->imeis->pluck('imei')->all())->toBe(['012345678901234', '112345678901234']);
    expect(PhoneUnit::availableForSale()->count())->toBe(1);
    expect(AccessorySale::sole()->lines->first()->phone_snapshot['imeis'])->toBe(['012345678901234', '112345678901234']);
    expect(PhoneWarranty::count())->toBe(1);
});

it('C289 sells one new phone from a grouped stock of three with checkout IMEI and a correct frozen receipt and warranty', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00', 'America/La_Paz'));
    $u = phoneQuickAdmin();
    Http::preventStrayRequests();
    $settings = PricingInput::defaults();
    $settings['adjustment_micros'] = 500000;
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    ExchangeQuote::create(['provider' => 'test C289', 'rate_micros' => 12180000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'c289')]);
    app(RegisterPhoneUnitAction::class)->execute($u, ['brand' => 'Samsung', 'model' => 'Galaxy 07 PRUEBA', 'ram_gb' => 6, 'storage_gb' => 128, 'condition' => 'NEW', 'color' => 'Negro', 'quantity' => 3, 'purchase_cost' => '100', 'price_mode' => 'MANUAL', 'sale_price' => '1390', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()]);
    expect(PhoneUnit::count())->toBe(3);
    expect(PhoneImei::count())->toBe(0);
    $catalogue = app(PhoneSaleQuery::class)->catalogue($u);
    expect($catalogue->total())->toBe(1);
    expect($catalogue->items()[0]['stock'])->toBe(3);
    $unitId = $catalogue->items()[0]['id'];
    $seller = User::factory()->create(['name' => 'Cajera de prueba C289']);
    $seller->assignRole('cajera');
    $this->actingAs($seller);

    $page = Livewire::test(AccessoryQuickSale::class)->call('setCatalogue', 'PHONE')->call('addPhone', $unitId)->call('reviewSale')->assertHasNoErrors();
    expect(AccessorySale::count())->toBe(0);
    expect(PhoneImei::count())->toBe(0);
    $page->setActionData(phoneQuickCustomer() + ['phone_imeis' => [$unitId => ['imei1' => '000000000000289', 'imei2' => '000000000000290']]])
        ->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt')->assertMountedActionModalSee('Cliente de prueba');
    $sale = AccessorySale::sole();
    expect($sale->total_cents)->toBe(139000);
    expect($sale->receipt_required)->toBeTrue();
    expect(PhoneUnit::findOrFail($unitId)->status)->toBe('SOLD');
    expect(PhoneUnit::availableForSale()->count())->toBe(2);
    expect(PhoneUnit::where('status', 'AVAILABLE')->whereDoesntHave('imeis')->count())->toBe(2);
    $remaining = app(PhoneSaleQuery::class)->catalogue($seller);
    expect($remaining->total())->toBe(1);
    expect($remaining->items()[0]['stock'])->toBe(2);
    $receipt = app(AccessorySaleQuery::class)->receipt($seller, $sale->id);
    expect($receipt['customer']['name'])->toBe('Cliente de prueba');
    expect($receipt['lines'][0]['phone']['imeis'])->toBe(['000000000000289', '000000000000290']);
    expect($receipt['lines'][0]['phone']['ram_gb'])->toBe(6);
    expect($receipt['lines'][0]['phone']['storage_gb'])->toBe(128);
    expect($receipt['lines'][0]['warranty']['months'])->toBe(3);
    expect($receipt['lines'][0]['warranty']['starts_on'])->toBe('2026-10-29');
    expect($receipt['lines'][0]['warranty']['expires_on'])->toBe('2027-01-29');
    $html = $this->get(route('accesorios.recibo', ['sale' => $sale->id]))->assertOk()->assertSee('000000000000289')->assertSee('000000000000290')->assertSee('Cliente de prueba')->assertSee('GARANTÍA')->assertDontSee('Costo real');
    $pdf = $this->get(route('accesorios.recibo-pdf', ['sale' => $sale->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-');
    if ($path = getenv('C289_RECEIPT_QA_PATH')) {
        file_put_contents($path, $pdf->getContent());
    }
    $this->get(route('accesorios.recibo-pdf', ['sale' => $sale->id]))->assertOk();
    expect(AccessorySale::count())->toBe(1);
    expect(PhoneWarranty::count())->toBe(1);
    expect(CommercialMovement::count())->toBe(1);
    expect(CommercialFund::sole()->balance_cents)->toBe(139000);
    expect(PhoneUnit::availableForSale()->count())->toBe(2);
    Http::assertNothingSent();
});

it('C290 sells the remaining two phones with distinct IMEIs and refuses further sales when stock reaches zero', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 11:00:00', 'America/La_Paz'));
    $u = phoneQuickAdmin();
    Http::preventStrayRequests();
    $settings = PricingInput::defaults();
    $settings['adjustment_micros'] = 500000;
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    ExchangeQuote::create(['provider' => 'test C290', 'rate_micros' => 12180000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'c290')]);
    app(RegisterPhoneUnitAction::class)->execute($u, ['brand' => 'Samsung', 'model' => 'Galaxy 07 PRUEBA', 'ram_gb' => 6, 'storage_gb' => 128, 'condition' => 'NEW', 'color' => 'Negro', 'quantity' => 3, 'purchase_cost' => '100', 'price_mode' => 'MANUAL', 'sale_price' => '1390', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $seller = User::factory()->create();
    $seller->assignRole('cajera');
    $this->actingAs($seller);
    $soldIds = [];

    foreach (['000000000000291', '000000000000292', '000000000000293'] as $index => $imei) {
        $catalogue = app(PhoneSaleQuery::class)->catalogue($seller);
        expect($catalogue->total())->toBe(1);
        expect($catalogue->items()[0]['stock'])->toBe(3 - $index);
        $id = $catalogue->items()[0]['id'];
        $soldIds[] = $id;
        Livewire::test(AccessoryQuickSale::class)->call('setCatalogue', 'PHONE')->call('addPhone', $id)->call('reviewSale')
            ->setActionData(phoneQuickCustomer() + ['phone_imeis' => [$id => ['imei1' => $imei]]])->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt');
        expect(PhoneUnit::availableForSale()->count())->toBe(2 - $index);
        expect(PhoneUnit::findOrFail($id)->status)->toBe('SOLD');
        $sale = AccessorySale::latest('id')->first();
        $receipt = app(AccessorySaleQuery::class)->receipt($seller, $sale->id);
        expect($receipt['lines'][0]['phone']['imeis'])->toBe([$imei]);
        expect($receipt['lines'][0]['warranty']['months'])->toBe(3);
    }
    expect(array_unique($soldIds))->toHaveCount(3);
    expect(PhoneImei::distinct()->count('imei'))->toBe(3);
    expect(PhoneUnit::availableForSale()->count())->toBe(0);
    expect(app(PhoneSaleQuery::class)->catalogue($seller)->total())->toBe(0);
    expect(app(PhoneInventoryQuery::class)->summary($seller)['total'])->toBe(0);
    Livewire::test(PhoneInventory::class)->assertSee('Stock: 0')->assertDontSee('Stock: 3');
    Livewire::test(AccessoryQuickSale::class)->call('setCatalogue', 'PHONE')->assertSee('No hay teléfonos disponibles para esta búsqueda.')->call('addPhone', $soldIds[0])->assertHasErrors(['cart']);
    expect(fn () => app(ReservePhoneAction::class)->execute($seller, $soldIds[1], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(3);
    expect(PhoneWarranty::count())->toBe(3);
    expect(CommercialMovement::count())->toBe(3);
    expect(CommercialFund::sole()->balance_cents)->toBe(417000);
    expect(PhoneUnit::count())->toBe(3);
    Http::assertNothingSent();
});

it('C291 rejects a below-cost checkout discount and keeps the corrected phone discount on its receipt and PDF', function () {
    $u = phoneQuickAdmin();
    $phone = phoneQuickUnit($u);
    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $phone->id)->call('reviewSale');
    $page->setActionData(phoneQuickCustomer() + ['final_prices' => ['p_'.$phone->id => '799']])->callMountedAction()->assertHasErrors(['cart']);
    expect(AccessorySale::count())->toBe(0);
    expect($phone->fresh()->status)->toBe('RESERVED');
    $page->setActionData(phoneQuickCustomer() + ['final_prices' => ['p_'.$phone->id => '920']])->assertMountedActionModalSee('Bs 920')->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt');
    $sale = AccessorySale::sole();
    $receipt = app(AccessorySaleQuery::class)->receipt($u, $sale->id);
    expect($receipt['subtotal_cents'])->toBe(95000);
    expect($receipt['discount_cents'])->toBe(3000);
    expect($receipt['total_cents'])->toBe(92000);
    expect(CommissionEntry::sole()->amount_cents)->toBe(2400);
    $this->get(route('accesorios.recibo', ['sale' => $sale->id]))->assertOk()->assertSee('Descuento: Bs 30')->assertSee('Bs 920')->assertDontSee('Costo real');
    $pdf = $this->get(route('accesorios.recibo-pdf', ['sale' => $sale->id]))->assertOk();
    expect($pdf->getContent())->toStartWith('%PDF-');
    if ($path = getenv('C291_RECEIPT_QA_PATH')) {
        file_put_contents($path, $pdf->getContent());
    }
    expect(CommercialFund::sole()->balance_cents)->toBe(92000);
    expect(PhoneWarranty::count())->toBe(1);
});

it('C292 lets a cashier correct a below-cost phone discount and collect the exact discounted amount with a frozen receipt', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00', 'America/La_Paz'));
    $admin = phoneQuickAdmin();
    Http::preventStrayRequests();
    $settings = PricingInput::defaults();
    $settings['adjustment_micros'] = 500000;
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    ExchangeQuote::create(['provider' => 'test C292', 'rate_micros' => 12180000, 'source_at' => now(), 'fetched_at' => now(), 'fingerprint' => hash('sha256', 'c292')]);
    $phone = app(RegisterPhoneUnitAction::class)->execute($admin, ['brand' => 'Samsung', 'model' => 'Galaxy 07 PRUEBA', 'ram_gb' => 6, 'storage_gb' => 128, 'condition' => 'NEW', 'color' => 'Negro', 'purchase_cost' => '100', 'price_mode' => 'MANUAL', 'sale_price' => '1390', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $cashier = User::factory()->create(['name' => 'Cajera de prueba C292']);
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    $form = phoneQuickCustomer() + ['phone_imeis' => [$phone->id => ['imei1' => '000000000000292']]];

    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $phone->id)->call('reviewSale');
    $page->setActionData($form + ['final_prices' => ['p_'.$phone->id => '1267']])->callMountedAction()->assertHasErrors(['cart'])->assertActionMounted('confirmSale');
    expect(AccessorySale::count())->toBe(0);
    expect(CommercialMovement::count())->toBe(0);
    expect(PhoneWarranty::count())->toBe(0);
    expect($phone->fresh()->status)->toBe('RESERVED');
    expect(PhoneImei::count())->toBe(0);
    $page->setActionData($form + ['final_prices' => ['p_'.$phone->id => '1360']])->assertMountedActionModalSee('Bs 1360')->callMountedAction()->assertHasNoErrors()->assertActionMounted('phoneReceipt');
    $sale = AccessorySale::sole();
    expect($sale->user_id)->toBe($cashier->id);
    expect($sale->total_cents)->toBe(136000);
    expect(CommercialFund::sole()->balance_cents)->toBe(136000);
    expect($phone->fresh()->sale_price_cents)->toBe(139000);
    expect($phone->fresh()->historical_cost_cents)->toBe(126800);
    expect($phone->fresh()->status)->toBe('SOLD');
    $receipt = app(AccessorySaleQuery::class)->receipt($cashier, $sale->id);
    expect($receipt['subtotal_cents'])->toBe(139000);
    expect($receipt['discount_cents'])->toBe(3000);
    expect($receipt['total_cents'])->toBe(136000);
    expect(CommissionEntry::sole()->amount_cents)->toBe(1840);
    expect($receipt['actor_name'])->toBe('Cajera de prueba C292');
    expect($receipt['lines'][0]['phone']['imeis'])->toBe(['000000000000292']);
    expect($receipt['lines'][0]['warranty']['months'])->toBe(3);
    $this->get(route('accesorios.recibo', ['sale' => $sale->id]))->assertOk()->assertSee('Descuento: Bs 30')->assertSee('Bs 1360')->assertSee('000000000000292')->assertDontSee('Costo real');
    $pdf = $this->get(route('accesorios.recibo-pdf', ['sale' => $sale->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-');
    if ($path = getenv('C292_RECEIPT_QA_PATH')) {
        file_put_contents($path, $pdf->getContent());
    }
    $this->get(route('accesorios.recibo-pdf', ['sale' => $sale->id]))->assertOk();
    expect(AccessorySale::count())->toBe(1);
    expect(PhoneWarranty::count())->toBe(1);
    expect(CommercialMovement::count())->toBe(1);
    expect(CommercialFund::sole()->balance_cents)->toBe(136000);
    Http::assertNothingSent();
});

it('C303 adds an available matching phone from the cart and releases it on removal', function () {
    Http::preventStrayRequests();
    $u = phoneQuickAdmin();
    $p = phoneQuickUnit($u);
    $second = $p->replicate();
    $second->request_key = (string) Str::uuid();
    $second->save();
    $page = Livewire::test(AccessoryQuickSale::class)->call('addPhone', $p->id)
        ->call('increasePhone', $p->id)->assertHasNoErrors();
    expect($page->get('phoneCart'))->toHaveCount(2);
    $page->call('increasePhone', $p->id)->assertHasErrors('cart');
    expect($page->get('phoneCart'))->toHaveCount(2);
    $page->call('removePhone', $second->id)->assertHasNoErrors();
    expect($second->fresh()->status)->toBe('AVAILABLE')->and($page->get('phoneCart'))->toHaveCount(1);
    $page->call('clearCart');
    expect($p->fresh()->status)->toBe('AVAILABLE')->and(AccessorySale::count())->toBe(0);
    Http::assertNothingSent();
});
