<?php

use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Actions\ExportReportSummaryPdfAction;
use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Reportes\Queries\DailyReportQuery;
use App\Filament\Pages\Reportes\DailyReport;
use App\Infrastructure\Reportes\ReportSummaryPdf;
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

function c355Admin(): User
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

function c355Data(User $actor): void
{
    foreach ([['2026-09-15 16:00:00', 7000, 'OBJECT_SALE'], ['2026-09-20 16:00:00', 1000, 'TERM_PAYMENT'], ['2026-10-20 16:00:00', 2000, 'TERM_PAYMENT'], ['2026-10-29 16:00:00', 5000, 'OBJECT_SALE'], ['2026-10-30 16:00:00', 10000, 'OBJECT_SALE'], ['2026-10-31 16:00:00', 15000, 'OBJECT_SALE']] as [$date, $amount, $kind]) {
        LoanMovement::create(['user_id' => $actor->id, 'kind' => $kind, 'delta_cents' => $amount, 'before_cents' => 0, 'after_cents' => $amount, 'created_at' => $date]);
    }
}

function c355SaveQa(string $name, string $bytes): void
{
    if ($folder = getenv('C355_QA_DIR')) {
        file_put_contents($folder.DIRECTORY_SEPARATOR.$name.'.pdf', $bytes);
    }
}

it('C355 downloads and audits a real Letter PDF with the correct period comparison and saved totals', function (array $input, array $expected) {
    $admin = c355Admin();
    c355Data($admin);
    $this->mock(ReportSummaryPdf::class, function ($mock) use ($expected): void {
        $mock->shouldReceive('render')->once()->andReturnUsing(function (array $report, array $document) use ($expected) {
            expect($report['period']['start'])->toBe($expected['start']);
            expect($report['period']['end'])->toBe($expected['end']);
            expect($report['sales_cents'])->toBe($expected['sales']);
            expect($report['collections_cents'])->toBe($expected['collections']);
            expect($report['comparison']['sales']['previous_cents'])->toBe($expected['previous_sales']);
            expect($report['comparison']['collections']['previous_cents'])->toBe($expected['previous_collections']);
            expect($report['detail'])->toBeNull();

            return (new ReportSummaryPdf)->render($report, $document);
        });
    });
    $response = $this->get(route('reportes.resumen-pdf', $input))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    $bytes = $response->getContent();
    expect($bytes)->toStartWith('%PDF-');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="Reporte_'.$expected['start'].'_a_'.$expected['end'].'.pdf"');
    $audit = ReportExport::sole();
    expect($audit)->toMatchArray(['user_id' => $admin->id, 'actor_name' => 'Administración de prueba', 'format' => 'PDF', 'period_mode' => $input['mode'], 'period_start' => $expected['start'], 'period_end' => $expected['end'], 'document_sha256' => hash('sha256', $bytes)]);
    expect($audit->previous_start)->toBe($expected['previous_start']);
    expect($audit->previous_end)->toBe($expected['previous_end']);
    expect($audit->generated_at->setTimezone('America/La_Paz')->format('d/m/Y H:i:s'))->toBe('31/10/2026 12:00:00');
    expect(LoanMovement::count())->toBe(6);
    Http::assertNothingSent();
    c355SaveQa($input['mode'], $bytes);
})->with([
    'day' => [['mode' => 'day', 'date' => '2026-10-31'], ['start' => '2026-10-31', 'end' => '2026-10-31', 'sales' => 15000, 'collections' => 15000, 'previous_sales' => 10000, 'previous_collections' => 10000, 'previous_start' => '2026-10-30', 'previous_end' => '2026-10-30']],
    'week' => [['mode' => 'week', 'date' => '2026-10-31'], ['start' => '2026-10-26', 'end' => '2026-10-31', 'sales' => 30000, 'collections' => 30000, 'previous_sales' => 0, 'previous_collections' => 2000, 'previous_start' => '2026-10-19', 'previous_end' => '2026-10-25']],
    'month' => [['mode' => 'month', 'date' => '2026-10-15'], ['start' => '2026-10-01', 'end' => '2026-10-31', 'sales' => 30000, 'collections' => 32000, 'previous_sales' => 7000, 'previous_collections' => 8000, 'previous_start' => '2026-09-01', 'previous_end' => '2026-09-30']],
    'range' => [['mode' => 'range', 'from' => '2026-10-29', 'until' => '2026-10-31'], ['start' => '2026-10-29', 'end' => '2026-10-31', 'sales' => 30000, 'collections' => 30000, 'previous_sales' => 0, 'previous_collections' => 0, 'previous_start' => '2026-10-26', 'previous_end' => '2026-10-28']],
]);

it('C355 exports only applied filters even after editing other pending controls', function () {
    c355Admin();
    $page = Livewire::test(DailyReport::class)->set('periodMode', 'range')->set('rangeFrom', '2026-10-20')->set('rangeUntil', '2026-10-22');
    $url = $page->instance()->getCachedHeaderActions()[0]->getUrl();
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['mode' => 'day', 'date' => '2026-10-31', 'expected_start' => '2026-10-31', 'expected_end' => '2026-10-31']);
    $page->call('applyDate')->set('rangeFrom', '2026-10-21');
    $url = $page->instance()->getCachedHeaderActions()[0]->getUrl();
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['mode' => 'range', 'from' => '2026-10-20', 'until' => '2026-10-22', 'expected_start' => '2026-10-20', 'expected_end' => '2026-10-22']);
    $this->get($url)->assertOk();
    expect(ReportExport::sole()->period_start)->toBe('2026-10-20');
});

