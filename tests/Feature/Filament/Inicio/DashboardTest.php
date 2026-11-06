<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Inicio\Queries\HomeDashboardQuery;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Prestamos\RegisterLoan;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function homeUser(string $role = 'cajera'): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-11-05 16:00:00', 'UTC'));
    test()->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class, TarjetasPermissionsSeeder::class,
        CardSalesPermissionsSeeder::class, LoanPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function homeCardSale(User $user, string $date = '2026-11-05 16:00:00'): CardSale
{
    $store = CardStore::firstOrCreate(['code' => 'TIENDA']);

    return CardSale::create(['card_store_id' => $store->id, 'user_id' => $user->id, 'request_key' => (string) Str::uuid(),
        'mode' => 'NORMAL', 'payment_method' => 'CASH', 'wholesale_min_quantity' => 10, 'total_quantity' => 1,
        'total_cents' => 1000, 'cash_before_cents' => 0, 'cash_after_cents' => 1000, 'created_at' => $date]);
}

function homeSaleLine(User $user): AccessorySaleLine
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => $user->name, 'payment_method' => 'CASH',
        'quantity' => 1, 'total_cents' => 2000, 'cost_cents' => 1000, 'pricing_revision' => 1,
        'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);

    return AccessorySaleLine::create(['sale_id' => $sale->id, 'kind' => 'ACCESSORY', 'product_id' => null,
        'product_name' => 'Accesorio de prueba', 'product_code' => 'PRUEBA', 'quantity' => 1,
        'unit_price_cents' => 2000, 'total_cents' => 2000, 'cost_cents' => 1000, 'previous_revision' => 0,
        'before_quantity' => 1, 'after_quantity' => 0, 'before_value_cents' => 1000, 'after_value_cents' => 0]);
}

