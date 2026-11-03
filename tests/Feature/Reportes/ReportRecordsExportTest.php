<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Actions\ExportReportRecordsAction;
use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordEntelRechargeAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Filament\Pages\Reportes\DailyReport;
use App\Infrastructure\Reportes\ReportRecordsFile;
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
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

function c362Admin(): User
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

function c362Payment(User $user, int $amount = 101, string $kind = 'TERM_PAYMENT', string $date = '2026-10-30 16:00:00'): LoanMovement
{
    return LoanMovement::create(['user_id' => $user->id, 'kind' => $kind, 'delta_cents' => $amount, 'before_cents' => 0, 'after_cents' => $amount, 'created_at' => $date]);
}

function c362Mixed(User $user, string $name = 'Cable histórico'): AccessorySale
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => 'Responsable histórico', 'payment_method' => 'CASH', 'quantity' => 4, 'total_cents' => 15000, 'cost_cents' => 10000, 'pricing_revision' => 1, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    foreach ([['ACCESSORY', 2, 200, $name], ['ACCESSORY', 1, 800, 'Cargador histórico'], ['PHONE', 1, 14000, 'Teléfono histórico']] as [$kind, $quantity, $amount, $label]) {
        AccessorySaleLine::create(['sale_id' => $sale->id, 'kind' => $kind, 'product_id' => null, 'product_name' => $label, 'product_code' => 'PRUEBA', 'quantity' => $quantity, 'unit_price_cents' => intdiv($amount, $quantity), 'total_cents' => $amount, 'cost_cents' => 100, 'previous_revision' => 0, 'before_quantity' => $quantity, 'after_quantity' => 0, 'before_value_cents' => 100, 'after_value_cents' => 0]);
    }

    return $sale;
}

function c362Box(User $admin): CashBoxSession
{
    $box = CashBox::factory()->create(['created_by' => $admin->id, 'name' => 'Caja histórica']);
    $config = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $admin->id, 'base_capital_cents' => 10000, 'accounts' => ['cash' => ['name' => 'Efectivo', 'initial_cents' => 10000]], 'shifts' => [], 'reason' => 'Prueba', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);

    return CashBoxSession::create(['cash_box_id' => $box->id, 'user_id' => $admin->id, 'configuration_id' => $config->id, 'status' => 'ABIERTA', 'active_slot' => 1, 'base_capital_cents' => 10000, 'opening_balances' => ['cash' => 10000], 'expected_balances' => ['cash' => 10000], 'comparison' => [], 'opened_at' => now(), 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]);
}

