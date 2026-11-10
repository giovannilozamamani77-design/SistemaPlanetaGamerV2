<?php

use App\Domain\Accesorios\Actions\CreateAccessoryCategoryAction;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryCategory;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessoryStockEntry;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Filament\Pages\Accesorios\AccessoryCategories;
use App\Filament\Pages\Accesorios\RegisterAccessoryProduct;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function productPageAdmin(): User
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
function productPageData(User $u, array $changes = []): array
{
    $cat = app(CreateAccessoryCategoryAction::class)->execute($u, ['name' => 'Cargadores', 'tracking' => 'QUANTITY', 'request_key' => (string) Str::uuid()]);

    return array_replace(['code' => '0000123456789', 'name' => 'Cargador USB C', 'category_id' => $cat->id, 'unit_cost' => '10', 'sale_price' => '15', 'quantity' => 3], $changes);
}
it('C254 opens a full registration page and confirms a complete initial inventory before clearing the fields', function () {
    $u = productPageAdmin();
    $this->get(RegisterAccessoryProduct::getUrl())->assertOk()->assertSee('Código de barras')->assertSee('Ganancia por unidad')->assertSee('id="registration-name"', false);
    $page = Livewire::test(RegisterAccessoryProduct::class)->fillForm(productPageData($u))->call('reviewProduct')->assertHasNoErrors()->assertMountedActionModalSee('Cargador USB C')->assertMountedActionModalSee('3 unidades');
    expect(AccessoryProduct::count())->toBe(0);
    $page->callMountedAction()->assertHasNoActionErrors()->assertSet('data.code', '')->assertSet('data.name', '')->assertDispatched('accessory-product-ready');
    expect(AccessoryProduct::first()->quantity)->toBe(3)->and(AccessoryStockEntry::count())->toBe(1);
    $page->fillForm(productPageData($u, ['code' => '0000123456790', 'name' => 'Cable USB C']))
        ->call('reviewProduct')->callMountedAction()->assertHasNoActionErrors()
        ->assertSet('data.code', '')->assertDispatched('accessory-product-ready');
    expect(AccessoryProduct::count())->toBe(2)->and(AccessoryStockEntry::count())->toBe(2);
});
it('C254 cancelling the review keeps entered fields and never writes stock or image', function () {
    $u = productPageAdmin();
    $page = Livewire::test(RegisterAccessoryProduct::class)->fillForm(productPageData($u))->call('reviewProduct')->unmountAction()->assertSet('data.code', '0000123456789');
    expect(AccessoryProduct::count())->toBe(0)->and(Storage::disk('local')->allFiles('accessories/products'))->toBe([]);
});
it('C254 computes the saved markup suggestion and permits an administrator to override it', function () {
    $u = productPageAdmin();
    $s = PricingInput::defaults();
    $s['rules']['ACCESSORY'] = ['mode' => 'PERCENT', 'markup_bps' => 5000];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    $page = Livewire::test(RegisterAccessoryProduct::class)->set('data.unit_cost', '10')->call('refreshQuote')->assertSet('data.sale_price', '15')->assertSee('Bs 5');
    $page->set('data.sale_price', '18')->call('refreshQuote')->assertSee('Bs 8');
    $page->call('refreshQuote', true)->assertSet('data.sale_price', '15');
});
it('C254 registers the selected uploaded image and displays it through the authenticated image route', function () {
    $u = productPageAdmin();
    $data = productPageData($u);
    $data['image'] = UploadedFile::fake()->image('producto.jpg', 100, 100);
    Livewire::test(RegisterAccessoryProduct::class)->fillForm($data)->call('reviewProduct')->assertHasNoErrors()->callMountedAction()->assertHasNoActionErrors();
    $p = AccessoryProduct::firstOrFail();
    Storage::disk('local')->assertExists($p->image_path);
    $this->get(route('accesorios.producto-imagen', ['product' => $p->id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
});
it('C254 disallows both image sources and maps validation to the registration fields', function () {
    $u = productPageAdmin();
    $data = productPageData($u, ['image' => UploadedFile::fake()->image('file.jpg'), 'camera_image' => UploadedFile::fake()->image('camera.jpg')]);
    Livewire::test(RegisterAccessoryProduct::class)->fillForm($data)->call('reviewProduct')->assertHasErrors(['data.image']);
    expect(AccessoryProduct::count())->toBe(0);
});
it('C254 categories can be created without duplicate spelling variants and cashier cannot access administration', function () {
    $u = productPageAdmin();
    Livewire::test(AccessoryCategories::class)->callAction('createCategory', data: ['name' => 'Audífonos', 'tracking' => 'QUANTITY'])->assertHasNoActionErrors();
    Livewire::test(AccessoryCategories::class)->callAction('createCategory', data: ['name' => 'AUDIFONOS', 'tracking' => 'QUANTITY'])->assertHasNoActionErrors();
    expect(AccessoryCategory::count())->toBe(1);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier)->get(RegisterAccessoryProduct::getUrl())->assertForbidden();
    $this->get(AccessoryCategories::getUrl())->assertForbidden();
});
it('C254 rejects changed form data or revoked permissions after review without persisting a partial product', function () {
    $u = productPageAdmin();
    $page = Livewire::test(RegisterAccessoryProduct::class)->fillForm(productPageData($u))->call('reviewProduct')->set('data.quantity', 4)->callMountedAction()->assertHasErrors(['data.code'])
        ->assertMountedActionModalSee('Los datos cambiaron. Vuelve a revisar antes de registrar.');
    expect(AccessoryProduct::count())->toBe(0);
    $page = Livewire::test(RegisterAccessoryProduct::class)->fillForm(productPageData($u))->call('reviewProduct');
    $u->removeRole('administrador');
    $page->callMountedAction()->assertForbidden();
    expect(AccessoryProduct::count())->toBe(0);
});

it('C254 shows conflicting category controls as a field error instead of silently failing', function () {
    $u = productPageAdmin();
    Livewire::test(AccessoryCategories::class)->callAction('createCategory', data: ['name' => 'Audio', 'tracking' => 'QUANTITY'])->assertHasNoActionErrors();
    Livewire::test(AccessoryCategories::class)->callAction('createCategory', data: ['name' => 'Audio', 'tracking' => 'IMEI'])->assertHasActionErrors(['name']);
    expect(AccessoryCategory::count())->toBe(1);
});

it('C254 accepts the camera input on its own and rejects a forged stored file path', function () {
    $u = productPageAdmin();
    $data = productPageData($u, ['camera_image' => UploadedFile::fake()->image('camera.jpg')]);
    Livewire::test(RegisterAccessoryProduct::class)->fillForm($data)->call('reviewProduct')->assertHasNoErrors()->callMountedAction()->assertHasNoActionErrors();
    expect(AccessoryProduct::first()->image_path)->not->toBeNull();
    $data = productPageData($u, ['code' => 'SECOND', 'image' => 'private/foreign.jpg']);
    Livewire::test(RegisterAccessoryProduct::class)->fillForm($data)->call('reviewProduct')->assertHasErrors(['data.image']);
    expect(AccessoryProduct::count())->toBe(1);
});

it('C254 preserves form and temporary image when the database rejects the final event', function () {
    $u = productPageAdmin();
    $data = productPageData($u, ['image' => UploadedFile::fake()->image('foto.jpg')]);
    $page = Livewire::test(RegisterAccessoryProduct::class)->fillForm($data)->call('reviewProduct');
    DB::statement("CREATE TRIGGER page_registration_failure BEFORE INSERT ON accessory_product_registrations BEGIN SELECT RAISE(ABORT, 'database interrupted'); END");
    $page->callMountedAction()->assertSet('data.code', '0000123456789')->assertSet('data.name', 'Cargador USB C')->assertSee('No se pudo guardar el accesorio. Tus datos se conservaron.');
    expect(AccessoryProduct::count())->toBe(0)->and(AccessoryStockEntry::count())->toBe(0)->and(Storage::disk('local')->allFiles('accessories/products'))->toBe([]);
    DB::statement('DROP TRIGGER page_registration_failure');
    $page->call('reviewProduct')->callMountedAction()->assertHasNoActionErrors()->assertSet('data.code', '');
    expect(AccessoryProduct::count())->toBe(1);
});

it('C265 shows a name error for barcode text and rejects invalid prices or fractional quantities', function () {
    $u = productPageAdmin();
    Livewire::test(RegisterAccessoryProduct::class)->fillForm(productPageData($u, ['name' => '7908984452071']))->call('reviewProduct')->assertHasErrors(['data.name']);
    Livewire::test(RegisterAccessoryProduct::class)->fillForm(productPageData($u, ['quantity' => '1.5']))->call('reviewProduct')->assertHasErrors(['data.quantity']);
    Livewire::test(RegisterAccessoryProduct::class)->fillForm(productPageData($u, ['unit_cost' => '-1']))->call('reviewProduct')->assertHasErrors(['data.unit_cost']);
    expect(AccessoryProduct::count())->toBe(0);
});

it('C269 keeps field pictures outside the clipped input wrappers', function () {
    productPageAdmin();
    $html = $this->get(RegisterAccessoryProduct::getUrl())->assertOk()->getContent();
    $dom = new DOMDocument;
    $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $images = $xpath->query('//img[contains(@src,"/images/accessory-fields/")]');
    expect($images->length)->toBeGreaterThanOrEqual(7);
    foreach ($images as $image) {
        $wrappers = $xpath->query('ancestor::*[contains(concat(" ",normalize-space(@class)," ")," fi-input-wrp ")]', $image);
        expect($wrappers->length)->toBe(0);
    }
});

it('C388 confirms an accessory name containing letters and numbers', function () {
    $u = productPageAdmin();
    $this->get(RegisterAccessoryProduct::getUrl())->assertOk()->assertDontSee('sin números');
    Livewire::test(RegisterAccessoryProduct::class)->fillForm(productPageData($u, ['name' => 'Air 2']))
        ->call('reviewProduct')->assertHasNoErrors()->callMountedAction()->assertHasNoActionErrors();
    expect(AccessoryProduct::sole()->name)->toBe('Air 2');
});

it('registers existing stock at its set price without asking for a purchase cost', function () {
    $admin = productPageAdmin();
    Livewire::test(RegisterAccessoryProduct::class)
        ->fillForm(productPageData($admin, ['cost_pending' => true, 'unit_cost' => '']))
        ->call('reviewProduct')->assertHasNoErrors()->assertMountedActionModalSee('Pendiente')
        ->callMountedAction()->assertHasNoActionErrors();
    expect(AccessoryProduct::sole()->cost_pending)->toBeTrue()
        ->and(AccessoryProduct::sole()->sale_price_cents)->toBe(1500);
});