function homeSession(User $user, string $name): CashBoxSession
{
    $box = CashBox::factory()->create(['created_by' => $user->id, 'name' => $name]);
    $config = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $user->id,
        'base_capital_cents' => 10000, 'accounts' => ['cash' => ['name' => 'Efectivo', 'initial_cents' => 10000]],
        'shifts' => [], 'reason' => 'Prueba', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);

    return CashBoxSession::create(['cash_box_id' => $box->id, 'user_id' => $user->id, 'configuration_id' => $config->id,
        'status' => 'ABIERTA', 'active_slot' => 1, 'base_capital_cents' => 10000, 'opening_balances' => ['cash' => 10000],
        'expected_balances' => ['cash' => 10000], 'comparison' => [], 'opened_at' => now(),
        'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]);
}

it('redirects unauthenticated home requests to the existing login', function () {
    $this->get('/inicio')->assertRedirect('/inicio/login');
});

it('renders the administrative dashboard in the existing home route with authorized module shortcuts', function () {
    $this->actingAs(homeUser('administrador'));
    Http::preventStrayRequests();

    $this->get(Dashboard::getUrl())->assertOk()->assertSee('data-home-dashboard="administration"', false)
        ->assertSee('Cobros de hoy')->assertSee('Cobros por módulo')->assertSee('Todos los accesos')
        ->assertSee('data-home-shortcut="Cajas"', false)->assertSee('data-home-shortcut="Tarjetas"', false)
        ->assertSee('data-home-shortcut="Préstamos"', false)->assertSee('data-home-shortcut="Accesorios"', false)
        ->assertSee('Usuarios')->assertDontSee('FilamentInfoWidget');
    Http::assertNothingSent();
});

it('shows a personal dashboard and no global financial totals to operational roles', function (string $role) {
    $this->actingAs(homeUser($role));

    $this->get(Dashboard::getUrl())->assertOk()->assertSee('data-home-dashboard="personal"', false)
        ->assertSee('Tus comisiones pendientes')->assertSee('Tus registros de hoy')
        ->assertDontSee('Cobros de hoy')->assertDontSee('Cobros por módulo')->assertDontSee('Comisiones de cajas')
        ->assertDontSee('data-home-shortcut="Envíos"', false)->assertDontSee('Usuarios')->assertDontSee('Roles')
        ->assertDontSee('Resumen general')->assertDontSee('Tipos y plantillas de cajas');
})->with(['cajera', 'encargado']);

it('hides individually restricted shortcuts and refuses their destination routes', function () {
    $user = homeUser();
    foreach (['cajas.operar', 'tarjetas.ventas.registrar', 'tarjetas.inventario.ver', 'prestamos.ver', 'accesorios.ventas.registrar', 'accesorios.inventario.ver'] as $name) {
        $permission = Permission::findByName($name, 'web');
        $user->restrictedPermissions()->attach($permission);
    }
    $this->actingAs($user->fresh());

    $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('data-home-shortcut=', false);
    $this->get(QuickCardSale::getUrl())->assertForbidden();
    $this->get(RegisterLoan::getUrl())->assertForbidden();
});

it('counts only the current cashiers records within the Bolivian day', function () {
    $user = homeUser();
    $other = User::factory()->create();
    homeCardSale($user, '2026-11-05 03:59:59');
    homeCardSale($user, '2026-11-05 04:00:00');
    homeCardSale($user, '2026-11-06 03:59:59');
    homeCardSale($user, '2026-11-06 04:00:00');
    homeCardSale($other);

    $data = app(HomeDashboardQuery::class)->execute($user);

    expect(collect($data['chart'])->firstWhere('tone', 'cyan')['value'])->toBe(2);
    expect(collect($data['metrics'])->firstWhere('label', 'Tus ventas de hoy')['value'])->toBe('2');
    expect($data['administrative'])->toBeFalse();
});

it('returns only the current users own open boxes for continuation', function () {
    $user = homeUser();
    $other = User::factory()->create();
    $mine = homeSession($user, 'Mi caja de prueba');
    homeSession($other, 'Caja privada ajena');
    $this->actingAs($user);

    $data = app(HomeDashboardQuery::class)->execute($user);

    expect($data['owned_boxes'])->toBe(1);
    expect(array_column($data['sessions'], 'box_id'))->toBe([$mine->cash_box_id]);
    $this->get(Dashboard::getUrl())->assertOk()->assertSee('Mi caja de prueba')->assertDontSee('Caja privada ajena');
});

it('reuses report collections without presenting capital movements as sales or net profit', function () {
    $admin = homeUser('administrador');
    homeSaleLine($admin);
    foreach (['TERM_PAYMENT' => 1500, 'CAPITAL_CONTRIBUTION' => 900000] as $kind => $amount) {
        LoanMovement::create(['user_id' => $admin->id, 'kind' => $kind, 'delta_cents' => $amount,
            'before_cents' => 0, 'after_cents' => $amount, 'created_at' => now()]);
    }

    $data = app(HomeDashboardQuery::class)->execute($admin);

    expect(collect($data['metrics'])->firstWhere('label', 'Cobros de hoy')['value'])->toBe('Bs 35');
    expect(collect($data['metrics'])->firstWhere('label', 'Ventas de hoy')['value'])->toBe('Bs 20');
    expect(collect($data['chart'])->firstWhere('label', 'Préstamos')['value'])->toBe(1500);
});

it('shows personal commission amounts without exposing another beneficiary', function () {
    $user = homeUser();
    $other = User::factory()->create();
    foreach ([[$user, 350], [$other, 95000]] as [$owner, $amount]) {
        $line = homeSaleLine($owner);
        CommissionEntry::create(['sale_line_id' => $line->id, 'user_id' => $owner->id, 'actor_name' => $owner->name,
            'amount_cents' => $amount, 'snapshot' => [], 'created_at' => now()]);
    }

    $data = app(HomeDashboardQuery::class)->execute($user);

    expect(collect($data['metrics'])->firstWhere('label', 'Tus comisiones pendientes')['value'])->toBe('Bs 3.50');
});

it('omits private profile information when the profile permission is restricted', function () {
    $user = homeUser();
    $user->restrictedPermissions()->attach(Permission::findByName('perfil.ver', 'web'));
    $this->actingAs($user->fresh());

    $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('Tus comisiones pendientes')
        ->assertDontSee('Comisiones, asistencia y pendientes personales.');
});

it('denies a stale identity after access is revoked', function (string $change) {
    $user = homeUser();
    if ($change === 'inactive') {
        User::whereKey($user->id)->update(['is_active' => false]);
    } else {
        $user->restrictedPermissions()->attach(Permission::findByName('panel.acceder', 'web'));
    }

    expect(fn () => app(HomeDashboardQuery::class)->execute($user))->toThrow(AuthorizationException::class);
})->with(['inactive', 'restricted']);

it('escapes names and leaves the business unchanged when refreshing the dashboard', function () {
    $user = homeUser();
    $user->update(['name' => '<script>alert(1)</script>']);
    homeSession($user, '<img src=x onerror=alert(1)>');
    $this->actingAs($user);
    Http::preventStrayRequests();

    Livewire::test(Dashboard::class)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->call('$refresh')->assertSuccessful();

    $this->assertDatabaseCount('cash_box_sessions', 1);
    $this->assertDatabaseCount('cash_box_operations', 0);
    $this->assertDatabaseCount('card_sales', 0);
    $this->assertDatabaseCount('telegram_dispatches', 0);
    Http::assertNothingSent();
});

it('renders useful empty states and performs bounded read queries', function () {
    $user = homeUser('administrador');
    DB::enableQueryLog();

    $data = app(HomeDashboardQuery::class)->execute($user);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($data['has_activity'])->toBeFalse();
    expect(count($queries))->toBeLessThan(40);
    expect(collect($queries)->filter(fn (array $query): bool => preg_match('/^\s*(insert|update|delete)/i', $query['query'])))->toBeEmpty();
    $this->actingAs($user)->get(Dashboard::getUrl())->assertOk()->assertSee('El día está por empezar');
});

function homeLoan(User $user, string $due, string $status = 'ACTIVO'): Loan
{
    $borrower = Borrower::create(['ci' => (string) Str::uuid(), 'name' => 'Cliente de prueba']);
    $type = LoanObjectType::firstOrCreate(['normalized_name' => 'prueba'], ['name' => 'Prueba', 'rate_bps' => 1000, 'created_by' => $user->id]);

    return Loan::create(['borrower_id' => $borrower->id, 'object_type_id' => $type->id, 'user_id' => $user->id,
        'request_key' => (string) Str::uuid(), 'borrower_snapshot' => [], 'object_snapshot' => [], 'account_snapshot' => [],
        'cashier_snapshot' => [], 'capital_cents' => 10000, 'original_rate_bps' => 1000, 'original_days' => 15,
        'original_interest_cents' => 500, 'original_total_cents' => 10500, 'start_date' => '2026-10-01',
        'original_due_date' => $due, 'due_date' => $due, 'status' => $status, 'delivery_method' => 'CASH', 'created_at' => now()]);
}

it('separates open pending released and uninitialized boxes in the operating chart', function () {
    $admin = homeUser('administrador');
    homeSession($admin, 'Abierta');
    $pending = homeSession($admin, 'Pendiente');
    DB::table('cash_box_sessions')->where('id', $pending->id)->update(['status' => 'CERRADA', 'active_slot' => null]);
    $closed = homeSession($admin, 'Liberada');
    DB::table('cash_box_sessions')->where('id', $closed->id)->update(['status' => 'CERRADA', 'active_slot' => null, 'released_at' => now()]);
    CashBox::factory()->create(['created_by' => $admin->id, 'name' => 'Sin abrir']);

    $boxes = app(HomeDashboardQuery::class)->execute($admin)['operations']['boxes'];

    expect($boxes['total'])->toBe(4);
    expect(array_column($boxes['rows'], 'value'))->toBe([1, 1, 1, 1]);
    expect(array_column($boxes['items'], 'name'))->toContain('Abierta', 'Pendiente', 'Liberada', 'Sin abrir');
});

it('uses the existing loan expiry rules and limits an operational dashboard to its owner', function () {
    $cashier = homeUser();
    homeLoan($cashier, '2026-11-05');
    homeLoan($cashier, '2026-11-04');
    homeLoan($cashier, '2026-10-28');
    homeLoan($cashier, '2026-11-06', 'RECOGIDO_PAGADO');
    homeLoan(User::factory()->create(), '2026-11-06');

    $loans = app(HomeDashboardQuery::class)->execute($cashier)['operations']['loans'];

    expect(array_column($loans['rows'], 'value'))->toBe([1, 1, 1]);
    expect($loans['total'])->toBe(3);
});

it('summarizes active physical stock by company without counting another store or digital balances', function () {
    $user = homeUser();
    $store = CardStore::create(['code' => 'TIENDA', 'initialized_at' => now()]);
    $otherStore = CardStore::create(['code' => 'OTRA']);
    foreach ([['ENTEL', 1500, 5, true], ['TIGO', 1000, 0, true], ['VIVA', 4000, 3, true], ['ENTEL', 3000, 999, false]] as [$company, $face, $quantity, $active]) {
        $product = CardProduct::create(['company' => $company, 'face_value_cents' => $face, 'purchase_price_cents' => $face,
            'normal_price_cents' => $face, 'wholesale_price_cents' => $face, 'is_active' => $active, 'created_by' => $user->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $product->id, 'quantity' => $quantity]);
        CardStock::create(['card_store_id' => $otherStore->id, 'card_product_id' => $product->id, 'quantity' => 888]);
    }

    $cards = app(HomeDashboardQuery::class)->execute($user)['operations']['cards'];

    expect($cards['initialized'])->toBeTrue();
    expect($cards['total'])->toBe(8);
    expect(array_column($cards['rows'], 'value'))->toBe([5, 0, 3]);
    expect($cards['zero_products'])->toBe(['Tigo · Bs 10']);
});

it('does not present an unconfirmed store count as available stock', function () {
    $user = homeUser();
    CardStore::create(['code' => 'TIENDA']);

    $cards = app(HomeDashboardQuery::class)->execute($user)['operations']['cards'];

    expect($cards['initialized'])->toBeFalse();
    expect($cards['rows'])->toBeEmpty();
    expect($cards['zero_products'])->toBeEmpty();
    $this->actingAs($user)->get(Dashboard::getUrl())->assertOk()->assertSee('Pendiente de conteo');
});