function c362AllSources(User $admin): void
{
    test()->seed([TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class]);
    $product = app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.4', 'normal_price' => '10', 'wholesale_price' => '9.5', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '100', 'stocks' => [['product_id' => $product->id, 'quantity' => 10]], 'request_key' => (string) Str::uuid()]);
    app(RecordCardSaleAction::class)->execute($admin, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()]);
    foreach (['CREDIT' => '100', 'PACKAGES' => '200'] as $kind => $amount) {
        EntelDigitalAccount::create(['kind' => $kind, 'balance_cents' => 50000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
        app(RecordEntelRechargeAction::class)->execute($admin, ['kind' => $kind, 'amount' => $amount, 'external_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    }
    c362Mixed($admin);
    foreach (['OBJECT_SALE' => 5000, 'TERM_PAYMENT' => 1500, 'SETTLEMENT' => 21500] as $kind => $amount) {
        c362Payment($admin, $amount, $kind);
    }
    $session = c362Box($admin);
    CashBoxOperation::create(['cash_box_session_id' => $session->id, 'user_id' => $admin->id, 'amount_cents' => 999999, 'fee_type' => 'ACH', 'fee_cents' => 200, 'suggested_fee_cents' => 200, 'fee_component' => 'cash', 'deltas' => ['cash' => 200], 'status' => 'VIGENTE', 'created_at' => now()]);
}

function c362Input(string $format = 'csv', array $extra = []): array
{
    return array_replace(['format' => $format, 'mode' => 'day', 'date' => '2026-10-30', 'expected_start' => '2026-10-30', 'expected_end' => '2026-10-30', 'metric' => 'collections', 'source' => 'ALL'], $extra);
}

function c362Rows(string $bytes, string $format): array
{
    if ($format === 'csv') {
        expect($bytes)->toStartWith("\xEF\xBB\xBF");
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, substr($bytes, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
    $file = tempnam(sys_get_temp_dir(), 'c362-');
    file_put_contents($file, $bytes);
    $reader = new Reader;
    try {
        $reader->open($file);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }

        return $rows;
    } finally {
        $reader->close();
        unlink($file);
    }
}

it('C362 exports all nine sources with exact totals and separate mixed sale contributions', function (string $format) {
    $actor = c362Admin();
    c362AllSources($actor);
    $this->actingAs($actor);
    $result = $this->get(route('reportes.detalle-export', c362Input($format)))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    $rows = c362Rows($result->getContent(), $format);
    $records = array_slice($rows, 7, -1);
    expect($records)->toHaveCount(9);
    expect(array_unique(array_column($records, 1)))->toHaveCount(9);
    expect((float) array_sum(array_column($records, 9)))->toBe(762.0);
    expect((float) end($rows)[9])->toBe(762.0);
    expect((int) end($rows)[2])->toBe(9);
    expect((float) array_sum(array_column($records, 7)))->toBe(530.0);
    $audit = ReportExport::sole();
    expect($audit)->toMatchArray(['export_scope' => 'DETAIL', 'detail_metric' => 'collections', 'detail_source' => 'ALL', 'period_start' => '2026-10-30', 'period_end' => '2026-10-30', 'format' => strtoupper($format)]);
    expect($audit->document_sha256)->toBe(hash('sha256', $result->getContent()));
    expect($audit->monthly_close_id)->toBeNull();
    Http::assertNothingSent();
    if ($folder = getenv('C362_QA_DIR')) {
        file_put_contents($folder.'/all.'.$format, $result->getContent());
    }
})->with(['csv', 'xlsx']);

it('C362 exports the entire selected period rather than the displayed page', function (string $format) {
    $actor = c362Admin();
    for ($i = 0; $i < 503; $i++) {
        c362Payment($actor, 101);
    }
    c362Payment($actor, 99999, date: '2026-10-31 04:00:00');
    $this->actingAs($actor);
    $rows = c362Rows($this->get(route('reportes.detalle-export', c362Input($format, ['page' => 2, 'per_page' => 5])))->assertOk()->getContent(), $format);
    expect(array_slice($rows, 7, -1))->toHaveCount(503);
    expect(array_unique(array_column(array_slice($rows, 7, -1), 1)))->toHaveCount(503);
    expect((float) end($rows)[9])->toBe(508.03);
    expect(LoanMovement::count())->toBe(504);
})->with(['csv', 'xlsx']);

it('C362 respects day week month and range with Bolivia boundaries', function (array $input, int $expected) {
    $actor = c362Admin();
    foreach (['2026-09-30 16:00:00' => 100, '2026-10-01 04:00:00' => 200, '2026-10-26 04:00:00' => 400, '2026-10-29 04:00:00' => 800, '2026-10-30 03:59:59' => 1600, '2026-10-30 04:00:00' => 3200, '2026-10-31 03:59:59' => 6400, '2026-10-31 04:00:00' => 12800] as $date => $amount) {
        c362Payment($actor, $amount, 'OBJECT_SALE', $date);
    }
    $this->actingAs($actor);
    $rows = c362Rows($this->get(route('reportes.detalle-export', c362Input(extra: $input)))->assertOk()->getContent(), 'csv');
    expect((float) end($rows)[9])->toBe($expected / 100.0);
})->with([
    [['mode' => 'day'], 9600],
    [['mode' => 'week', 'expected_start' => '2026-10-26'], 12400],
    [['mode' => 'month', 'expected_start' => '2026-10-01'], 12600],
    [['mode' => 'range', 'from' => '2026-10-29', 'until' => '2026-10-30', 'expected_start' => '2026-10-29'], 12000],
]);

it('C362 applies metric and source filters including empty sales-only results', function (string $metric, string $source, int $count, int $cents) {
    $actor = c362Admin();
    c362AllSources($actor);
    $this->actingAs($actor);
    $rows = c362Rows($this->get(route('reportes.detalle-export', c362Input(extra: compact('metric', 'source'))))->assertOk()->getContent(), 'csv');
    expect((int) end($rows)[2])->toBe($count);
    expect((float) end($rows)[9])->toBe($cents / 100.0);
})->with([
    ['sales', 'ALL', 6, 53000], ['collections', 'PHONE', 1, 14000], ['sales', 'TERM_PAYMENT', 0, 0], ['collections', 'BOX_FEES', 1, 200],
]);

it('C362 denies guests cashiers managers and inactive administrators', function (string $role, bool $active) {
    $admin = c362Admin();
    $actor = User::factory()->create(['is_active' => $active]);
    $actor->assignRole($role);
    $this->actingAs($actor)->get(route('reportes.detalle-export', c362Input()))->assertForbidden();
    expect(ReportExport::count())->toBe(0);
})->with([['cajera', true], ['encargado', true], ['administrador', false]]);

it('C362 redirects unauthenticated downloads', function () {
    c362Admin();
    auth()->logout();
    $this->get(route('reportes.detalle-export', c362Input()))->assertRedirect();
    expect(ReportExport::count())->toBe(0);
});

it('C362 rejects invalid filters unsupported formats and stale period links', function (array $change, int $status) {
    $this->actingAs(c362Admin());
    $this->getJson(route('reportes.detalle-export', c362Input(extra: $change)))->assertStatus($status);
    expect(ReportExport::count())->toBe(0);
})->with([
    [['source' => 'UNKNOWN'], 422], [['metric' => 'sales;DROP TABLE users'], 422], [['expected_end' => '2026-10-29'], 409], [['date' => '2026-11-30'], 422], [['format' => 'pdf'], 404],
]);

it('C362 treats user-controlled text as text in spreadsheets and CSV', function (string $format) {
    $actor = c362Admin();
    $actor->update(['name' => '=2+2']);
    c362Mixed($actor, "\t=HYPERLINK(\"bad\")");
    $this->actingAs($actor);
    $rows = c362Rows($this->get(route('reportes.detalle-export', c362Input($format)))->assertOk()->getContent(), $format);
    expect($rows[3][1])->toBe($format === 'csv' ? "'=2+2" : '=2+2');
    $dangerous = collect($rows)->first(fn ($row) => str_contains((string) ($row[6] ?? ''), 'HYPERLINK'));
    expect($dangerous)->not->toBeNull();
    expect($dangerous[6])->toStartWith($format === 'csv' ? "'\t=HYPERLINK" : "\t=HYPERLINK");
    if ($format === 'xlsx') {
        expect($rows[3][1])->not->toBe(4);
    }
})->with(['csv', 'xlsx']);

it('C362 builds download links from applied selection ignoring unsaved filters and pagination', function () {
    $actor = c362Admin();
    $page = Livewire::actingAs($actor)->test(DailyReport::class)->call('showDetails', 'sales', 'PHONE')->set('date', '2026-10-01')->set('detailPerPage', 25);
    $url = $page->instance()->detailExportUrl('csv');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['date' => '2026-10-30', 'expected_start' => '2026-10-30', 'expected_end' => '2026-10-30', 'metric' => 'sales', 'source' => 'PHONE']);
    $page->assertSee('Detalle Excel')->assertSee('Detalle CSV');
});

it('C362 never audits failed rendering or revoked access and cleans private temporary files', function (bool $revoke) {
    $actor = c362Admin();
    $this->mock(ReportRecordsFile::class, function ($mock) use ($actor, $revoke): void {
        $mock->shouldReceive('render')->once()->andReturnUsing(function () use ($actor, $revoke): string {
            if ($revoke) {
                $actor->forceFill(['is_active' => false])->save();

                return 'temporary';
            }
            throw new RuntimeException('Simulated renderer failure');
        });
    });
    try {
        app(ExportReportRecordsAction::class)->execute($actor, c362Input(), 'CSV');
        test()->fail('The download should fail.');
    } catch (RuntimeException|AuthorizationException $e) {
        expect(ReportExport::count())->toBe(0);
    }
})->with([false, true]);

it('C362 rejects oversized exports instead of silently truncating or recording a completed download', function () {
    $actor = c362Admin();
    $row = ['user_id' => $actor->id, 'kind' => 'TERM_PAYMENT', 'delta_cents' => 1, 'before_cents' => 0, 'after_cents' => 1, 'created_at' => now()];
    for ($i = 0; $i < 50; $i++) {
        DB::table('loan_movements')->insert(array_fill(0, 1000, $row));
    }
    DB::table('loan_movements')->insert($row);
    $this->actingAs($actor)->getJson(route('reportes.detalle-export', c362Input()))->assertUnprocessable()->assertJsonValidationErrors('period');
    expect(ReportExport::count())->toBe(0);
    expect(LoanMovement::count())->toBe(50001);
});

it('C362 removes temporary files after a failure during batched generation', function (string $format) {
    c362Admin();
    $context = ['period_label' => '30/10/2026', 'metric_label' => 'Cobros', 'label' => 'Todos', 'records_count' => 1, 'total_cents' => 1];
    $document = ['id' => 'simulated', 'actor' => 'Prueba', 'generated_at' => '30/10/2026'];
    $records = function (): Generator {
        yield from [];
        throw new RuntimeException('Interrupted read');
    };
    $before = glob(storage_path('app/private/report-exports/records-*'));
    expect(fn () => app(ReportRecordsFile::class)->render($context, $document, $records(), $format))->toThrow(RuntimeException::class, 'Interrupted read');
    expect(glob(storage_path('app/private/report-exports/records-*')))->toBe($before);
})->with(['CSV', 'XLSX']);

it('C362 repeated downloads create only their audit and no duplicate operational records', function () {
    $actor = c362Admin();
    c362Payment($actor, 123);
    $this->actingAs($actor);
    foreach ([1, 2] as $attempt) {
        $rows = c362Rows($this->get(route('reportes.detalle-export', c362Input()))->assertOk()->getContent(), 'csv');
        expect((float) end($rows)[9])->toBe(1.23);
    }
    expect(ReportExport::count())->toBe(2);
    expect(LoanMovement::count())->toBe(1);
    expect(DB::table('report_monthly_closes')->count())->toBe(0);
    expect(DB::table('report_monthly_adjustments')->count())->toBe(0);
});
