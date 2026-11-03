<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Queries\DailyReportQuery;
use App\Domain\Reportes\Queries\DailyReportRecordsQuery;
use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordEntelRechargeAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Filament\Pages\Reportes\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c352Admin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-30 16:00:00', 'UTC'));
    test()->seed(UsuariosPermissionsSeeder::class);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create(['name' => 'Responsable de prueba']);
    $admin->assignRole('administrador');

    return $admin;
}

function c352Payment(User $user, int $amount = 101, string $kind = 'TERM_PAYMENT', string $date = '2026-10-30 16:00:00'): LoanMovement
{
    return LoanMovement::create(['user_id' => $user->id, 'kind' => $kind, 'delta_cents' => $amount, 'before_cents' => 0, 'after_cents' => $amount, 'created_at' => $date]);
}

function c352Mixed(User $user, string $name = 'Cable histórico'): AccessorySale
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => 'Responsable histórico', 'payment_method' => 'CASH', 'quantity' => 4, 'total_cents' => 15000, 'cost_cents' => 10000, 'pricing_revision' => 1, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    foreach ([['ACCESSORY', 2, 200, $name], ['ACCESSORY', 1, 800, 'Cargador histórico'], ['PHONE', 1, 14000, 'Teléfono histórico']] as [$kind, $quantity, $amount, $label]) {
        AccessorySaleLine::create(['sale_id' => $sale->id, 'kind' => $kind, 'product_id' => null, 'product_name' => $label, 'product_code' => 'PRUEBA', 'quantity' => $quantity, 'unit_price_cents' => intdiv($amount, $quantity), 'total_cents' => $amount, 'cost_cents' => 100, 'previous_revision' => 0, 'before_quantity' => $quantity, 'after_quantity' => 0, 'before_value_cents' => 100, 'after_value_cents' => 0]);
    }

    return $sale;
}

function c352Box(User $admin): CashBoxSession
{
    $box = CashBox::factory()->create(['created_by' => $admin->id, 'name' => 'Caja histórica']);
    $config = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $admin->id, 'base_capital_cents' => 10000, 'accounts' => ['cash' => ['name' => 'Efectivo', 'initial_cents' => 10000]], 'shifts' => [], 'reason' => 'Prueba', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);

    return CashBoxSession::create(['cash_box_id' => $box->id, 'user_id' => $admin->id, 'configuration_id' => $config->id, 'status' => 'ABIERTA', 'active_slot' => 1, 'base_capital_cents' => 10000, 'opening_balances' => ['cash' => 10000], 'expected_balances' => ['cash' => 10000], 'comparison' => [], 'opened_at' => now(), 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]);
}

