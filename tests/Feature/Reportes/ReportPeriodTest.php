<?php

use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Data\ReportPeriod;
use App\Domain\Reportes\Queries\DailyReportQuery;
use App\Domain\Reportes\Queries\DailyReportRecordsQuery;
use App\Filament\Pages\Reportes\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c354Admin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-31 16:00:00', 'UTC'));
    test()->seed(UsuariosPermissionsSeeder::class);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');

    return $admin;
}

function c354Receipt(User $user, string $date, int $amount, string $kind = 'OBJECT_SALE'): void
{
    LoanMovement::create(['user_id' => $user->id, 'kind' => $kind, 'delta_cents' => $amount, 'before_cents' => 0, 'after_cents' => $amount, 'created_at' => $date]);
}

it('C354 resolves calendar periods and adjacent equal-length custom ranges', function (string $mode, string $date, ?string $from, ?string $until, array $expected) {
    c354Admin();
    $period = app(ReportPeriod::class)->resolve($mode, $date, $from, $until);
    expect($period)->toMatchArray($expected);
})->with([
    'full week Monday to Sunday' => ['week', '2026-10-15', null, null, ['start' => '2026-10-12', 'end' => '2026-10-18', 'previous_start' => '2026-10-05', 'previous_end' => '2026-10-11', 'is_current' => false]],
    'current week stops today' => ['week', '2026-10-31', null, null, ['start' => '2026-10-26', 'end' => '2026-10-31', 'previous_start' => '2026-10-19', 'previous_end' => '2026-10-25', 'is_current' => true]],
    'week crossing year' => ['week', '2026-01-01', null, null, ['start' => '2025-12-29', 'end' => '2026-01-04', 'previous_start' => '2025-12-22', 'previous_end' => '2025-12-28']],
    'month crossing year' => ['month', '2026-01-15', null, null, ['start' => '2026-01-01', 'end' => '2026-01-31', 'previous_start' => '2025-12-01', 'previous_end' => '2025-12-31']],
    'leap February' => ['month', '2024-03-10', null, null, ['start' => '2024-03-01', 'end' => '2024-03-31', 'previous_start' => '2024-02-01', 'previous_end' => '2024-02-29']],
    'range inclusive three days' => ['range', '', '2026-10-20', '2026-10-22', ['start' => '2026-10-20', 'end' => '2026-10-22', 'previous_start' => '2026-10-17', 'previous_end' => '2026-10-19']],
    'range one day' => ['range', '', '2026-10-20', '2026-10-20', ['previous_start' => '2026-10-19', 'previous_end' => '2026-10-19']],
]);

