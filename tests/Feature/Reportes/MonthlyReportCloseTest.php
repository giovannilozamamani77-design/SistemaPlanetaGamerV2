<?php

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Actions\ConfirmMonthlyReportCloseAction;
use App\Domain\Reportes\Actions\ExportReportSummaryAction;
use App\Domain\Reportes\Models\MonthlyReportClose;
use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Reportes\Queries\MonthlyReportCloseQuery;
use App\Domain\Reportes\Queries\MonthlyReportSnapshotQuery;
use App\Filament\Pages\Reportes\MonthlyReport;
use App\Infrastructure\Reportes\ReportSummaryCsv;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function c359Admin(): User
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

function c359Input(User $actor, string $month = '2026-10', bool $accept = false): array
{
    $preview = app(MonthlyReportSnapshotQuery::class)->execute($actor, $month);

    return ['month' => $month, 'request_uuid' => (string) Str::uuid(), 'preview_hash' => $preview['hash'], 'accept_pending' => $accept];
}

function c359Movement(User $actor, string $at = '2026-10-15 16:00:00', int $cents = 12345): LoanMovement
{
    return LoanMovement::create(['user_id' => $actor->id, 'kind' => 'OBJECT_SALE', 'delta_cents' => $cents, 'before_cents' => 0, 'after_cents' => $cents, 'created_at' => $at]);
}

function c359Incident(User $actor, string $at = '2026-10-15 16:00:00'): EconomicIncident
{
    return EconomicIncident::create(['user_id' => $actor->id, 'reported_by' => $actor->id, 'source_module' => 'PRUEBA', 'source_reference' => (string) Str::uuid(), 'component' => 'cash', 'status' => 'CONFIRMADA', 'amount_cents' => 500, 'pending_cents' => 500, 'reason' => 'Solo datos ficticios', 'occurred_at' => $at]);
}

it('C359 confirms an ended month with its original evidence comparison and PIN audit without moving money', function () {
    $actor = c359Admin();
    c359Movement($actor, '2026-09-15 16:00:00', 4000);
    c359Movement($actor);
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, c359Input($actor), '1234');
    expect($close->snapshot['report'])->toMatchArray(['sales_cents' => 12345, 'collections_cents' => 12345, 'balances' => [], 'balance_scope' => 'excluded_monthly']);
    expect($close->snapshot['report']['comparison']['sales'])->toMatchArray(['previous_cents' => 4000, 'difference_cents' => 8345]);
    expect($close->source_count)->toBe(1);
    expect($close->pending_count)->toBe(0);
    expect($close->snapshot['records'][0])->toMatchArray(['source' => 'OBJECT_SALE', 'sales_cents' => 12345, 'actor_name' => 'Administración ficticia']);
    expect(DB::table('user_audits')->where('id', $close->pin_audit_id)->value('action'))->toBe('pin_verified');
    expect(json_encode($close->snapshot))->not->toContain('pin_hash', '1234');
    expect(LoanMovement::count())->toBe(2);
    expect(DB::table('report_exports')->count())->toBe(0);
    Http::assertNothingSent();
});

it('C359 rejects unfinished future malformed and too old months', function (string $month) {
    $actor = c359Admin();
    expect(fn () => app(MonthlyReportSnapshotQuery::class)->execute($actor, $month))->toThrow(ValidationException::class);
    expect(MonthlyReportClose::count())->toBe(0);
})->with(['2026-11', '2026-12', '2026-13', '2026-1', '1899-12', 'not-a-month']);

it('C359 uses the Bolivia midnight boundary and leap-year calendar correctly', function () {
    $actor = c359Admin();
    test()->travelTo(CarbonImmutable::parse('2026-11-01 03:59:59', 'UTC'));
    expect(fn () => c359Input($actor))->toThrow(ValidationException::class);
    test()->travelTo(CarbonImmutable::parse('2026-11-01 04:00:00', 'UTC'));
    c359Movement($actor, '2026-10-01 03:59:59', 100);
    c359Movement($actor, '2026-10-01 04:00:00', 200);
    c359Movement($actor, '2026-11-01 03:59:59', 300);
    c359Movement($actor, '2026-11-01 04:00:00', 400);
    $capture = app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-10');
    expect($capture['snapshot']['report']['sales_cents'])->toBe(500);
    expect($capture['snapshot']['report']['comparison']['sales']['previous_cents'])->toBe(100);
    expect(app(MonthlyReportSnapshotQuery::class)->execute($actor, '2024-02')['snapshot']['report']['period']['end'])->toBe('2024-02-29');
});

