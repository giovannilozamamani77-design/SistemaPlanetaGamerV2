<?php

use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Queries\DailyReportQuery;
use App\Filament\Pages\Reportes\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c353Admin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-30 16:00:00', 'UTC'));
    test()->seed(UsuariosPermissionsSeeder::class);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $user = User::factory()->create();
    $user->assignRole('administrador');

    return $user;
}

function c353Receipt(User $user, int $cents, string $date, string $kind = 'OBJECT_SALE'): void
{
    LoanMovement::create(['user_id' => $user->id, 'kind' => $kind, 'delta_cents' => $cents, 'before_cents' => 0, 'after_cents' => $cents, 'created_at' => $date]);
}

it('C353 compares exact cents without misleading percentages when the previous day is zero', function (int $current, int $previous, int $difference, string $direction, string $label) {
    $admin = c353Admin();
    c353Receipt($admin, $current, '2026-10-30 16:00:00');
    c353Receipt($admin, $previous, '2026-10-29 16:00:00');
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    expect($report['comparison']['previous_date'])->toBe('2026-10-29');
    expect($report['comparison']['available'])->toBeTrue();
    foreach (['sales', 'collections'] as $metric) {
        expect($report['comparison'][$metric])->toBe(['current_cents' => $current, 'previous_cents' => $previous, 'difference_cents' => $difference, 'direction' => $direction, 'label' => $label]);
    }
})->with([
    'both empty' => [0, 0, 0, 'equal', 'Sin cambios'],
    'equal nonzero' => [1000, 1000, 0, 'equal', 'Sin cambios'],
    'increase' => [2000, 1000, 1000, 'up', 'Subió Bs 10'],
    'decrease' => [1000, 2000, -1000, 'down', 'Bajó Bs 10'],
    'new activity' => [1000, 0, 1000, 'up', 'Subió Bs 10'],
    'no activity today' => [0, 1000, -1000, 'down', 'Bajó Bs 10'],
    'one cent more' => [101, 100, 1, 'up', 'Subió Bs 0.01'],
    'one cent less' => [100, 101, -1, 'down', 'Bajó Bs 0.01'],
]);

it('C353 compares sales and collections independently and keeps detail on the selected day', function () {
    $admin = c353Admin();
    c353Receipt($admin, 10000, '2026-10-29 16:00:00');
    c353Receipt($admin, 10000, '2026-10-29 16:00:00', 'TERM_PAYMENT');
    c353Receipt($admin, 15000, '2026-10-30 16:00:00');
    c353Receipt($admin, 1000, '2026-10-30 16:00:00', 'TERM_PAYMENT');
    c353Receipt($admin, 99999, '2026-10-29 16:00:00', 'IN');
    c353Receipt($admin, 99999, '2026-10-30 16:00:00', 'ANNULMENT');
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30', ['metric' => 'collections', 'source' => 'TERM_PAYMENT', 'page' => 1, 'per_page' => 5]);
    expect($report['sales_cents'])->toBe(15000);
    expect($report['collections_cents'])->toBe(16000);
    expect($report['comparison']['sales'])->toMatchArray(['previous_cents' => 10000, 'difference_cents' => 5000, 'direction' => 'up']);
    expect($report['comparison']['collections'])->toMatchArray(['previous_cents' => 20000, 'difference_cents' => -4000, 'direction' => 'down']);
    expect($report['detail']['total_cents'])->toBe(1000);
    expect($report['detail']['records']->total())->toBe(1);
});

it('C353 compares adjacent complete Bolivia date windows without leaking neighboring dates', function () {
    $admin = c353Admin();
    foreach (['2026-10-29 03:59:59' => 3200, '2026-10-29 04:00:00' => 100, '2026-10-30 03:59:59' => 200, '2026-10-30 04:00:00' => 400, '2026-10-31 03:59:59' => 800, '2026-10-31 04:00:00' => 1600] as $date => $amount) {
        c353Receipt($admin, $amount, $date);
    }
    $report = app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    expect($report['comparison']['sales'])->toMatchArray(['current_cents' => 1200, 'previous_cents' => 300, 'difference_cents' => 900]);
});

it('C353 uses the previous calendar day across month year and leap boundaries', function (string $date, string $previous) {
    $admin = c353Admin();
    c353Receipt($admin, 300, $previous.' 16:00:00');
    c353Receipt($admin, 500, $date.' 16:00:00');
    $report = app(DailyReportQuery::class)->execute($admin, $date);
    expect($report['comparison']['previous_date'])->toBe($previous);
    expect($report['comparison']['sales'])->toMatchArray(['current_cents' => 500, 'previous_cents' => 300, 'difference_cents' => 200]);
    expect($report['is_today'])->toBeFalse();
})->with([['2026-01-01', '2025-12-31'], ['2026-10-01', '2026-09-30'], ['2024-03-01', '2024-02-29']]);

it('C353 makes the first supported day unavailable for comparison rather than inventing a zero baseline', function () {
    $admin = c353Admin();
    $report = app(DailyReportQuery::class)->execute($admin, '1900-01-01');
    expect($report['comparison'])->toBe(['previous_date' => '1899-12-31', 'available' => false, 'sales' => null, 'collections' => null]);
    test()->actingAs($admin);
    Livewire::test(DailyReport::class)->set('date', '1900-01-01')->call('applyDate')->assertSee('Sin comparación: el día anterior está fuera del rango admitido.');
});

it('C353 renders clear changes and today warning and refreshes only when the chosen date is applied', function () {
    $admin = c353Admin();
    c353Receipt($admin, 10000, '2026-10-29 16:00:00');
    c353Receipt($admin, 15000, '2026-10-30 16:00:00');
    c353Receipt($admin, 10000, '2026-10-29 16:00:00', 'TERM_PAYMENT');
    c353Receipt($admin, 1000, '2026-10-30 16:00:00', 'TERM_PAYMENT');
    test()->actingAs($admin);
    Livewire::test(DailyReport::class)->assertSee('Subió Bs 50')->assertSee('Bajó Bs 40')->assertSee('Día anterior · 29/10/2026')->assertSee('Hoy está en curso; se compara con el día anterior completo.')
        ->set('date', '2026-10-29')->assertSee('Subió Bs 50')->assertSet('appliedDate', '2026-10-30')
        ->call('applyDate')->assertSee('Día anterior · 28/10/2026')->assertSee('Subió Bs 100')->assertDontSee('Hoy está en curso; se compara con el día anterior completo.');
});

it('C353 keeps comparison exclusive to active Administration', function (string $role, bool $active) {
    c353Admin();
    $actor = User::factory()->create(['is_active' => $active]);
    $actor->assignRole($role);
    test()->actingAs($actor);
    expect(fn () => app(DailyReportQuery::class)->execute($actor, '2026-10-30'))->toThrow(AuthorizationException::class);
    Livewire::test(DailyReport::class)->assertForbidden();
})->with([['cajera', true], ['encargado', true], ['administrador', false]]);

it('C353 comparison reads do not alter balances or emit external requests', function () {
    $admin = c353Admin();
    c353Receipt($admin, 1000, '2026-10-29 16:00:00');
    c353Receipt($admin, 1500, '2026-10-30 16:00:00');
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(DailyReportQuery::class)->execute($admin, '2026-10-30');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->contains(fn ($query) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query'])))->toBeFalse();
    expect(LoanMovement::count())->toBe(2);
    Http::assertNothingSent();
});
