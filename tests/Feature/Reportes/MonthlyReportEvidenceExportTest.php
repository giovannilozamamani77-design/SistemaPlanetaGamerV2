<?php

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Actions\ConfirmMonthlyReportAdjustmentAction;
use App\Domain\Reportes\Actions\ConfirmMonthlyReportCloseAction;
use App\Domain\Reportes\Actions\ExportMonthlyReportEvidenceAction;
use App\Domain\Reportes\Models\MonthlyReportAdjustment;
use App\Domain\Reportes\Models\MonthlyReportClose;
use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Reportes\Queries\MonthlyReportAdjustmentQuery;
use App\Domain\Reportes\Queries\MonthlyReportCloseQuery;
use App\Domain\Reportes\Queries\MonthlyReportEvidenceQuery;
use App\Domain\Reportes\Queries\MonthlyReportSnapshotQuery;
use App\Filament\Pages\Reportes\MonthlyReport;
use App\Infrastructure\Reportes\MonthlyReportEvidenceFile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

function c363Admin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-11-02 16:00:00', 'UTC'));
    test()->seed(UsuariosPermissionsSeeder::class);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $actor = User::factory()->create(['name' => 'Administración ficticia', 'pin_hash' => Hash::make('1234')]);
    $actor->assignRole('administrador');
    RateLimiter::clear('usuarios:pin:user:'.$actor->id);
    test()->actingAs($actor);

    return $actor;
}

function c363Movement(User $actor, int $cents = 12345, string $date = '2026-10-15 16:00:00'): LoanMovement
{
    return LoanMovement::create(['user_id' => $actor->id, 'kind' => 'OBJECT_SALE', 'delta_cents' => $cents, 'before_cents' => 0, 'after_cents' => $cents, 'created_at' => $date]);
}

function c363Close(User $actor): MonthlyReportClose
{
    $capture = app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-10');

    return app(ConfirmMonthlyReportCloseAction::class)->execute($actor, ['month' => '2026-10', 'request_uuid' => (string) Str::uuid(), 'preview_hash' => $capture['hash'], 'accept_pending' => true], '1234');
}

function c363Input(User $actor, int $closeId): array
{
    $quote = app(MonthlyReportAdjustmentQuery::class)->preview($actor, $closeId);

    return ['close_id' => $closeId, 'base_sequence' => $quote['base_sequence'], 'request_uuid' => (string) Str::uuid(), 'preview_hash' => $quote['preview_hash'], 'reason' => 'Cambio de origen verificado', 'accept_pending' => true];
}

function c363Rows(string $bytes, string $format): array
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

function c363Url(int $close, ?int $adjustment, string $format = 'csv', array $extra = []): string
{
    return route($adjustment === null ? 'reportes.cierre-detalle-export' : 'reportes.cierre-ajuste-detalle-export', [...compact('close', 'format'), ...($adjustment === null ? [] : ['adjustment' => $adjustment]), ...$extra]);
}

function c363Incident(User $actor): EconomicIncident
{
    return EconomicIncident::create(['user_id' => $actor->id, 'reported_by' => $actor->id, 'source_module' => 'PRUEBA', 'source_reference' => 'C363-'.Str::uuid(), 'component' => 'cash', 'status' => 'CONFIRMADA', 'amount_cents' => 500, 'pending_cents' => 500, 'reason' => 'Solo prueba', 'occurred_at' => '2026-10-15 16:00:00']);
}