it('C359 requires the active administrator own correct PIN and throttles failures', function () {
    $actor = c359Admin();
    $input = c359Input($actor);
    for ($i = 0; $i < 6; $i++) {
        expect(fn () => app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '9999'))->toThrow(ValidationException::class);
    }
    expect(fn () => app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234'))->toThrow(ValidationException::class);
    expect(MonthlyReportClose::count())->toBe(0);
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe(0);
});

it('C359 warns about outstanding incidents including prior months and requires explicit acceptance', function () {
    $actor = c359Admin();
    c359Incident($actor, '2026-09-01 16:00:00');
    c359Incident($actor);
    c359Incident($actor, '2026-11-01 16:00:00');
    $resolved = c359Incident($actor);
    $resolved->update(['pending_cents' => 0]);
    $input = c359Input($actor);
    expect(fn () => app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234'))->toThrow(ValidationException::class);
    $input['accept_pending'] = true;
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234');
    expect($close->pending_count)->toBe(2);
    expect($close->accepted_pending)->toBeTrue();
    expect($close->snapshot['report']['collections_cents'])->toBe(0);
    expect(EconomicIncident::where('pending_cents', 500)->count())->toBe(3);
});

it('C359 rejects stale previews when a contribution or pending record changes', function (string $kind) {
    $actor = c359Admin();
    $movement = c359Movement($actor);
    $incident = c359Incident($actor);
    $input = c359Input($actor, accept: true);
    if ($kind === 'contribution') {
        c359Movement($actor, '2026-10-16 16:00:00', 100);
    } else {
        $incident->update(['pending_cents' => 100]);
    }
    expect(fn () => app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234'))->toThrow(ValidationException::class);
    expect(MonthlyReportClose::count())->toBe(0);
})->with(['contribution', 'pending']);

it('C359 returns one original for retries double clicks and another administrator closing the same month', function () {
    $actor = c359Admin();
    $input = c359Input($actor);
    $action = app(ConfirmMonthlyReportCloseAction::class);
    $first = $action->execute($actor, $input, '1234');
    $again = $action->execute($actor, $input, '1234');
    $other = User::factory()->create(['pin_hash' => Hash::make('4321')]);
    $other->assignRole('administrador');
    $third = $action->execute($other, c359Input($other), '4321');
    expect([$again->id, $third->id])->toBe([$first->id, $first->id]);
    expect(MonthlyReportClose::count())->toBe(1);
    expect($first->fresh()->user_id)->toBe($actor->id);
    $input['month'] = '2026-09';
    expect(fn () => $action->execute($actor, $input, '1234'))->toThrow(ValidationException::class);
});

it('C359 protects month uniqueness and original immutability in the database as well as the model', function () {
    $actor = c359Admin();
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, c359Input($actor), '1234');
    expect(fn () => $close->update(['actor_name' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => $close->delete())->toThrow(LogicException::class);
    expect(fn () => DB::table('report_monthly_closes')->where('id', $close->id)->update(['month' => '2026-09']))->toThrow(QueryException::class);
    expect(fn () => DB::table('report_monthly_closes')->where('id', $close->id)->delete())->toThrow(QueryException::class);
    $attributes = $close->getAttributes();
    unset($attributes['id']);
    $attributes['request_uuid'] = (string) Str::uuid();
    expect(fn () => DB::table('report_monthly_closes')->insert($attributes))->toThrow(QueryException::class);
    expect(MonthlyReportClose::count())->toBe(1);
});

it('C359 rolls back an interrupted confirmation and allows a clean retry', function () {
    $actor = c359Admin();
    $input = c359Input($actor);
    DB::unprepared("CREATE TRIGGER c359_stop BEFORE INSERT ON report_monthly_closes BEGIN SELECT RAISE(ABORT, 'interruption'); END");
    expect(fn () => app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234'))->toThrow(QueryException::class);
    expect(MonthlyReportClose::count())->toBe(0);
    DB::unprepared('DROP TRIGGER c359_stop');
    app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234');
    expect(MonthlyReportClose::count())->toBe(1);
});

it('C359 keeps frozen evidence comparison and pending states after later source corrections', function () {
    $actor = c359Admin();
    $movement = c359Movement($actor);
    $incident = c359Incident($actor);
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, c359Input($actor, accept: true), '1234');
    c359Movement($actor, '2026-10-16 16:00:00', 1);
    $incident->update(['pending_cents' => 0]);
    User::whereKey($actor->id)->update(['name' => 'Nombre posterior']);
    $saved = app(MonthlyReportCloseQuery::class)->detail($actor, $close->id);
    expect($saved['report']['sales_cents'])->toBe(12345);
    expect($saved['evidence']->items()[0]['actor_name'])->toBe('Administración ficticia');
    expect($saved['close']->snapshot['pending'][0]['amount_cents'])->toBe(500);
});

it('C359 denies cashier manager and inactive administrators including direct page requests', function (string $role, bool $active) {
    $actor = c359Admin();
    $input = c359Input($actor);
    $other = User::factory()->create(['is_active' => $active, 'pin_hash' => Hash::make('1234')]);
    $other->assignRole($role);
    expect(fn () => app(ConfirmMonthlyReportCloseAction::class)->execute($other, $input, '1234'))->toThrow(AuthorizationException::class);
    $this->actingAs($other)->get(MonthlyReport::getUrl())->assertForbidden();
    expect(MonthlyReportClose::count())->toBe(0);
})->with([['cajera', true], ['encargado', true], ['administrador', false]]);

it('C359 checks authorization again after capture before saving', function () {
    $actor = c359Admin();
    $input = c359Input($actor);
    $this->mock(MonthlyReportSnapshotQuery::class, function ($mock) use ($actor): void {
        $mock->shouldReceive('month')->andReturn('2026-10-01');
        $mock->shouldReceive('execute')->andReturnUsing(function () use ($actor) {
            $result = (new MonthlyReportSnapshotQuery)->execute($actor, '2026-10');
            User::findOrFail($actor->id)->removeRole('administrador');

            return $result;
        });
    });
    expect(fn () => app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234'))->toThrow(AuthorizationException::class);
    expect(MonthlyReportClose::count())->toBe(0);
});

it('C359 exposes a usable preview confirmation and paginated history through Livewire without a browser', function () {
    $actor = c359Admin();
    c359Movement($actor);
    c359Incident($actor);
    $page = Livewire::test(MonthlyReport::class)->assertSuccessful()->set('month', '2026-10')->call('prepare')->assertSee('pendientes')->set('pin', '9999')->call('confirm')->assertHasErrors('pin')->assertSet('pin', '');
    $page->set('pin', '1234')->call('confirm')->assertHasErrors('acceptPending');
    $page->set('acceptPending', true)->set('pin', '1234')->call('confirm')->assertSet('pin', '')->assertSee('Cierre original confirmado');
    $page->call('changeEvidence', 'pending')->assertSee('CONFIRMADA');
    expect(MonthlyReportClose::count())->toBe(1);
    for ($i = 1; $i < 7; $i++) {
        $month = CarbonImmutable::parse('2026-10-01')->subMonths($i)->format('Y-m');
        app(ConfirmMonthlyReportCloseAction::class)->execute($actor, c359Input($actor, $month, true), '1234');
    }
    $history = app(MonthlyReportCloseQuery::class)->history($actor);
    expect($history->total())->toBe(7);
    expect($history->items())->toHaveCount(5);
    expect(app(MonthlyReportCloseQuery::class)->history($actor, 2)->items())->toHaveCount(2);
    expect(fn () => app(MonthlyReportCloseQuery::class)->detail($actor, 1, 'anything'))->toThrow(ValidationException::class);
});

function c359Box(User $actor): array
{
    $box = CashBox::factory()->create(['created_by' => $actor->id]);
    $config = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $actor->id, 'base_capital_cents' => 10000, 'accounts' => ['cash' => ['name' => 'Efectivo', 'initial_cents' => 10000]], 'shifts' => [], 'reason' => 'Solo prueba', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    $session = CashBoxSession::create(['cash_box_id' => $box->id, 'user_id' => $actor->id, 'configuration_id' => $config->id, 'status' => 'ABIERTA', 'active_slot' => 1, 'base_capital_cents' => 10000, 'opening_balances' => ['cash' => 10000], 'expected_balances' => ['cash' => 10000], 'current_balances' => ['cash' => 10150], 'comparison' => [], 'opened_at' => '2026-10-15 16:00:00', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]);
    $operation = CashBoxOperation::create(['cash_box_session_id' => $session->id, 'user_id' => $actor->id, 'amount_cents' => 10000, 'fee_type' => 'ACH', 'fee_cents' => 150, 'suggested_fee_cents' => 150, 'fee_component' => 'cash', 'deltas' => ['cash' => 150], 'status' => 'VIGENTE', 'created_at' => '2026-10-15 16:00:00']);
    $close = CashBoxClose::create(['cash_box_session_id' => $session->id, 'cash_box_id' => $box->id, 'user_id' => $actor->id, 'period' => 'MANANA', 'shift_snapshot' => [], 'counted_balances' => ['cash' => 10150], 'real_balances' => ['cash' => 10150], 'expected_balances' => ['cash' => 10150], 'comparison' => [], 'difference_cents' => 0, 'commission_cents' => 150, 'capital_cents' => 10000, 'requires_liquidation' => true, 'liquidation_completed' => false, 'settlement_source' => 'cash', 'created_at' => '2026-10-15 16:00:00']);
    $correction = CashBoxCorrection::create(['cash_box_operation_id' => $operation->id, 'cash_box_session_id' => $session->id, 'reason' => 'Prueba', 'status' => 'PENDIENTE', 'created_at' => now()]);

    return compact('session', 'operation', 'close', 'correction');
}

it('C359 captures liquidation and correction warnings and never releases or resolves their source records', function () {
    $actor = c359Admin();
    $box = c359Box($actor);
    c359Incident($actor);
    $capture = app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-10');
    expect(array_column($capture['snapshot']['pending'], 'source'))->toBe(['Faltante / incidencia', 'Liquidación pendiente', 'Corrección en revisión']);
    $input = c359Input($actor, accept: true);
    $saved = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234');
    expect($saved->pending_count)->toBe(3);
    expect($saved->snapshot['report']['collections_cents'])->toBe(150);
    expect($box['session']->fresh()->current_balances)->toBe(['cash' => 10150]);
    expect($box['close']->fresh()->liquidation_completed)->toBeFalse();
    expect($box['correction']->fresh()->status)->toBe('PENDIENTE');
    $box['operation']->update(['status' => 'INVALIDADA']);
    $box['close']->update(['liquidation_completed' => true]);
    $box['correction']->update(['status' => 'APROBADA']);
    expect(app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-10')['snapshot']['report']['collections_cents'])->toBe(0);
    expect(app(MonthlyReportCloseQuery::class)->detail($actor, $saved->id)['report']['collections_cents'])->toBe(150);
});

it('C359 exports the saved original and audits its link without recalculating or closing again', function (string $format) {
    $actor = c359Admin();
    c359Movement($actor);
    c359Incident($actor);
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, c359Input($actor, accept: true), '1234');
    c359Movement($actor, '2026-10-20 16:00:00', 555);
    $result = $this->get(route('reportes.cierre-export', ['close' => $close->id, 'format' => $format]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $bytes = $result->getContent();
    $audit = ReportExport::sole();
    expect($audit->monthly_close_id)->toBe($close->id);
    expect($audit->format)->toBe(strtoupper($format));
    expect($audit->document_sha256)->toBe(hash('sha256', $bytes));
    if ($format === 'csv') {
        $text = substr($bytes, 3);
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", trim($text)));
        expect(collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Ventas registradas')[1])->toBe('123.45');
        expect($bytes)->not->toContain('Dinero disponible al generar', 'No constituye cierre mensual');
    } elseif ($format === 'xlsx') {
        expect($bytes)->toStartWith('PK');
    } else {
        expect($bytes)->toStartWith('%PDF-');
    }
    if ($qa = getenv('C359_QA_DIR')) {
        file_put_contents($qa.'/original.'.$format, $bytes);
    }
    expect(MonthlyReportClose::count())->toBe(1);
    expect($close->fresh()->snapshot_hash)->toBe($close->snapshot_hash);
})->with(['pdf', 'xlsx', 'csv']);

it('C359 denies frozen exports to guests cashiers and managers', function () {
    $actor = c359Admin();
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, c359Input($actor), '1234');
    $url = route('reportes.cierre-export', ['close' => $close->id, 'format' => 'csv']);
    auth()->logout();
    $this->get($url)->assertRedirect();
    foreach (['cajera', 'encargado'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user)->get($url)->assertForbidden();
    }
    expect(DB::table('report_exports')->count())->toBe(0);
});

it('C359 preserves the close after a failed export and allows downloading later without a second confirmation', function () {
    $actor = c359Admin();
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, c359Input($actor), '1234');
    $this->mock(ReportSummaryCsv::class, function ($mock): void {
        $mock->shouldReceive('render')->once()->andThrow(new RuntimeException('Interrupción simulada'));
    });
    expect(fn () => app(ExportReportSummaryAction::class)->frozen($actor, $close->id, 'CSV'))->toThrow(RuntimeException::class);
    expect(MonthlyReportClose::count())->toBe(1);
    expect(DB::table('report_exports')->count())->toBe(0);
    $this->forgetMock(ReportSummaryCsv::class);
    $this->get(route('reportes.cierre-export', ['close' => $close->id, 'format' => 'csv']))->assertOk();
    expect(DB::table('report_exports')->count())->toBe(1);
    expect(MonthlyReportClose::count())->toBe(1);
});

it('C359 does not serialize the PIN and does not invalidate a preview for current money changes', function () {
    $actor = c359Admin();
    $input = c359Input($actor);
    DB::table('loan_funds')->updateOrInsert(['code' => 'YAPE'], ['balance_cents' => 88888, 'revision' => 1, 'initialized_at' => now(), 'initialized_by' => $actor->id]);
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234');
    expect($close->snapshot['report']['balances'])->toBe([]);
    expect(DB::table('loan_funds')->where('code', 'YAPE')->value('balance_cents'))->toBe(88888);
    expect(array_keys($close->getAttributes()))->not->toContain('pin');
});

it('C359 shows the saved original immediately when reviewing an already closed month', function () {
    $actor = c359Admin();
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, c359Input($actor), '1234');
    c359Movement($actor);
    Livewire::test(MonthlyReport::class)->set('month', '2026-10')->call('prepare')
        ->assertSet('selectedCloseId', $close->id)->assertSet('preview', [])->assertSee('Cierre original confirmado');
});

it('C359 concurrent administrators receive the same close from independent processes and SQLite connections', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $folder = storage_path('framework/testing');
    if (! is_dir($folder)) {
        mkdir($folder, 0700, true);
    }
    $file = $folder.DIRECTORY_SEPARATOR.'c359-'.Str::uuid().'.sqlite';
    touch($file);
    $fixture = base_path('tests/Fixtures/Reportes/monthly-close-concurrency.php');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
    $workers = [];
    try {
        $setup = new Process([PHP_BINARY, $fixture, 'setup', $file], base_path(), $environment, timeout: 30);
        $setup->run();
        expect($setup->isSuccessful())->toBeTrue($setup->getErrorOutput());
        foreach ([1, 2] as $actor) {
            $worker = new Process([PHP_BINARY, $fixture, 'confirm', $file, (string) $actor], base_path(), $environment, timeout: 30);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 15;
        while (! (is_file($file.'.ready.1') && is_file($file.'.ready.2'))) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Concurrent test not ready.');
            }
            usleep(10000);
        }
        touch($file.'.go');
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }
        expect(json_validate($workers[0]->getOutput()))->toBeTrue($workers[0]->getOutput());
        expect(json_validate($workers[1]->getOutput()))->toBeTrue($workers[1]->getOutput());
        $first = json_decode($workers[0]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode($workers[1]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($first)->toBe($second)->toMatchArray(['month' => '2020-01', 'sales_cents' => 12345, 'count' => 1]);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        foreach (['.go', '.ready.1', '.ready.2', '-wal', '-shm', '-journal', ''] as $suffix) {
            if (is_file($file.$suffix)) {
                unlink($file.$suffix);
            }
        }
    }
});

it('C359 captures a full month without querying once per contribution', function () {
    $actor = c359Admin();
    c359Movement($actor);
    app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-10');
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-10');
    $first = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($i = 0; $i < 30; $i++) {
        c359Movement($actor);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $capture = app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-10');
    $second = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect(count($capture['snapshot']['records']))->toBe(31);
    expect($second)->toBe($first)->toBeLessThan(45);
});

it('C359 prevents changing the locked preview hash from the browser request', function () {
    c359Admin();
    expect(fn () => Livewire::test(MonthlyReport::class)->call('prepare')->set('previewHash', str_repeat('a', 64)))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
