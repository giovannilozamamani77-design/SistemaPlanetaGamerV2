<?php

use App\Domain\Accesorios\Actions\CreateAccessoryCategoryAction;
use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\RegisterAccessoryProductAction;
use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Actions\ResolveAccessoryCostAction;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryCategory;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessoryProductRegistration;
use App\Domain\Accesorios\Models\AccessoryStockEntry;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\AccessoryRegistrationQuery;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Reportes\Queries\ModuleReportQuery;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function registrationAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class]);
    Storage::fake('local');
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
function registrationCategory(User $u, array $changes = []): AccessoryCategory
{
    return app(CreateAccessoryCategoryAction::class)->execute($u, array_replace(['name' => 'Cargadores', 'tracking' => 'QUANTITY', 'request_key' => (string) Str::uuid()], $changes));
}
function registrationData(AccessoryCategory $category, array $changes = []): array
{
    return array_replace(['code' => '0000123456789', 'name' => 'Cargador USB C', 'category_id' => $category->id, 'description' => null, 'unit_cost' => '10', 'sale_price' => '15', 'quantity' => 5, 'minimum_stock' => 2, 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()], $changes);
}
it('C254 registers product initial stock original cost and audit in one transaction without requiring image or details', function () {
    $u = registrationAdmin();
    $cat = registrationCategory($u);
    $p = app(RegisterAccessoryProductAction::class)->execute($u, registrationData($cat));
    expect($p->quantity)->toBe(5)->and($p->stock_value_cents)->toBe(5000)->and($p->reference_cost_cents)->toBe(1000)->and($p->sale_price_cents)->toBe(1500)->and($p->category_id)->toBe($cat->id)->and($p->code)->toBe('0000123456789')->and($p->image_path)->toBeNull();
    expect(AccessoryStockEntry::count())->toBe(1)->and(AccessoryProductRegistration::count())->toBe(1);
});
it('C254 returns the same complete registration on retry and rejects changed stock or code duplicates', function () {
    $u = registrationAdmin();
    $cat = registrationCategory($u);
    $data = registrationData($cat);
    $a = app(RegisterAccessoryProductAction::class);
    $p = $a->execute($u, $data);
    expect($a->execute($u, $data)->id)->toBe($p->id);
    expect(fn () => $a->execute($u, array_replace($data, ['quantity' => 6])))->toThrow(ValidationException::class);
    expect(fn () => $a->execute($u, array_replace($data, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    expect(AccessoryProduct::count())->toBe(1)->and(AccessoryStockEntry::count())->toBe(1);
});
it('C254 normalizes category names and does not duplicate accented or uppercase variants', function () {
    $u = registrationAdmin();
    $a = app(CreateAccessoryCategoryAction::class);
    $one = registrationCategory($u, ['name' => ' Audífonos  inalámbricos ']);
    $two = registrationCategory($u, ['name' => 'AUDIFONOS INALAMBRICOS']);
    expect($two->id)->toBe($one->id)->and(AccessoryCategory::count())->toBe(1);
    $phone = registrationCategory($u, ['name' => 'Teléfonos', 'tracking' => 'IMEI']);
    expect(fn () => app(RegisterAccessoryProductAction::class)->execute($u, registrationData($phone)))->toThrow(ValidationException::class);
    expect(AccessoryProduct::count())->toBe(0);
});
it('C254 keeps zero stock as a reference cost without inventing an acquisition movement', function () {
    $u = registrationAdmin();
    $p = app(RegisterAccessoryProductAction::class)->execute($u, registrationData(registrationCategory($u), ['quantity' => 0]));
    expect($p->quantity)->toBe(0)->and($p->stock_value_cents)->toBe(0)->and($p->reference_cost_cents)->toBe(1000)->and(AccessoryStockEntry::count())->toBe(0);
});
it('C254 stores a validated private image and keeps retries from creating duplicate files', function () {
    $u = registrationAdmin();
    $data = registrationData(registrationCategory($u));
    $image = UploadedFile::fake()->image('foto.png', 200, 200);
    $a = app(RegisterAccessoryProductAction::class);
    $p = $a->execute($u, $data, $image);
    $again = $a->execute($u, $data, $image);
    expect($again->id)->toBe($p->id)->and($p->image_path)->toStartWith('accessories/products/');
    Storage::disk('local')->assertExists($p->image_path);
    expect(count(Storage::disk('local')->allFiles('accessories/products')))->toBe(1);
    $bytes = Storage::disk('local')->get($p->image_path);
    expect(getimagesizefromstring($bytes)['mime'])->toBe('image/jpeg');
});
it('C254 rolls back product stock and newly stored image if the final registration event fails', function () {
    $u = registrationAdmin();
    $data = registrationData(registrationCategory($u));
    $image = UploadedFile::fake()->image('foto.jpg');
    DB::statement("CREATE TRIGGER fail_registration BEFORE INSERT ON accessory_product_registrations BEGIN SELECT RAISE(ABORT, 'registration interrupted'); END");
    expect(fn () => app(RegisterAccessoryProductAction::class)->execute($u, $data, $image))->toThrow(QueryException::class);
    expect(AccessoryProduct::count())->toBe(0)->and(AccessoryStockEntry::count())->toBe(0)->and(Storage::disk('local')->allFiles('accessories/products'))->toBe([]);
    DB::statement('DROP TRIGGER fail_registration');
    app(RegisterAccessoryProductAction::class)->execute($u, $data, $image);
    expect(AccessoryProduct::count())->toBe(1)->and(AccessoryStockEntry::count())->toBe(1);
});
it('C254 rejects unsupported or forged image paths without creating any product', function () {
    $u = registrationAdmin();
    $data = registrationData(registrationCategory($u));
    $a = app(RegisterAccessoryProductAction::class);
    expect(fn () => $a->execute($u, $data, '../../.env'))->toThrow(ValidationException::class);
    expect(fn () => $a->execute($u, $data, UploadedFile::fake()->create('foto.svg', 10, 'image/svg+xml')))->toThrow(ValidationException::class);
    expect(AccessoryProduct::count())->toBe(0);
});
it('C254 preserves configured markup and allows an administrative manual price above cost', function () {
    $u = registrationAdmin();
    $s = PricingInput::defaults();
    $s['rules']['ACCESSORY'] = ['mode' => 'PERCENT', 'markup_bps' => 5000];
    $s['revision'] = 2;
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    $cat = registrationCategory($u);
    $q = app(AccessoryRegistrationQuery::class);
    $quote = $q->quote($u, '10', '18');
    expect($quote['suggested_price_cents'])->toBe(1500)->and($quote['profit_cents'])->toBe(800);
    $p = app(RegisterAccessoryProductAction::class)->execute($u, registrationData($cat, ['sale_price' => '18', 'expected_pricing_revision' => 2]));
    expect($p->sale_price_cents)->toBe(1800)->and(PricingSettings::first()->revision)->toBe(2);
});
it('C254 validates required money stock category and permissions before persisting', function () {
    $u = registrationAdmin();
    $cat = registrationCategory($u);
    $data = registrationData($cat);
    $a = app(RegisterAccessoryProductAction::class);
    foreach ([['unit_cost' => ''], ['sale_price' => '9'], ['quantity' => -1], ['category_id' => null], ['name' => '']] as $change) {
        expect(fn () => $a->execute($u, array_replace($data, $change)))->toThrow(ValidationException::class);
    }
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => $a->execute($cashier, $data))->toThrow(AuthorizationException::class);
    expect(AccessoryProduct::count())->toBe(0);
});

it('C254 rolls back all database rows and reports image error when storage refuses the write', function (bool $throws) {
    $u = registrationAdmin();
    $data = registrationData(registrationCategory($u));
    $image = UploadedFile::fake()->image('foto.jpg');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturn(false);
    $disk->shouldReceive('delete')->andReturn(true);
    if ($throws) {
        $disk->shouldReceive('put')->andThrow(new RuntimeException('Disk full'));
    } else {
        $disk->shouldReceive('put')->andReturn(false);
    }
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    expect(fn () => app(RegisterAccessoryProductAction::class)->execute($u, $data, $image))->toThrow(ValidationException::class);
    expect(AccessoryProduct::count())->toBe(0)->and(AccessoryStockEntry::count())->toBe(0)->and(AccessoryProductRegistration::count())->toBe(0);
})->with([false, true]);
it('C254 keeps a sold image snapshot accessible even if the current product later has no image', function () {
    $u = registrationAdmin();
    test()->seed(AccessorySalesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->actingAs($u);
    $p = app(RegisterAccessoryProductAction::class)->execute($u, registrationData(registrationCategory($u)), UploadedFile::fake()->image('foto.jpg'));
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, ['lines' => [['product_id' => $p->id, 'quantity' => 1, 'expected_revision' => $p->revision, 'expected_price_cents' => $p->sale_price_cents]], 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => (string) Str::uuid()]);
    $line = $sale->lines()->first();
    expect($line->product_image_path)->toBe($p->image_path);
    $p->update(['image_path' => null]);
    test()->get(route('accesorios.venta-imagen', ['line' => $line->id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    test()->actingAs(User::factory()->create())->get(route('accesorios.venta-imagen', ['line' => $line->id]))->assertForbidden();
});
it('C254 rejects oversized photos and stale price rules without partial registration', function () {
    $u = registrationAdmin();
    $data = registrationData(registrationCategory($u));
    $a = app(RegisterAccessoryProductAction::class);
    expect(fn () => $a->execute($u, $data, UploadedFile::fake()->image('foto.jpg')->size(6000)))->toThrow(ValidationException::class);
    $s = PricingInput::defaults();
    $s['revision'] = 1;
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    expect(fn () => $a->execute($u, $data))->toThrow(ValidationException::class);
    expect(AccessoryProduct::count())->toBe(0);
});

it('C254 retains initial barcode and costing data after another actor tries to replay the registration', function () {
    $u = registrationAdmin();
    $data = registrationData(registrationCategory($u));
    $a = app(RegisterAccessoryProductAction::class);
    $a->execute($u, $data);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $a->execute($other, $data))->toThrow(AuthorizationException::class);
    expect(AccessoryProductRegistration::count())->toBe(1)->and(AccessoryStockEntry::count())->toBe(1);
});
it('C254 applies phone camera orientation after resizing and strips original metadata', function () {
    $u = registrationAdmin();
    $image = UploadedFile::fake()->image('camera.jpg', 40, 20);
    $bytes = file_get_contents($image->getRealPath());
    $exif = "Exif\0\0"."II\x2a\x00".pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', 6)."\x00\x00".pack('V', 0);
    file_put_contents($image->getRealPath(), substr($bytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2));
    $p = app(RegisterAccessoryProductAction::class)->execute($u, registrationData(registrationCategory($u)), $image);
    $result = Storage::disk('local')->get($p->image_path);
    $size = getimagesizefromstring($result);
    if (function_exists('exif_read_data')) {
        expect([$size[0], $size[1]])->toBe([20, 40]);
    }expect(str_contains($result, 'Exif'))->toBeFalse();
});

it('C265 rejects numeric accessory names in both registration paths while preserving barcode zeroes', function () {
    $u = registrationAdmin();
    $cat = registrationCategory($u);
    foreach (['7908984452071', '---'] as $name) {
        expect(fn () => app(RegisterAccessoryProductAction::class)->execute($u, registrationData($cat, ['name' => $name])))->toThrow(ValidationException::class);
        expect(fn () => app(CreateAccessoryProductAction::class)->execute($u, ['code' => '000123', 'name' => $name, 'sale_price' => '20', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    }
    expect(AccessoryProduct::count())->toBe(0);
    $p = app(RegisterAccessoryProductAction::class)->execute($u, registrationData($cat, ['name' => 'Audífonos inalámbricos', 'description' => 'Modelo veinte, potencia 20 W', 'code' => '000123']));
    expect($p->name)->toBe('Audífonos inalámbricos')->and($p->code)->toBe('000123')->and($p->description)->toContain('20 W');
});

it('C388 stores alphanumeric names in both accessory registration paths', function (string $name) {
    $u = registrationAdmin();
    $cat = registrationCategory($u);
    $registered = app(RegisterAccessoryProductAction::class)->execute($u, registrationData($cat, ['name' => $name]));
    $created = app(CreateAccessoryProductAction::class)->execute($u, ['code' => '000123', 'name' => $name, 'sale_price' => '20', 'request_key' => (string) Str::uuid()]);
    expect($registered->fresh()->name)->toBe($name)->and($created->fresh()->name)->toBe($name);
    expect($created->code)->toBe('000123');
})->with(['Air 2', 'Cargador 20W', 'Audífono ２０']);

it('sells opening stock with unknown cost and resolves past margin once the actual cost is recorded', function () {
    $admin = registrationAdmin();
    test()->seed(AccessorySalesPermissionsSeeder::class);
    $product = app(RegisterAccessoryProductAction::class)->execute($admin, registrationData(registrationCategory($admin), ['cost_pending' => true, 'unit_cost' => null]));
    expect($product->cost_pending)->toBeTrue()->and($product->quantity)->toBe(5)
        ->and(AccessoryStockEntry::sole()->unit_cost_cents)->toBeNull();
    $sale = app(RegisterAccessorySaleAction::class)->execute($admin, [
        'lines' => [['product_id' => $product->id, 'quantity' => 2, 'expected_revision' => $product->revision, 'expected_price_cents' => 1500]],
        'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => (string) Str::uuid(),
    ]);
    $query = app(AccessorySaleQuery::class);
    expect($sale->total_cents)->toBe(3000)->and($sale->cost_cents)->toBeNull()
        ->and($query->detail($admin, $sale)['profit_cents'])->toBeNull()
        ->and(CommissionEntry::count())->toBe(0);
    $report = app(ModuleReportQuery::class);
    $month = $sale->created_at->timezone('America/La_Paz')->format('Y-m');
    expect($report->execute($admin, 'ACCESSORIES', $month)['margin']['pending'])->toBe(1);
    $resolve = app(ResolveAccessoryCostAction::class);
    $resolve->execute($admin, $product->id, '10', $product->fresh()->revision);
    expect($product->fresh()->stock_value_cents)->toBe(3000)
        ->and($product->fresh()->quantity)->toBe(3)
        ->and($product->fresh()->cost_pending)->toBeFalse()
        ->and($sale->lines()->sole()->cost_cents)->toBeNull()
        ->and($query->detail($admin, $sale)['profit_cents'])->toBe(1000)
        ->and(CommissionEntry::count())->toBe(1);
    $margin = $report->execute($admin, 'ACCESSORIES', $month)['margin'];
    expect($margin['pending'])->toBe(0)->and($margin['known_cents'])->toBe(1000);
    expect(fn () => $resolve->execute($admin, $product->id, '10', $product->fresh()->revision))->toThrow(ValidationException::class);
});