it('C363 exports the selected frozen original or adjustment after later sources and versions change', function (string $format, bool $adjusted) {
    $actor = c363Admin();
    $movement = c363Movement($actor);
    $incident = c363Incident($actor);
    $close = c363Close($actor);
    $originalHash = $close->snapshot_hash;
    c363Movement($actor, 500);
    $incident->update(['pending_cents' => 200]);
    $first = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c363Input($actor, $close->id), '1234');
    c363Movement($actor, 100);
    $incident->update(['pending_cents' => 0]);
    app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c363Input($actor, $close->id), '1234');
    c363Movement($actor, 999999);
    $actor->update(['name' => 'Nombre actual cambiado']);
    $queries = [];
    DB::listen(function ($event) use (&$queries): void {
        $queries[] = strtolower($event->sql);
    });
    $response = $this->get(c363Url($close->id, $adjusted ? $first->id : null, $format, ['date' => '2000-01-01', 'source' => 'PHONE', 'page' => 99]))->assertOk()->assertHeader('Cache-Control')->assertHeader('X-Content-Type-Options', 'nosniff');
    foreach (['loan_movements', 'economic_incidents', 'card_sales', 'cash_box_operations', 'accessory_sales', 'entel_recharges'] as $sourceTable) {
        expect(collect($queries)->filter(fn ($sql) => str_contains($sql, $sourceTable)))->toBeEmpty();
    }
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $rows = c363Rows($response->getContent(), $format);
    $total = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'TOTAL CONSERVADO');
    expect((float) $total[7])->toBe($adjusted ? 128.45 : 123.45);
    expect((float) $total[8])->toBe($adjusted ? 128.45 : 123.45);
    expect((int) $total[2])->toBe($adjusted ? 2 : 1);
    $records = array_slice($rows, 7, $adjusted ? 2 : 1);
    expect(array_unique(array_column($records, 1)))->toHaveCount($adjusted ? 2 : 1);
    expect($records[0][4])->toBe('Administración ficticia');
    expect($records[0][0])->toBe('15/10/2026 12:00:00');
    $pendingIndex = array_search('PENDIENTES CONSERVADOS', array_column($rows, 0));
    expect((float) $rows[$pendingIndex + 2][7])->toBe($adjusted ? 2.0 : 5.0);
    expect($rows[$pendingIndex + 2][5])->toBe('CONFIRMADA');
    $changesIndex = array_search('CAMBIOS DE ESTA VERSIÓN', array_column($rows, 0));
    expect((int) $rows[$changesIndex][2])->toBe($adjusted ? 2 : 0);
    if ($adjusted) {
        $changes = array_slice($rows, $changesIndex + 2);
        expect(array_column($changes, 1))->toContain('INCIDENT:'.$incident->id);
        expect(json_encode($changes))->toContain('pending', '200', '500');
        expect((float) array_sum(array_column($changes, 8)))->toBe(5.0);
    }
    expect($rows[2][1])->toBe($adjusted ? 'Ajuste v1' : 'Original');
    expect($rows[2][3])->toContain('Administración ficticia');
    $audit = ReportExport::sole();
    expect($audit)->toMatchArray(['export_scope' => 'DETAIL', 'detail_metric' => 'both', 'detail_source' => 'ALL', 'monthly_close_id' => $close->id, 'monthly_adjustment_id' => $adjusted ? $first->id : null, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    expect($audit->document_sha256)->toBe(hash('sha256', $response->getContent()));
    expect($close->fresh()->snapshot_hash)->toBe($originalHash);
    expect(MonthlyReportClose::count())->toBe(1);
    expect(MonthlyReportAdjustment::count())->toBe(2);
    Http::assertNothingSent();
    if ($folder = getenv('C363_QA_DIR')) {
        file_put_contents($folder.'/'.($adjusted ? 'v1' : 'original').'.'.$format, $response->getContent());
    }
})->with([['csv', false], ['xlsx', false], ['csv', true], ['xlsx', true]]);

it('C363 exports every preserved contribution and pending entry beyond the five-row UI page', function (string $format) {
    $actor = c363Admin();
    for ($i = 0; $i < 12; $i++) {
        c363Movement($actor, 101);
        c363Incident($actor);
    }
    $close = c363Close($actor);
    $rows = c363Rows($this->get(c363Url($close->id, null, $format))->assertOk()->getContent(), $format);
    $total = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'TOTAL CONSERVADO');
    expect((int) $total[2])->toBe(12);
    expect((float) $total[7])->toBe(12.12);
    $pending = array_search('PENDIENTES CONSERVADOS', array_column($rows, 0));
    expect((int) $rows[$pending][2])->toBe(12);
    expect(array_unique(array_column(array_slice($rows, 7, 12), 1)))->toHaveCount(12);
})->with(['csv', 'xlsx']);

it('C363 exports an empty original with zero totals and no fabricated records', function () {
    $actor = c363Admin();
    $close = c363Close($actor);
    $rows = c363Rows($this->get(c363Url($close->id, null))->assertOk()->getContent(), 'csv');
    $total = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'TOTAL CONSERVADO');
    expect((int) $total[2])->toBe(0);
    expect((float) $total[7])->toBe(0.0);
    expect((float) $total[8])->toBe(0.0);
});

