<?php

use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Actions\ExportReportSummaryCsvAction;
use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Reportes\Queries\DailyReportQuery;
use App\Filament\Pages\Reportes\DailyReport;
use App\Infrastructure\Reportes\ReportSummaryCsv;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c357Admin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-31 16:00:00', 'UTC'));
    test()->seed(UsuariosPermissionsSeeder::class);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create(['name' => 'Administración de prueba']);
    $admin->assignRole('administrador');
    test()->actingAs($admin);

    return $admin;
}

function c357Data(User $actor): void
{
    foreach ([['2026-09-15 16:00:00', 7000, 'OBJECT_SALE'], ['2026-09-20 16:00:00', 1000, 'TERM_PAYMENT'], ['2026-10-20 16:00:00', 2000, 'TERM_PAYMENT'], ['2026-10-29 16:00:00', 5000, 'OBJECT_SALE'], ['2026-10-30 16:00:00', 10000, 'OBJECT_SALE'], ['2026-10-31 16:00:00', 15000, 'OBJECT_SALE']] as [$date, $amount, $kind]) {
        LoanMovement::create(['user_id' => $actor->id, 'kind' => $kind, 'delta_cents' => $amount, 'before_cents' => 0, 'after_cents' => $amount, 'created_at' => $date]);
    }
}

function c357SaveQa(string $name, string $bytes): void
{
    if ($folder = getenv('C357_QA_DIR')) {
        file_put_contents($folder.DIRECTORY_SEPARATOR.$name.'.csv', $bytes);
    }
}

function c357Read(string $bytes): array
{
    expect($bytes)->toStartWith("\xEF\xBB\xBF");
    $stream = fopen('php://temp', 'w+b');
    try {
        fwrite($stream, substr($bytes, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            expect($row)->toHaveCount(4);
            $rows[] = $row;
        }

        return $rows;
    } finally {
        fclose($stream);
    }
}

it('C357 downloads and audits a real CSV workbook with the correct period comparison and saved totals', function (array $input, array $expected) {
    $admin = c357Admin();
    c357Data($admin);
    $this->mock(ReportSummaryCsv::class, function ($mock) use ($expected): void {
        $mock->shouldReceive('render')->once()->andReturnUsing(function (array $report, array $document) use ($expected) {
            expect($report['period']['start'])->toBe($expected['start']);
            expect($report['period']['end'])->toBe($expected['end']);
            expect($report['sales_cents'])->toBe($expected['sales']);
            expect($report['collections_cents'])->toBe($expected['collections']);
            expect($report['comparison']['sales']['previous_cents'])->toBe($expected['previous_sales']);
            expect($report['comparison']['collections']['previous_cents'])->toBe($expected['previous_collections']);
            expect($report['detail'])->toBeNull();

            return (new ReportSummaryCsv)->render($report, $document);
        });
    });
    $response = $this->get(route('reportes.resumen-csv', $input))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    $bytes = $response->getContent();
    expect($bytes)->toStartWith("\xEF\xBB\xBF");
    $rows = c357Read($bytes);
    $sales = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Ventas registradas');
    $collections = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Cobros registrados');
    expect((float) $sales[1])->toBe($expected['sales'] / 100.0);
    expect((float) $sales[2])->toBe($expected['previous_sales'] / 100.0);
    expect((float) $sales[3])->toBe(($expected['sales'] - $expected['previous_sales']) / 100.0);
    expect((float) $collections[1])->toBe($expected['collections'] / 100.0);
    expect((float) $collections[2])->toBe($expected['previous_collections'] / 100.0);
    expect((float) $collections[3])->toBe(($expected['collections'] - $expected['previous_collections']) / 100.0);
    $text = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect($text)->toContain('Administración de prueba', '31/10/2026 12:00:00', 'Dinero disponible al generar');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="Reporte_'.$expected['start'].'_a_'.$expected['end'].'.csv"');
    $audit = ReportExport::sole();
    expect($audit)->toMatchArray(['user_id' => $admin->id, 'actor_name' => 'Administración de prueba', 'format' => 'CSV', 'period_mode' => $input['mode'], 'period_start' => $expected['start'], 'period_end' => $expected['end'], 'document_sha256' => hash('sha256', $bytes)]);
    expect($audit->previous_start)->toBe($expected['previous_start']);
    expect($rows[1][1])->toBe($input['mode'] === 'day' ? '31/10/2026' : match ($input['mode']) {
        'week' => '26/10/2026 — 31/10/2026', 'month' => '01/10/2026 — 31/10/2026', 'range' => '29/10/2026 — 31/10/2026'
    });
    expect($rows[2][1])->toBe(match ($input['mode']) {
        'day' => '30/10/2026', 'week' => '19/10/2026 — 25/10/2026', 'month' => '01/09/2026 — 30/09/2026', 'range' => '26/10/2026 — 28/10/2026'
    });
    expect($audit->previous_end)->toBe($expected['previous_end']);
    expect($audit->generated_at->setTimezone('America/La_Paz')->format('d/m/Y H:i:s'))->toBe('31/10/2026 12:00:00');
    expect(LoanMovement::count())->toBe(6);
    Http::assertNothingSent();
    c357SaveQa($input['mode'], $bytes);
})->with([
    'day' => [['mode' => 'day', 'date' => '2026-10-31'], ['start' => '2026-10-31', 'end' => '2026-10-31', 'sales' => 15000, 'collections' => 15000, 'previous_sales' => 10000, 'previous_collections' => 10000, 'previous_start' => '2026-10-30', 'previous_end' => '2026-10-30']],
    'week' => [['mode' => 'week', 'date' => '2026-10-31'], ['start' => '2026-10-26', 'end' => '2026-10-31', 'sales' => 30000, 'collections' => 30000, 'previous_sales' => 0, 'previous_collections' => 2000, 'previous_start' => '2026-10-19', 'previous_end' => '2026-10-25']],
    'month' => [['mode' => 'month', 'date' => '2026-10-15'], ['start' => '2026-10-01', 'end' => '2026-10-31', 'sales' => 30000, 'collections' => 32000, 'previous_sales' => 7000, 'previous_collections' => 8000, 'previous_start' => '2026-09-01', 'previous_end' => '2026-09-30']],
    'range' => [['mode' => 'range', 'from' => '2026-10-29', 'until' => '2026-10-31'], ['start' => '2026-10-29', 'end' => '2026-10-31', 'sales' => 30000, 'collections' => 30000, 'previous_sales' => 0, 'previous_collections' => 0, 'previous_start' => '2026-10-26', 'previous_end' => '2026-10-28']],
]);

it('C357 exports only applied filters even after editing other pending controls', function () {
    c357Admin();
    $page = Livewire::test(DailyReport::class)->set('periodMode', 'range')->set('rangeFrom', '2026-10-20')->set('rangeUntil', '2026-10-22');
    $url = $page->instance()->getCachedHeaderActions()[2]->getUrl();
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['mode' => 'day', 'date' => '2026-10-31', 'expected_start' => '2026-10-31', 'expected_end' => '2026-10-31']);
    $page->call('applyDate')->set('rangeFrom', '2026-10-21');
    $url = $page->instance()->getCachedHeaderActions()[2]->getUrl();
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['mode' => 'range', 'from' => '2026-10-20', 'until' => '2026-10-22', 'expected_start' => '2026-10-20', 'expected_end' => '2026-10-22']);
    $this->get($url)->assertOk();
    expect(ReportExport::sole()->period_start)->toBe('2026-10-20');
});