function c352AllSources(User $admin): void
{
    test()->seed([TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    $product = app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.4', 'normal_price' => '10', 'wholesale_price' => '9.5', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '100', 'stocks' => [['product_id' => $product->id, 'quantity' => 10]], 'request_key' => (string) Str::uuid()]);
    app(RecordCardSaleAction::class)->execute($admin, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()]);
    foreach (['CREDIT' => '100', 'PACKAGES' => '200'] as $kind => $amount) {
        EntelDigitalAccount::create(['kind' => $kind, 'balance_cents' => 50000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
        app(RecordEntelRechargeAction::class)->execute($admin, ['kind' => $kind, 'amount' => $amount, 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    }
    c352Mixed($admin);
    foreach (['OBJECT_SALE' => 5000, 'TERM_PAYMENT' => 1500, 'SETTLEMENT' => 21500] as $kind => $amount) {
        c352Payment($admin, $amount, $kind);
    }
    $session = c352Box($admin);
    CashBoxOperation::create(['cash_box_session_id' => $session->id, 'user_id' => $admin->id, 'amount_cents' => 999999, 'fee_type' => 'ACH', 'fee_cents' => 200, 'suggested_fee_cents' => 200, 'fee_component' => 'cash', 'deltas' => ['cash' => 200], 'status' => 'VIGENTE', 'created_at' => now()]);
}

it('C352 reconciles every source and both global totals with the exact detailed contributions', function () {
    $admin = c352Admin();
    c352AllSources($admin);
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    $query = app(DailyReportRecordsQuery::class);
    $collections = $query->records($admin, '2026-10-30', 'collections', 'ALL', 1, 25);
    $sales = $query->records($admin, '2026-10-30', 'sales', 'ALL', 1, 25);
    expect($report['sales_cents'])->toBe(53000);
    expect($report['collections_cents'])->toBe(76200);
    expect($collections['total_cents'])->toBe(76200);
    expect($collections['records']->sum('amount_cents'))->toBe(76200);
    expect($collections['records']->total())->toBe(9);
    expect($sales['total_cents'])->toBe(53000);
    expect($sales['records']->sum('amount_cents'))->toBe(53000);
    expect($sales['records']->total())->toBe(6);
    foreach (['CARDS' => 3000, 'CREDIT' => 10000, 'PACKAGES' => 20000, 'ACCESSORY' => 1000, 'PHONE' => 14000, 'OBJECT_SALE' => 5000, 'TERM_PAYMENT' => 1500, 'SETTLEMENT' => 21500, 'BOX_FEES' => 200] as $source => $amount) {
        $detail = $query->records($admin, '2026-10-30', 'collections', $source);
        expect($detail['total_cents'])->toBe($amount);
        expect($detail['records']->total())->toBe(1);
        expect($detail['records']->first()['source'])->toBe($source);
    }
    Http::assertNothingSent();
});

it('C352 keeps mixed sale lines and stored seller details separate without returning cost snapshots', function () {
    $admin = c352Admin();
    $sale = c352Mixed($admin);
    $admin->update(['name' => 'Nombre cambiado después']);
    $query = app(DailyReportRecordsQuery::class);
    $accessory = $query->records($admin, '2026-10-30', 'collections', 'ACCESSORY')['records']->first();
    $phone = $query->records($admin, '2026-10-30', 'collections', 'PHONE')['records']->first();
    expect($accessory)->toMatchArray(['reference' => 'Venta #'.$sale->id, 'amount_cents' => 1000, 'quantity' => 3, 'actor' => 'Responsable histórico']);
    expect(array_column($accessory['items'], 'label'))->toBe(['Cable histórico', 'Cargador histórico']);
    expect(array_column($phone['items'], 'label'))->toBe(['Teléfono histórico']);
    expect($phone['amount_cents'])->toBe(14000);
    expect($accessory['key'])->not->toBe($phone['key']);
    expect(json_encode([$accessory, $phone]))->not->toContain('cost_cents', 'pricing_snapshot', 'before_value_cents');
});

it('C352 paginates stable ties without losing older records or changing the filtered total', function () {
    $admin = c352Admin();
    $ids = [];
    for ($i = 0; $i < 13; $i++) {
        $ids[] = 'TERM_PAYMENT:'.c352Payment($admin)->id;
    }
    $query = app(DailyReportRecordsQuery::class);
    $keys = [];
    foreach ([1 => 5, 2 => 5, 3 => 3] as $page => $count) {
        $result = $query->records($admin, '2026-10-30', 'collections', 'TERM_PAYMENT', $page);
        expect($result['records']->count())->toBe($count);
        expect($result['records']->total())->toBe(13);
        expect($result['total_cents'])->toBe(1313);
        $keys = [...$keys, ...$result['records']->pluck('key')->all()];
    }
    expect($keys)->toBe(array_reverse($ids));
    expect(array_unique($keys))->toHaveCount(13);
    expect($query->records($admin, '2026-10-30', 'collections', 'TERM_PAYMENT', 1, 10)['records']->count())->toBe(10);
    expect($query->records($admin, '2026-10-30', 'collections', 'TERM_PAYMENT', 1, 25)['records']->count())->toBe(13);
});

it('C352 uses the applied Bolivia day rather than a typed but unapplied date', function () {
    $admin = c352Admin();
    c352Payment($admin, 100, date: '2026-10-30 03:59:59');
    c352Payment($admin, 200, date: '2026-10-30 04:00:00');
    c352Payment($admin, 300, date: '2026-10-31 03:59:59');
    c352Payment($admin, 400, date: '2026-10-31 04:00:00');
    $result = app(DailyReportRecordsQuery::class)->records($admin, '2026-10-30', 'collections', 'TERM_PAYMENT');
    expect($result['total_cents'])->toBe(500);
    expect($result['records']->pluck('recorded_at')->all())->toBe(['30/10/2026 23:59:59', '30/10/2026 00:00:00']);
    test()->actingAs($admin);
    Livewire::test(DailyReport::class)->set('date', '2026-10-29')->call('showDetails', 'collections', 'TERM_PAYMENT')->assertSet('appliedDate', '2026-10-30')->assertSee('Bs 5');
});

it('C352 excludes invalid box fees and noncommercial loan movements while retaining zero-fee records', function () {
    $admin = c352Admin();
    $session = c352Box($admin);
    foreach ([['VIGENTE', 0], ['VIGENTE', 200], ['ANULADA', 999], ['INVALIDADA', 888]] as [$status, $amount]) {
        CashBoxOperation::create(['cash_box_session_id' => $session->id, 'user_id' => $admin->id, 'amount_cents' => 999999, 'fee_type' => 'ACH', 'fee_cents' => $amount, 'suggested_fee_cents' => $amount, 'fee_component' => 'cash', 'deltas' => [], 'status' => $status, 'created_at' => now()]);
    }
    foreach (['INITIAL', 'IN', 'OUT', 'DISBURSEMENT', 'ANNULMENT', 'OBJECT_EXPENSE'] as $kind) {
        c352Payment($admin, 99999, $kind);
    }
    $query = app(DailyReportRecordsQuery::class);
    $collections = $query->records($admin, '2026-10-30');
    expect($collections['total_cents'])->toBe(200);
    expect($collections['records']->total())->toBe(2);
    expect($collections['records']->pluck('amount_cents')->all())->toBe([200, 0]);
    expect($query->records($admin, '2026-10-30', 'sales', 'BOX_FEES')['records']->total())->toBe(0);
});

it('C352 denies every detail endpoint to cashiers managers and inactive administrators', function (string $role, bool $active) {
    c352Admin();
    $actor = User::factory()->create(['is_active' => $active]);
    $actor->assignRole($role);
    $actor->givePermissionTo('usuarios.ver');
    $query = app(DailyReportRecordsQuery::class);
    expect(fn () => $query->records($actor, '2026-10-30'))->toThrow(AuthorizationException::class);
    expect(fn () => $query->summary($actor, '2026-10-30'))->toThrow(AuthorizationException::class);
    test()->actingAs($actor);
    Livewire::test(DailyReport::class)->assertForbidden();
})->with([['cajera', true], ['encargado', true], ['administrador', false]]);

it('C352 reauthorizes pagination after revocation and locks the selected source', function () {
    $admin = c352Admin();
    test()->actingAs($admin);
    $page = Livewire::test(DailyReport::class)->call('showDetails', 'collections', 'TERM_PAYMENT')->assertSet('detailOpen', true);
    expect(fn () => Livewire::test(DailyReport::class)->set('detailSource', 'PHONE'))->toThrow(CannotUpdateLockedPropertyException::class);
    User::findOrFail($admin->id)->removeRole('administrador');
    $page->call('nextPage', 'reportRecordsPage')->assertForbidden();
});

it('C352 resets pagination when changing source size or day and renders escaped historical names', function () {
    $admin = c352Admin();
    c352Mixed($admin, '<script>unsafe()</script>');
    for ($i = 0; $i < 6; $i++) {
        c352Payment($admin);
    }
    test()->actingAs($admin);
    Livewire::test(DailyReport::class)->call('showDetails', 'collections', 'TERM_PAYMENT')->assertDispatched('open-modal', id: 'daily-report-records')
        ->call('nextPage', 'reportRecordsPage')->assertSet('paginators.reportRecordsPage', 2)
        ->set('detailPerPage', 10)->assertSet('paginators.reportRecordsPage', 1)
        ->call('showDetails', 'sales', 'ACCESSORY')->assertSet('paginators.reportRecordsPage', 1)->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)->assertDontSee('<script>unsafe()</script>', false)
        ->set('date', '2026-10-29')->call('applyDate')->assertSet('detailOpen', false)->assertDispatched('close-modal', id: 'daily-report-records')
        ->call('showDetails', 'sales', 'ACCESSORY')->assertSee('Sin registros para este día y origen.');
});

it('C352 validates detail context before accepting arbitrary sources sizes or pages', function (array $overrides) {
    $admin = c352Admin();
    $input = array_replace(['date' => '2026-10-30', 'metric' => 'collections', 'source' => 'ALL', 'page' => 1, 'perPage' => 5], $overrides);
    expect(fn () => app(DailyReportRecordsQuery::class)->records($admin, ...$input))->toThrow(ValidationException::class);
})->with([
    'invalid date' => [['date' => '2026-02-30']],
    'future date' => [['date' => '2026-10-31']],
    'unknown metric' => [['metric' => 'gain']],
    'unknown source' => [['source' => 'loan_funds']],
    'injected source' => [['source' => "CARDS' UNION SELECT * FROM users"]],
    'page zero' => [['page' => 0]],
    'huge page' => [['page' => 1000001]],
    'unbounded size' => [['perPage' => 10000]],
]);

it('C352 restores a safe page size after invalid client input', function () {
    $admin = c352Admin();
    test()->actingAs($admin);
    Livewire::test(DailyReport::class)->call('showDetails')->set('detailPerPage', 10000)->assertSet('detailPerPage', 5)->assertHasErrors(['detailPerPage']);
});

it('C352 reads details without mutations or external sends and without a per-record query increase', function () {
    $admin = c352Admin();
    for ($i = 0; $i < 15; $i++) {
        c352Mixed($admin);
    }
    $query = app(DailyReportRecordsQuery::class);
    $query->records($admin, '2026-10-30', source: 'ACCESSORY');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $query->records($admin, '2026-10-30', 'collections', 'ACCESSORY', 1, 5);
    $first = DB::getQueryLog();
    DB::flushQueryLog();
    $query->records($admin, '2026-10-30', 'collections', 'ACCESSORY', 1, 25);
    $second = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($second))->toBe(count($first));
    expect(count($second))->toBeLessThan(20);
    expect(collect([...$first, ...$second])->contains(fn ($query) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query'])))->toBeFalse();
    expect(AccessorySale::count())->toBe(15);
    expect(AccessorySaleLine::count())->toBe(45);
    Http::assertNothingSent();
});