it('C363 blocks cashier manager inactive and revoked administration for frozen evidence', function (string $role, bool $active, bool $adjusted) {
    $actor = c363Admin();
    $close = c363Close($actor);
    c363Movement($actor, 100);
    $first = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c363Input($actor, $close->id), '1234');
    $other = User::factory()->create(['is_active' => $active]);
    $other->assignRole($role);
    $this->actingAs($other)->get(c363Url($close->id, $adjusted ? $first->id : null))->assertForbidden();
    expect(ReportExport::count())->toBe(0);
})->with([['cajera', true, false], ['cajera', true, true], ['encargado', true, false], ['encargado', true, true], ['administrador', false, false], ['administrador', false, true]]);

it('C363 rejects guests missing records mismatched adjustments and unsupported formats', function () {
    $actor = c363Admin();
    $close = c363Close($actor);
    c363Movement($actor, 100);
    $first = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c363Input($actor, $close->id), '1234');
    $this->get(c363Url($close->id, null, 'pdf'))->assertNotFound();
    $this->get(c363Url(999999, null))->assertNotFound();
    $this->get(c363Url($close->id, 999999))->assertNotFound();
    $capture = app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-09');
    $other = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, ['month' => '2026-09', 'request_uuid' => (string) Str::uuid(), 'preview_hash' => $capture['hash'], 'accept_pending' => true], '1234');
    $this->get(c363Url($other->id, $first->id))->assertNotFound();
    auth()->logout();
    $this->get(c363Url($close->id, null))->assertRedirect();
    expect(ReportExport::count())->toBe(0);
});

it('C363 rejects unverified frozen snapshots without an export audit', function () {
    $actor = c363Admin();
    $good = c363Close($actor);
    $attributes = $good->getAttributes();
    unset($attributes['id']);
    $attributes['month'] = '2026-09';
    $attributes['request_uuid'] = (string) Str::uuid();
    $attributes['snapshot_hash'] = str_repeat('f', 64);
    $id = DB::table('report_monthly_closes')->insertGetId($attributes);
    $this->get(c363Url($id, null))->assertConflict();
    expect(ReportExport::count())->toBe(0);
});

it('C363 protects formula-like saved text in both formats without rewriting the frozen record', function (string $format) {
    $actor = c363Admin();
    $actor->update(['name' => '=HYPERLINK("test")']);
    c363Movement($actor, 123);
    $close = c363Close($actor);
    $hash = $close->snapshot_hash;
    $rows = c363Rows($this->get(c363Url($close->id, null, $format))->assertOk()->getContent(), $format);
    expect($rows[7][4])->toBe($format === 'csv' ? '\'=HYPERLINK("test")' : '=HYPERLINK("test")');
    expect($close->fresh()->snapshot_hash)->toBe($hash);
})->with(['csv', 'xlsx']);

it('C363 never audits failed rendering or permission revocation before delivery', function (bool $revoke) {
    $actor = c363Admin();
    $close = c363Close($actor);
    $this->mock(MonthlyReportEvidenceFile::class, function ($mock) use ($actor, $revoke): void {
        $mock->shouldReceive('render')->once()->andReturnUsing(function () use ($actor, $revoke): string {
            if ($revoke) {
                $actor->forceFill(['is_active' => false])->save();

                return 'discarded';
            }
            throw new RuntimeException('Export interrupted');
        });
    });
    expect(fn () => app(ExportMonthlyReportEvidenceAction::class)->execute($actor, $close->id, null, 'CSV'))->toThrow($revoke ? AuthorizationException::class : RuntimeException::class);
    expect(ReportExport::count())->toBe(0);
    expect(MonthlyReportClose::count())->toBe(1);
})->with([false, true]);

it('C363 repeated downloads leave the close and adjustments unchanged and audit each download separately', function () {
    $actor = c363Admin();
    c363Movement($actor, 123);
    $close = c363Close($actor);
    $hash = $close->snapshot_hash;
    foreach ([1, 2] as $attempt) {
        $this->get(c363Url($close->id, null))->assertOk();
    }
    expect(ReportExport::count())->toBe(2);
    expect(LoanMovement::count())->toBe(1);
    expect(MonthlyReportClose::count())->toBe(1);
    expect(MonthlyReportAdjustment::count())->toBe(0);
    expect($close->fresh()->snapshot_hash)->toBe($hash);
});