it('C357 denies the direct CSV URL to guests and all roles except active Administration', function (string $role, bool $active) {
    c357Admin();
    auth()->logout();
    $url = route('reportes.resumen-csv', ['mode' => 'day', 'date' => '2026-10-31']);
    $this->get($url)->assertRedirect();
    $user = User::factory()->create(['is_active' => $active]);
    $user->assignRole($role);
    $this->actingAs($user)->get($url)->assertForbidden();
    expect(ReportExport::count())->toBe(0);
})->with([['cajera', true], ['encargado', true], ['administrador', false]]);

it('C357 rejects invalid export selections without recording a successful export', function (array $input) {
    c357Admin();
    $this->getJson(route('reportes.resumen-csv', $input))->assertUnprocessable();
    expect(ReportExport::count())->toBe(0);
})->with([
    'invalid mode' => [['mode' => 'all', 'date' => '2026-10-31']],
    'missing date' => [['mode' => 'month']],
    'invalid date' => [['mode' => 'day', 'date' => '2026-02-30']],
    'future date' => [['mode' => 'day', 'date' => '2026-11-01']],
    'reverse range' => [['mode' => 'range', 'from' => '2026-10-31', 'until' => '2026-10-20']],
    'array input' => [['mode' => ['day'], 'date' => '2026-10-31']],
]);

it('C357 refuses to silently change a consulted period when the day rolls over', function () {
    c357Admin();
    test()->travelTo(CarbonImmutable::parse('2026-11-01 16:00:00', 'UTC'));
    $this->get(route('reportes.resumen-csv', ['mode' => 'week', 'date' => '2026-10-31', 'expected_start' => '2026-10-26', 'expected_end' => '2026-10-31']))->assertConflict();
    expect(ReportExport::count())->toBe(0);
});

it('C357 rejects revoked access after rendering before returning the sensitive CSV', function () {
    $admin = c357Admin();
    $this->mock(ReportSummaryCsv::class, function ($mock) use ($admin): void {
        $mock->shouldReceive('render')->once()->andReturnUsing(function (array $report, array $document) use ($admin) {
            User::findOrFail($admin->id)->removeRole('administrador');

            return (new ReportSummaryCsv)->render($report, $document);
        });
    });
    expect(fn () => app(ExportReportSummaryCsvAction::class)->execute($admin, ['mode' => 'day', 'date' => '2026-10-31']))->toThrow(AuthorizationException::class);
    expect(ReportExport::count())->toBe(0);
});

