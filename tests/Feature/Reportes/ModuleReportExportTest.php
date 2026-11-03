<?php

use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Actions\ExportModuleReportAction;
use App\Domain\Reportes\Models\ReportExport;
use App\Filament\Pages\Reportes\LoanReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function c370Admin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-11-03 16:00:00', 'UTC'));
    Http::preventStrayRequests();
    test()->seed(UsuariosPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
it('C370 exports each module and month with embedded graphs and no financial mutations', function (string $module, string $format) {
    $u = c370Admin();
    LoanMovement::create(['user_id' => $u->id, 'kind' => 'TERM_PAYMENT', 'delta_cents' => 12345, 'before_cents' => 0, 'after_cents' => 12345, 'created_at' => '2026-10-15 16:00:00']);
    $result = app(ExportModuleReportAction::class)->execute($u, $module, '2026-10', $format);
    expect($result['filename'])->toBe('Reporte_'.$module.'_2026-10.'.strtolower($format));
    expect(ReportExport::sole()->document_sha256)->toBe(hash('sha256', $result['bytes']))->and(ReportExport::sole()->detail_source)->toBe($module);
    if ($format === 'PDF') {
        expect($result['bytes'])->toStartWith('%PDF-')->and(substr_count($result['bytes'], '/Subtype /Image'))->toBeGreaterThanOrEqual(2);
    } else {
        $path = tempnam(sys_get_temp_dir(), 'c370');
        file_put_contents($path, $result['bytes']);
        $z = new ZipArchive;
        $z->open($path);
        expect($z->getFromName('xl/media/trend.png'))->toStartWith("\x89PNG");
        expect($z->getFromName('xl/media/distribution.png'))->toStartWith("\x89PNG");
        expect($z->getFromName('xl/worksheets/sheet1.xml'))->toContain('2026-10', 'rIdModuleCharts');
        expect($z->getFromName('xl/worksheets/sheet2.xml'))->toContain('2026-10-15');
        $z->close();
        unlink($path);
    }
    if ($module === 'LOANS') {
        $folder = storage_path('app/private/testing/C370');
        File::ensureDirectoryExists($folder);
        file_put_contents($folder.'/example.'.strtolower($format), $result['bytes']);
    }
    expect(LoanMovement::count())->toBe(1)->and(DB::table('report_monthly_closes')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['BOXES', 'CARDS', 'LOANS', 'ACCESSORIES'])->with(['PDF', 'XLSX']);
it('C370 protects exports for cashier manager and revoked administrators', function () {
    $admin = c370Admin();
    foreach (['cajera', 'encargado'] as $role) {
        $u = User::factory()->create();
        $u->assignRole($role);
        test()->actingAs($u);
        test()->get(route('reportes.modulo-export', ['module' => 'LOANS', 'format' => 'pdf', 'month' => '2026-10']))->assertForbidden();
    }
    User::whereKey($admin->id)->update(['is_active' => false]);
    expect(fn () => app(ExportModuleReportAction::class)->execute($admin, 'LOANS', '2026-10', 'PDF'))->toThrow(AuthorizationException::class);
    expect(ReportExport::count())->toBe(0);
});
it('C370 exports the applied month rather than an unapplied filter and delivers private attachments', function () {
    $admin = c370Admin();
    test()->actingAs($admin);
    $page = Livewire::test(LoanReport::class)->set('month', '2026-10')->call('applyMonth')->set('month', '2026-09');
    $url = $page->instance()->moduleExportUrl('xlsx');
    expect($url)->toContain('month=2026-10');
    test()->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(ReportExport::sole()->period_start)->toBe('2026-10-01');
});
it('C370 refuses invalid future months without auditing a download', function () {
    $u = c370Admin();
    expect(fn () => app(ExportModuleReportAction::class)->execute($u, 'CARDS', '2027-01', 'PDF'))->toThrow(ValidationException::class);
    expect(ReportExport::count())->toBe(0);
});