it('C363 exposes downloads for original latest and selected historical adjustment in Livewire', function () {
    $actor = c363Admin();
    $close = c363Close($actor);
    c363Movement($actor, 500);
    $first = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c363Input($actor, $close->id), '1234');
    c363Movement($actor, 100);
    $second = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c363Input($actor, $close->id), '1234');
    Livewire::test(MonthlyReport::class)->call('viewClose', $close->id)->assertSee('Detalle Excel del original')->assertSee(c363Url($close->id, null, 'xlsx'), false)->assertSee('Detalle CSV ajustado v2')->call('viewAdjustment', $first->id)->assertSee('Detalle Excel de esta versión')->assertSee(c363Url($close->id, $first->id, 'xlsx'), false);
});

it('C363 rejects an adjustment with an invalid snapshot hash', function () {
    $actor = c363Admin();
    $close = c363Close($actor);
    c363Movement($actor, 100);
    $first = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c363Input($actor, $close->id), '1234');
    $attributes = $first->getAttributes();
    unset($attributes['id']);
    $attributes['sequence'] = 2;
    $attributes['previous_adjustment_id'] = $first->id;
    $attributes['before_hash'] = $first->snapshot_hash;
    $attributes['request_uuid'] = (string) Str::uuid();
    $attributes['snapshot_hash'] = str_repeat('f', 64);
    $id = DB::table('report_monthly_adjustments')->insertGetId($attributes);
    $this->get(c363Url($close->id, $id))->assertConflict();
    expect(ReportExport::count())->toBe(0);
});

it('C363 rejects oversized frozen evidence without delivering a partial file', function () {
    $actor = c363Admin();
    $close = c363Close($actor);
    $payload = $close->snapshot;
    $payload['records'] = array_fill(0, 50001, []);
    $close->snapshot = $payload;
    $this->mock(MonthlyReportCloseQuery::class, function ($mock) use ($close): void {
        $mock->shouldReceive('detail')->once()->andReturn(['close' => $close]);
    });
    expect(fn () => app(MonthlyReportEvidenceQuery::class)->execute($actor, $close->id))->toThrow(ValidationException::class);
    expect(ReportExport::count())->toBe(0);
});

it('C363 preserves zero-value pending states and unspecified amounts without treating them as income', function () {
    $actor = c363Admin();
    $close = c363Close($actor);
    $saved = app(MonthlyReportEvidenceQuery::class)->execute($actor, $close->id);
    $saved['snapshot']['pending'] = [['key' => 'LIQUIDATION:1', 'source' => 'Liquidación pendiente', 'reference' => 'Cierre #1', 'actor_name' => 'Responsable histórico', 'actor_id' => null, 'recorded_at' => '2026-10-15 16:00:00', 'status' => 'PENDIENTE', 'amount_cents' => null]];
    $rows = c363Rows(app(MonthlyReportEvidenceFile::class)->render(['period_label' => 'Octubre', 'saved' => $saved], ['id' => 'isolated', 'actor' => 'Prueba', 'generated_at' => '02/11/2026'], [], 'CSV'), 'csv');
    $index = array_search('PENDIENTES CONSERVADOS', array_column($rows, 0));
    expect($rows[$index + 2][7])->toBe('No aplica');
    expect(collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'TOTAL CONSERVADO')[8])->toBe('0.00');
});