it('C355 denies the direct PDF URL to guests and all roles except active Administration', function (string $role, bool $active) {
    c355Admin();
    auth()->logout();
    $url = route('reportes.resumen-pdf', ['mode' => 'day', 'date' => '2026-10-31']);
    $this->get($url)->assertRedirect();
    $user = User::factory()->create(['is_active' => $active]);
    $user->assignRole($role);
    $this->actingAs($user)->get($url)->assertForbidden();
    expect(ReportExport::count())->toBe(0);
})->with([['cajera', true], ['encargado', true], ['administrador', false]]);

it('C355 rejects invalid export selections without recording a successful export', function (array $input) {
    c355Admin();
    $this->getJson(route('reportes.resumen-pdf', $input))->assertUnprocessable();
    expect(ReportExport::count())->toBe(0);
})->with([
    'invalid mode' => [['mode' => 'all', 'date' => '2026-10-31']],
    'missing date' => [['mode' => 'month']],
    'invalid date' => [['mode' => 'day', 'date' => '2026-02-30']],
    'future date' => [['mode' => 'day', 'date' => '2026-11-01']],
    'reverse range' => [['mode' => 'range', 'from' => '2026-10-31', 'until' => '2026-10-20']],
    'array input' => [['mode' => ['day'], 'date' => '2026-10-31']],
]);

it('C355 refuses to silently change a consulted period when the day rolls over', function () {
    c355Admin();
    test()->travelTo(CarbonImmutable::parse('2026-11-01 16:00:00', 'UTC'));
    $this->get(route('reportes.resumen-pdf', ['mode' => 'week', 'date' => '2026-10-31', 'expected_start' => '2026-10-26', 'expected_end' => '2026-10-31']))->assertConflict();
    expect(ReportExport::count())->toBe(0);
});

it('C355 rejects revoked access after rendering before returning the sensitive PDF', function () {
    $admin = c355Admin();
    $this->mock(ReportSummaryPdf::class, function ($mock) use ($admin): void {
        $mock->shouldReceive('render')->once()->andReturnUsing(function (array $report, array $document) use ($admin) {
            User::findOrFail($admin->id)->removeRole('administrador');

            return (new ReportSummaryPdf)->render($report, $document);
        });
    });
    expect(fn () => app(ExportReportSummaryPdfAction::class)->execute($admin, ['mode' => 'day', 'date' => '2026-10-31']))->toThrow(AuthorizationException::class);
    expect(ReportExport::count())->toBe(0);
});

it('C355 does not audit failed rendering and cannot return an unaudited document', function () {
    $admin = c355Admin();
    $this->mock(ReportSummaryPdf::class, function ($mock): void {
        $mock->shouldReceive('render')->once()->andThrow(new RuntimeException('Fallo simulado'));
    });
    expect(fn () => app(ExportReportSummaryPdfAction::class)->execute($admin, ['mode' => 'day', 'date' => '2026-10-31']))->toThrow(RuntimeException::class);
    expect(ReportExport::count())->toBe(0);
});

it('C355 blocks PDF delivery if its audit write is interrupted without changing financial history', function () {
    $admin = c355Admin();
    c355Data($admin);
    DB::unprepared("CREATE TRIGGER c355_stop_export BEFORE INSERT ON report_exports BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    expect(fn () => app(ExportReportSummaryPdfAction::class)->execute($admin, ['mode' => 'day', 'date' => '2026-10-31']))->toThrow(QueryException::class);
    expect(ReportExport::count())->toBe(0);
    expect(LoanMovement::count())->toBe(6);
});

it('C355 repeated downloads audit separate generated documents without repeating collections', function () {
    $admin = c355Admin();
    c355Data($admin);
    $url = route('reportes.resumen-pdf', ['mode' => 'day', 'date' => '2026-10-31']);
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

it('C355 renders empty data and unavailable prior comparison without inventing balances', function () {
    c355Admin();
    $response = $this->get(route('reportes.resumen-pdf', ['mode' => 'day', 'date' => '1900-01-01']))->assertOk();
    expect($response->getContent())->toStartWith('%PDF-');
    expect(ReportExport::sole()->previous_start)->toBeNull();
    c355SaveQa('empty', $response->getContent());
});

it('C355 safely renders long names and multiple pages without dropping the final funds', function () {
    $admin = c355Admin();
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-31');
    for ($i = 1; $i <= 35; $i++) {
        $report['balances'][] = ['label' => 'Fondo de prueba '.$i.' - nombre extenso para verificar el salto de línea', 'amount_cents' => 123456, 'note' => 'Saldo actual de ejemplo, separado de los demás fondos. Conserva su detalle completo.'];
    }
    $document = ['id' => '00000000-0000-4000-8000-000000000355', 'actor' => '<script>alert(1)</script> Administración de prueba con nombre extenso', 'generated_at' => '31/10/2026 12:00:00', 'mode_label' => 'Día'];
    $html = view('reportes.summary-pdf', ['report' => $report, 'document' => $document, 'logoData' => null])->render();
    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    expect($html)->not->toContain('<script>alert(1)</script>');
    $pdf = app(ReportSummaryPdf::class)->render($report, $document);
    $bytes = $pdf->output();
    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBeGreaterThan(1);
    c355SaveQa('multiple-pages', $bytes);
    expect(ReportExport::count())->toBe(0);
});
