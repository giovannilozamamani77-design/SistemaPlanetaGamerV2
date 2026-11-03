<?php

use App\Domain\Accesorios\Actions\CreateAccessoryCategoryAction;
use App\Domain\Accesorios\Actions\InstallDefaultAccessoryCategoriesAction;
use App\Domain\Accesorios\Models\AccessoryCategory;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
function defaultsCategoryAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
it('C256 installs the nine requested categories once and keeps phones under IMEI control', function () {
    $u = defaultsCategoryAdmin();
    $a = app(InstallDefaultAccessoryCategoriesAction::class);
    expect($a->execute($u)['created'])->toBe(9)->and($a->execute($u)['created'])->toBe(0)->and(AccessoryCategory::count())->toBe(9);
    expect(AccessoryCategory::where('normalized_name', 'telefonos')->first()->tracking)->toBe('IMEI')->and(AccessoryCategory::where('tracking', 'QUANTITY')->count())->toBe(8)->and(AccessoryProduct::count())->toBe(0);
});
it('C256 preserves existing spelling inactive state and controls when installing defaults', function () {
    $u = defaultsCategoryAdmin();
    $cat = app(CreateAccessoryCategoryAction::class)->execute($u, ['name' => 'CARGADORES', 'tracking' => 'QUANTITY', 'request_key' => (string) Str::uuid()]);
    $cat->update(['is_active' => false]);
    $original = $cat->fresh()->toArray();
    $result = app(InstallDefaultAccessoryCategoriesAction::class)->execute($u);
    expect($result['created'])->toBe(8)->and($cat->fresh()->toArray())->toBe($original)->and(AccessoryCategory::count())->toBe(9);
});
it('C256 rolls back a partial installation and permits one complete retry', function () {
    $u = defaultsCategoryAdmin();
    DB::statement("CREATE TRIGGER fail_default_category BEFORE INSERT ON accessory_categories WHEN NEW.normalized_name='mandos' BEGIN SELECT RAISE(ABORT, 'interrupted'); END");
    expect(fn () => app(InstallDefaultAccessoryCategoriesAction::class)->execute($u))->toThrow(QueryException::class);
    expect(AccessoryCategory::count())->toBe(0);
    DB::statement('DROP TRIGGER fail_default_category');
    expect(app(InstallDefaultAccessoryCategoriesAction::class)->execute($u)['created'])->toBe(9);
});
it('C256 restricts installation to inventory managers and requires an explicit authorized CLI actor', function () {
    $u = defaultsCategoryAdmin();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => app(InstallDefaultAccessoryCategoriesAction::class)->execute($cashier))->toThrow(AuthorizationException::class);
    test()->artisan('accesorios:categorias-iniciales')->assertExitCode(1);
    test()->artisan('accesorios:categorias-iniciales', ['--user' => $u->id])->assertExitCode(0);
    test()->artisan('accesorios:categorias-iniciales', ['--user' => $u->id])->assertExitCode(0);
    expect(AccessoryCategory::count())->toBe(9);
});