it('C364 keeps original and three monthly versions stable across repeated Excel and CSV summary and detail downloads', function (string $format, string $scope) {
    $actor = c363Admin();
    c363Movement($actor, 10000);
    $incident = c363Incident($actor);
    $incident->update(['amount_cents' => 900, 'pending_cents' => 900]);
    $close = c363Close($actor);
    $versions = [0 => ['adjustment' => null, 'snapshot' => $close->snapshot, 'hash' => $close->snapshot_hash, 'sales' => 100.0, 'collections' => 100.0, 'pending' => 9.0, 'count' => 1, 'actor' => 'Administración ficticia']];
    foreach ([1, 2, 3] as $sequence) {
        $actor->update(['name' => 'Administración v'.$sequence]);
        if ($sequence === 1) {
            c363Movement($actor, 2500);
        } elseif ($sequence === 2) {
            LoanMovement::create(['user_id' => $actor->id, 'kind' => 'TERM_PAYMENT', 'delta_cents' => 1000, 'before_cents' => 0, 'after_cents' => 1000, 'created_at' => '2026-10-20 16:00:00']);
        }
        $incident->update(['pending_cents' => match ($sequence) {
            1 => 600, 2 => 300, 3 => 0
        }]);
        $row = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c363Input($actor, $close->id), '1234');
        expect($row->sequence)->toBe($sequence);
        $versions[$sequence] = ['adjustment' => $row->id, 'snapshot' => $row->snapshot, 'hash' => $row->snapshot_hash, 'sales' => 125.0,
            'collections' => $sequence === 1 ? 125.0 : 135.0, 'pending' => match ($sequence) {
                1 => 6.0, 2 => 3.0, 3 => null
            },
            'count' => $sequence === 1 ? 2 : 3, 'actor' => 'Administración v'.$sequence];
    }
    c363Movement($actor, 777);
    c363Movement($actor, 100000, '2026-11-01 16:00:00');
    $actor->update(['name' => 'Generador actual después de v3']);
    $normalized = [];
    foreach ([3, 0, 1, 2, 2, 1, 0, 3] as $version) {
        $expected = $versions[$version];
        $url = $scope === 'detail' ? c363Url($close->id, $expected['adjustment'], $format) : route($version === 0 ? 'reportes.cierre-export' : 'reportes.cierre-ajuste-export', ['close' => $close->id, 'format' => $format, ...($version === 0 ? [] : ['adjustment' => $expected['adjustment']])]);
        $response = $this->get($url)->assertOk();
        $bytes = $response->getContent();
        $rows = c363Rows($bytes, $format);
        if ($scope === 'detail') {
            $total = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'TOTAL CONSERVADO');
            expect((float) $total[7])->toBe($expected['sales']);
            expect((float) $total[8])->toBe($expected['collections']);
            expect((int) $total[2])->toBe($expected['count']);
            $records = array_slice($rows, 7, $expected['count']);
            expect(array_unique(array_column($records, 1)))->toHaveCount($expected['count']);
            expect(array_unique(array_column($records, 4)))->toBe([$expected['actor']]);
            $pi = array_search('PENDIENTES CONSERVADOS', array_column($rows, 0));
            expect((int) $rows[$pi][2])->toBe($version === 3 ? 0 : 1);
            if ($version !== 3) {
                expect((float) $rows[$pi + 2][7])->toBe($expected['pending']);
                expect($rows[$pi + 2][5])->toBe('CONFIRMADA');
            }
            $hash = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Huella SHA256 conservada');
            expect($hash[1])->toBe($expected['hash']);
            expect($rows[2][1])->toBe($version === 0 ? 'Original' : 'Ajuste v'.$version);
        } else {
            expect((float) collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Ventas registradas')[1])->toBe($expected['sales']);
            expect((float) collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Cobros registrados')[1])->toBe($expected['collections']);
        }
        $stable = array_values(array_filter($rows, fn ($row) => ! str_starts_with((string) ($row[0] ?? ''), 'Documento')));
        if (isset($normalized[$version])) {
            expect($stable)->toBe($normalized[$version]);
        }
        $normalized[$version] = $stable;
        $audit = ReportExport::latest('id')->firstOrFail();
        expect($audit->monthly_close_id)->toBe($close->id);
        expect($audit->monthly_adjustment_id)->toBe($expected['adjustment']);
        expect($audit->export_scope)->toBe($scope === 'detail' ? 'DETAIL' : 'SUMMARY');
        expect($audit->document_sha256)->toBe(hash('sha256', $bytes));
        expect($close->fresh()->snapshot_hash)->toBe($versions[0]['hash']);
        if ($folder = getenv('C364_QA_DIR')) {
            $directory = $folder.'/'.$scope;
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/v'.$version.'.'.$format, $bytes);
        }
    }
    expect(ReportExport::count())->toBe(8);
    expect(ReportExport::distinct()->count('document_id'))->toBe(8);
    expect(MonthlyReportClose::count())->toBe(1);
    expect(MonthlyReportAdjustment::count())->toBe(3);
    expect(LoanMovement::count())->toBe(5);
    expect($close->fresh()->snapshot)->toBe($versions[0]['snapshot']);
    foreach ([1, 2, 3] as $version) {
        $row = MonthlyReportAdjustment::findOrFail($versions[$version]['adjustment']);
        expect($row->snapshot)->toBe($versions[$version]['snapshot']);
        expect($row->snapshot_hash)->toBe($versions[$version]['hash']);
        expect(ReportExport::where('monthly_adjustment_id', $row->id)->count())->toBe(2);
    }
    expect(ReportExport::whereNull('monthly_adjustment_id')->count())->toBe(2);
    Http::assertNothingSent();
})->with([['csv', 'detail'], ['xlsx', 'detail'], ['csv', 'summary'], ['xlsx', 'summary']]);