it('C354 clips the current month in Bolivia without truncating the prior calendar month', function () {
    c354Admin();
    test()->travelTo(CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'));
    $period = app(ReportPeriod::class)->resolve('month', '2026-10-10');
    expect($period)->toMatchArray(['start' => '2026-10-01', 'end' => '2026-10-15', 'previous_start' => '2026-09-01', 'previous_end' => '2026-09-30', 'is_current' => true]);
});

it('C354 sums a weekly period and its predecessor without including adjacent weeks', function () {
    $admin = c354Admin();
    foreach (['2026-10-05 04:00:00' => 100, '2026-10-12 03:59:59' => 200, '2026-10-12 04:00:00' => 400, '2026-10-19 03:59:59' => 800, '2026-10-19 04:00:00' => 1600] as $date => $amount) {
        c354Receipt($admin, $date, $amount);
    }
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-15', selection: ['mode' => 'week']);
    expect($report['sales_cents'])->toBe(1200);
    expect($report['comparison']['sales'])->toMatchArray(['previous_cents' => 300, 'difference_cents' => 900]);
    expect($report['period']['label'])->toBe('12/10/2026 — 18/10/2026');
});

it('C354 compares monthly sales and collections independently across unequal calendar months', function () {
    $admin = c354Admin();
    c354Receipt($admin, '2026-09-01 04:00:00', 10000);
    c354Receipt($admin, '2026-09-30 16:00:00', 10000, 'TERM_PAYMENT');
    c354Receipt($admin, '2026-10-01 04:00:00', 15000);
    c354Receipt($admin, '2026-10-31 16:00:00', 1000, 'TERM_PAYMENT');
    c354Receipt($admin, '2026-09-01 03:59:59', 99999);
    c354Receipt($admin, '2026-10-31 16:00:00', 99999, 'ANNULMENT');
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-15', selection: ['mode' => 'month']);
    expect($report['comparison']['sales'])->toMatchArray(['current_cents' => 15000, 'previous_cents' => 10000, 'difference_cents' => 5000]);
    expect($report['comparison']['collections'])->toMatchArray(['current_cents' => 16000, 'previous_cents' => 20000, 'difference_cents' => -4000]);
    expect($report['period']['previous_label'])->toBe('01/09/2026 — 30/09/2026');
});

it('C354 reconciles range detail and comparison with identical inclusive date filters', function () {
    $admin = c354Admin();
    foreach (['2026-10-17 04:00:00' => 100, '2026-10-20 03:59:59' => 200, '2026-10-20 04:00:00' => 400, '2026-10-23 03:59:59' => 800, '2026-10-23 04:00:00' => 1600] as $date => $amount) {
        c354Receipt($admin, $date, $amount);
    }
    $report = app(DailyReportQuery::class)->execute($admin, '', ['metric' => 'sales', 'source' => 'ALL', 'page' => 1, 'per_page' => 5], ['mode' => 'range', 'from' => '2026-10-20', 'until' => '2026-10-22']);
    expect($report['sales_cents'])->toBe(1200);
    expect($report['comparison']['sales']['previous_cents'])->toBe(300);
    expect($report['detail']['total_cents'])->toBe(1200);
    expect($report['detail']['records']->sum('amount_cents'))->toBe(1200);
    expect($report['detail']['period_label'])->toBe('20/10/2026 — 22/10/2026');
    expect($report['detail']['records']->total())->toBe(2);
});

it('C354 preserves detail pages and applies new period filters only after consultation', function () {
    $admin = c354Admin();
    for ($i = 0; $i < 12; $i++) {
        c354Receipt($admin, $i < 6 ? '2026-10-20 16:00:00' : '2026-10-22 16:00:00', 100);
    }
    test()->actingAs($admin);
    $page = Livewire::test(DailyReport::class)->set('periodMode', 'range')->set('rangeFrom', '2026-10-20')->set('rangeUntil', '2026-10-22')->assertSet('appliedMode', 'day')
        ->call('applyDate')->assertHasNoErrors()->assertSet('appliedMode', 'range')->assertSee('20/10/2026 — 22/10/2026')->assertSee('17/10/2026 — 19/10/2026')
        ->call('showDetails', 'sales')->assertSee('Bs 12')->call('nextPage', 'reportRecordsPage')->assertSet('paginators.reportRecordsPage', 2)
        ->set('detailPerPage', 10)->assertSet('paginators.reportRecordsPage', 1)
        ->set('periodMode', 'week')->set('date', '2026-10-15')->call('applyDate')->assertSet('detailOpen', false)->assertSet('paginators.reportRecordsPage', 1)->assertSee('12/10/2026 — 18/10/2026');
    expect(fn () => Livewire::test(DailyReport::class)->set('appliedMode', 'month'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('C354 keeps the last valid report after an invalid range and clears errors after correction', function () {
    $admin = c354Admin();
    test()->actingAs($admin);
    Livewire::test(DailyReport::class)->set('periodMode', 'range')->set('rangeFrom', '2026-10-22')->set('rangeUntil', '2026-10-20')
        ->call('applyDate')->assertHasErrors(['rangeUntil'])->assertSet('appliedMode', 'day')
        ->set('rangeUntil', '2026-10-23')->call('applyDate')->assertHasNoErrors()->assertSet('appliedMode', 'range');
});

it('C354 rejects invalid arbitrary periods and direct record ranges', function (array $selection) {
    $admin = c354Admin();
    expect(fn () => app(DailyReportQuery::class)->execute($admin, '2026-10-31', selection: $selection))->toThrow(ValidationException::class);
})->with([
    'unknown mode' => [['mode' => 'year']],
    'missing dates' => [['mode' => 'range']],
    'reverse dates' => [['mode' => 'range', 'from' => '2026-10-22', 'until' => '2026-10-20']],
    'future end' => [['mode' => 'range', 'from' => '2026-10-22', 'until' => '2026-11-01']],
    'invalid date' => [['mode' => 'range', 'from' => '2026-02-30', 'until' => '2026-10-31']],
]);

it('C354 validates direct record endpoints and preserves unavailable comparisons at the lower limit', function () {
    $admin = c354Admin();
    $query = app(DailyReportRecordsQuery::class);
    expect(fn () => $query->records($admin, '2026-10-22', untilDate: '2026-10-20'))->toThrow(ValidationException::class);
    expect(fn () => $query->summary($admin, '2026-10-20', '2026-11-01'))->toThrow(ValidationException::class);
    $report = app(DailyReportQuery::class)->execute($admin, '', selection: ['mode' => 'range', 'from' => '1900-01-01', 'until' => '1900-01-03']);
    expect($report['comparison']['available'])->toBeFalse();
    expect($report['comparison']['sales'])->toBeNull();
});

it('C354 authorizes all period modes only for active Administration', function (string $role, bool $active) {
    c354Admin();
    $user = User::factory()->create(['is_active' => $active]);
    $user->assignRole($role);
    test()->actingAs($user);
    expect(fn () => app(DailyReportQuery::class)->execute($user, '2026-10-31', selection: ['mode' => 'month']))->toThrow(AuthorizationException::class);
    Livewire::test(DailyReport::class)->assertForbidden();
})->with([['cajera', true], ['encargado', true], ['administrador', false]]);

it('C354 adds no per-day query loop or financial writes for longer ranges', function () {
    $admin = c354Admin();
    c354Receipt($admin, '2026-10-20 16:00:00', 100);
    $query = app(DailyReportQuery::class);
    $query->execute($admin, '2026-10-31');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $query->execute($admin, '2026-10-31');
    $first = DB::getQueryLog();
    DB::flushQueryLog();
    $query->execute($admin, '', selection: ['mode' => 'range', 'from' => '2026-01-01', 'until' => '2026-10-31']);
    $second = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($second))->toBe(count($first));
    expect(collect($second)->contains(fn ($query) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query'])))->toBeFalse();
    expect(LoanMovement::count())->toBe(1);
    Http::assertNothingSent();
});