it('C357 does not audit failed rendering and cannot return an unaudited document', function () {
    $admin = c357Admin();
    $this->mock(ReportSummaryCsv::class, function ($mock): void {
        $mock->shouldReceive('render')->once()->andThrow(new RuntimeException('Fallo simulado'));
    });
    expect(fn () => app(ExportReportSummaryCsvAction::class)->execute($admin, ['mode' => 'day', 'date' => '2026-10-31']))->toThrow(RuntimeException::class);
    expect(ReportExport::count())->toBe(0);
});

it('C357 blocks CSV delivery if its audit write is interrupted without changing financial history', function () {
    $admin = c357Admin();
    c357Data($admin);
    DB::unprepared("CREATE TRIGGER c357_stop_export BEFORE INSERT ON report_exports BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    expect(fn () => app(ExportReportSummaryCsvAction::class)->execute($admin, ['mode' => 'day', 'date' => '2026-10-31']))->toThrow(QueryException::class);
    expect(ReportExport::count())->toBe(0);
    expect(LoanMovement::count())->toBe(6);
});

it('C357 repeated downloads audit separate generated documents without repeating collections', function () {
    $admin = c357Admin();
    c357Data($admin);
    $url = route('reportes.resumen-csv', ['mode' => 'day', 'date' => '2026-10-31']);
    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    expect(ReportExport::count())->toBe(2);
    expect(ReportExport::pluck('document_id')->unique())->toHaveCount(2);
    expect(LoanMovement::count())->toBe(6);
    $audit = ReportExport::first();
    expect(fn () => $audit->update(['actor_name' => 'Reescritura']))->toThrow(LogicException::class);
    expect(fn () => $audit->delete())->toThrow(LogicException::class);
    Http::assertNothingSent();
});

it('C357 renders empty data and unavailable prior comparison without inventing balances', function () {
    c357Admin();
    $response = $this->get(route('reportes.resumen-csv', ['mode' => 'day', 'date' => '1900-01-01']))->assertOk();
    expect($response->getContent())->toStartWith("\xEF\xBB\xBF");
    expect(ReportExport::sole()->previous_start)->toBeNull();
    $rows = c357Read($response->getContent());
    expect($rows[2][1])->toBe('Sin comparación disponible');
    $sales = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Ventas registradas');
    expect($sales)->toBe(['Ventas registradas', '0.00', 'No disponible', 'Sin comparación disponible']);
    c357SaveQa('empty', $response->getContent());
});

it('C357 preserves CSV quoting and all funds while neutralizing formula-like text', function () {
    $admin = c357Admin();
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-31');
    $dangerous = ['=HYPERLINK("https://invalid.test")', '+SUM(1,2)', '-2+3', '@SUM(1,2)', '  =1+1', "\t=1+1", "\r=1+1", "\n=1+1", "\u{00A0}=1+1"];
    foreach ($dangerous as $text) {
        $report['balances'][] = ['label' => $text, 'amount_cents' => 123456, 'note' => $text];
    }
    $quoted = "Caja, \"Ágil\"\r\nBolivia \\ cierre";
    $report['balances'][] = ['label' => $quoted, 'amount_cents' => -1, 'note' => $quoted];
    for ($i = 1; $i <= 35; $i++) {
        $report['balances'][] = ['label' => 'Fondo de prueba '.$i, 'amount_cents' => 123456, 'note' => 'Saldo actual, no histórico.'];
    }
    $bytes = app(ReportSummaryCsv::class)->render($report, ['id' => '00000000-0000-4000-8000-000000000357', 'actor' => '=1+1', 'generated_at' => '31/10/2026 12:00:00', 'mode_label' => 'Día']);
    $rows = c357Read($bytes);
    expect($rows[3][1])->toBe("'=1+1");
    foreach ($dangerous as $text) {
        $row = collect($rows)->first(fn ($row) => $row[0] === "'".$text);
        expect($row)->toBe(["'".$text, '1234.56', "'".$text, '']);
    }
    expect(collect($rows)->first(fn ($row) => $row[0] === $quoted))->toBe([$quoted, '-0.01', $quoted, '']);
    expect(collect($rows)->first(fn ($row) => $row[0] === 'Fondo de prueba 35')[1])->toBe('1234.56');
    expect($bytes)->toContain('""Ágil""')->toEndWith("\r\n");
    expect(ReportExport::count())->toBe(0);
    c357SaveQa('all-funds', $bytes);
});

it('C357 preserves cents negative differences and zero as numbers instead of currency strings', function () {
    $admin = c357Admin();
    LoanMovement::create(['user_id' => $admin->id, 'kind' => 'OBJECT_SALE', 'delta_cents' => 12345, 'before_cents' => 0, 'after_cents' => 12345, 'created_at' => '2026-10-30 16:00:00']);
    $bytes = $this->get(route('reportes.resumen-csv', ['mode' => 'day', 'date' => '2026-10-31']))->assertOk()->getContent();
    $rows = c357Read($bytes);
    $sales = collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Ventas registradas');
    expect($sales)->toBe(['Ventas registradas', '0.00', '123.45', '-123.45']);
    expect(LoanMovement::count())->toBe(1);
});
