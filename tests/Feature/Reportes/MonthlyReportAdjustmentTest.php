<?php

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Actions\ConfirmMonthlyReportAdjustmentAction;
use App\Domain\Reportes\Actions\ConfirmMonthlyReportCloseAction;
use App\Domain\Reportes\Actions\ExportReportSummaryAction;
use App\Domain\Reportes\Models\MonthlyReportAdjustment;
use App\Domain\Reportes\Models\MonthlyReportClose;
use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Reportes\Queries\MonthlyReportAdjustmentQuery;
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
use Livewire\Livewire;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function c360Admin(): User
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

function c360Movement(User $actor, int $cents = 12345, string $date = '2026-10-15 16:00:00'): LoanMovement
{
    return LoanMovement::create(['user_id' => $actor->id, 'kind' => 'OBJECT_SALE', 'delta_cents' => $cents, 'before_cents' => 0, 'after_cents' => $cents, 'created_at' => $date]);
}

function c360Close(User $actor): MonthlyReportClose
{
    $capture = app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-10');

    return app(ConfirmMonthlyReportCloseAction::class)->execute($actor, ['month' => '2026-10', 'request_uuid' => (string) Str::uuid(), 'preview_hash' => $capture['hash'], 'accept_pending' => true], '1234');
}

function c360Input(User $actor, int $closeId): array
{
    $quote = app(MonthlyReportAdjustmentQuery::class)->preview($actor, $closeId);

    return ['close_id' => $closeId, 'base_sequence' => $quote['base_sequence'], 'request_uuid' => (string) Str::uuid(), 'preview_hash' => $quote['preview_hash'], 'reason' => 'Cambio de origen verificado', 'accept_pending' => true];
}

function c360Operation(User $actor): CashBoxOperation
{
    $box = CashBox::factory()->create(['created_by' => $actor->id]);
    $config = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $actor->id, 'base_capital_cents' => 10000, 'accounts' => ['cash' => ['name' => 'Efectivo', 'initial_cents' => 10000]], 'shifts' => [], 'reason' => 'Solo prueba', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    $session = CashBoxSession::create(['cash_box_id' => $box->id, 'user_id' => $actor->id, 'configuration_id' => $config->id, 'status' => 'ABIERTA', 'active_slot' => 1, 'base_capital_cents' => 10000, 'opening_balances' => ['cash' => 10000], 'expected_balances' => ['cash' => 10000], 'current_balances' => ['cash' => 10150], 'comparison' => [], 'opened_at' => '2026-10-15 16:00:00', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]);

    return CashBoxOperation::create(['cash_box_session_id' => $session->id, 'user_id' => $actor->id, 'amount_cents' => 10000, 'fee_type' => 'ACH', 'fee_cents' => 150, 'suggested_fee_cents' => 150, 'fee_component' => 'cash', 'deltas' => ['cash' => 150], 'status' => 'VIGENTE', 'created_at' => '2026-10-15 16:00:00']);
}

it('C360 records source-backed differences and preserves the original with a linked immutable version', function () {
    $actor = c360Admin();
    c360Movement($actor);
    $close = c360Close($actor);
    c360Movement($actor, 500);
    $quote = app(MonthlyReportAdjustmentQuery::class)->preview($actor, $close->id);
    expect($quote['sales_delta_cents'])->toBe(500);
    $row = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    expect($row->sequence)->toBe(1);
    expect($row->sales_delta_cents)->toBe(500);
    expect($row->snapshot['report']['sales_cents'])->toBe(12845);
    expect($row->changes[0])->toMatchArray(['section' => 'records', 'kind' => 'ADDED', 'sales_delta_cents' => 500]);
    expect($close->fresh()->snapshot['report']['sales_cents'])->toBe(12345);
    expect($row->before_hash)->toBe($close->snapshot_hash);
    expect(DB::table('user_audits')->where('id', $row->pin_audit_id)->value('action'))->toBe('pin_verified');
    expect(LoanMovement::count())->toBe(2);
    Http::assertNothingSent();
});

it('C360 computes negative corrections and permits returning to a previous source state through new versions', function () {
    $actor = c360Admin();
    $operation = c360Operation($actor);
    $close = c360Close($actor);
    foreach (['INVALIDADA', 'VIGENTE', 'INVALIDADA'] as $index => $status) {
        $operation->update(['status' => $status]);
        $row = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
        expect($row->sequence)->toBe($index + 1);
        expect($row->collections_delta_cents)->toBe($status === 'INVALIDADA' ? -150 : 150);
    }
    expect(MonthlyReportAdjustment::count())->toBe(3);
    expect($close->fresh()->snapshot['report']['collections_cents'])->toBe(150);
    $state = app(MonthlyReportAdjustmentQuery::class)->state($actor, $close->id);
    expect($state['collections_adjustment_cents'])->toBe(-150);
    expect($state['snapshot']['report']['collections_cents'])->toBe(0);
});

it('C360 rejects empty adjustments and ignores only renamed actors or changes to current balances', function () {
    $actor = c360Admin();
    c360Movement($actor);
    $close = c360Close($actor);
    User::whereKey($actor->id)->update(['name' => 'Nombre posterior']);
    DB::table('loan_funds')->updateOrInsert(['code' => 'YAPE'], ['balance_cents' => 5000, 'revision' => 1]);
    $input = c360Input($actor, $close->id);
    expect(app(MonthlyReportAdjustmentQuery::class)->preview($actor, $close->id)['changes'])->toBe([]);
    expect(fn () => app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '1234'))->toThrow(ValidationException::class);
    expect(MonthlyReportAdjustment::count())->toBe(0);
});

it('C360 keeps the original comparison baseline rather than silently changing the previous month', function () {
    $actor = c360Admin();
    c360Movement($actor, 4000, '2026-09-15 16:00:00');
    c360Movement($actor);
    $close = c360Close($actor);
    c360Movement($actor, 1000, '2026-09-20 16:00:00');
    expect(app(MonthlyReportAdjustmentQuery::class)->preview($actor, $close->id)['changes'])->toBe([]);
    c360Movement($actor, 200);
    $row = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    expect($row->snapshot['report']['comparison']['sales'])->toMatchArray(['previous_cents' => 4000, 'current_cents' => 12545, 'difference_cents' => 8545]);
});

it('C360 requires a reason and correct PIN without accepting manually supplied totals', function () {
    $actor = c360Admin();
    $close = c360Close($actor);
    c360Movement($actor, 500);
    $input = c360Input($actor, $close->id);
    $input['reason'] = ' ';
    expect(fn () => app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '1234'))->toThrow(ValidationException::class);
    $input['reason'] = 'Motivo confirmado';
    expect(fn () => app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '9999'))->toThrow(ValidationException::class);
    $input['sales_delta_cents'] = 999999;
    $row = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '1234');
    expect($row->sales_delta_cents)->toBe(500);
});

it('C360 requires accepting outstanding pending states and can record their resolution without financial loss', function () {
    $actor = c360Admin();
    $incident = EconomicIncident::create(['user_id' => $actor->id, 'reported_by' => $actor->id, 'source_module' => 'PRUEBA', 'source_reference' => 'c360', 'component' => 'cash', 'status' => 'CONFIRMADA', 'amount_cents' => 500, 'pending_cents' => 500, 'reason' => 'Prueba', 'occurred_at' => '2026-10-15 16:00:00']);
    $close = c360Close($actor);
    $incident->update(['pending_cents' => 200]);
    $input = c360Input($actor, $close->id);
    $input['accept_pending'] = false;
    expect(fn () => app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '1234'))->toThrow(ValidationException::class);
    $input['accept_pending'] = true;
    $first = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '1234');
    expect($first->sales_delta_cents)->toBe(0);
    expect($first->pending_count)->toBe(1);
    $incident->update(['pending_cents' => 0]);
    $second = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    expect($second->collections_delta_cents)->toBe(0);
    expect($second->pending_count)->toBe(0);
    expect($close->fresh()->snapshot['pending'][0]['amount_cents'])->toBe(500);
});

it('C360 returns the same version for identical requests or another administrator confirming the same preview', function () {
    $actor = c360Admin();
    $close = c360Close($actor);
    c360Movement($actor, 500);
    $input = c360Input($actor, $close->id);
    $action = app(ConfirmMonthlyReportAdjustmentAction::class);
    $first = $action->execute($actor, $input, '1234');
    expect($action->execute($actor, $input, '1234')->id)->toBe($first->id);
    $other = User::factory()->create(['pin_hash' => Hash::make('4321')]);
    $other->assignRole('administrador');
    $secondInput = $input;
    $secondInput['request_uuid'] = (string) Str::uuid();
    expect($action->execute($other, $secondInput, '4321')->id)->toBe($first->id);
    $input['reason'] = 'Otro motivo';
    expect(fn () => $action->execute($actor, $input, '1234'))->toThrow(ValidationException::class);
    expect(MonthlyReportAdjustment::count())->toBe(1);
});

it('C360 refuses a stale preview after changed source data or a different confirmed version', function (bool $confirmOther) {
    $actor = c360Admin();
    $close = c360Close($actor);
    c360Movement($actor, 100);
    $input = c360Input($actor, $close->id);
    c360Movement($actor, 200);
    if ($confirmOther) {
        app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    }
    expect(fn () => app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '1234'))->toThrow(ValidationException::class);
    expect(MonthlyReportAdjustment::count())->toBe($confirmOther ? 1 : 0);
})->with([false, true]);

it('C360 rolls back an interrupted adjustment and safely retries the same request', function () {
    $actor = c360Admin();
    $close = c360Close($actor);
    c360Movement($actor, 500);
    $input = c360Input($actor, $close->id);
    DB::unprepared("CREATE TRIGGER c360_stop BEFORE INSERT ON report_monthly_adjustments BEGIN SELECT RAISE(ABORT, 'interruption'); END");
    expect(fn () => app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '1234'))->toThrow(QueryException::class);
    expect(MonthlyReportAdjustment::count())->toBe(0);
    DB::unprepared('DROP TRIGGER c360_stop');
    app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '1234');
    expect(MonthlyReportAdjustment::count())->toBe(1);
    expect($close->fresh()->source_count)->toBe(0);
});

it('C360 protects confirmed adjustments and version uniqueness in the model and database', function () {
    $actor = c360Admin();
    $close = c360Close($actor);
    c360Movement($actor, 500);
    $row = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    expect(fn () => $row->update(['reason' => 'Otro']))->toThrow(LogicException::class);
    expect(fn () => $row->delete())->toThrow(LogicException::class);
    expect(fn () => DB::table('report_monthly_adjustments')->where('id', $row->id)->update(['sales_delta_cents' => 9000]))->toThrow(QueryException::class);
    expect(fn () => DB::table('report_monthly_adjustments')->where('id', $row->id)->delete())->toThrow(QueryException::class);
    $attributes = $row->getAttributes();
    unset($attributes['id']);
    $attributes['request_uuid'] = (string) Str::uuid();
    expect(fn () => DB::table('report_monthly_adjustments')->insert($attributes))->toThrow(QueryException::class);
});

it('C360 denies cashier manager and inactive administrators access to adjustment operations', function (string $role, bool $active) {
    $actor = c360Admin();
    $close = c360Close($actor);
    c360Movement($actor, 500);
    $input = c360Input($actor, $close->id);
    $other = User::factory()->create(['is_active' => $active]);
    $other->assignRole($role);
    expect(fn () => app(MonthlyReportAdjustmentQuery::class)->preview($other, $close->id))->toThrow(AuthorizationException::class);
    expect(fn () => app(ConfirmMonthlyReportAdjustmentAction::class)->execute($other, $input, '1234'))->toThrow(AuthorizationException::class);
    expect(MonthlyReportAdjustment::count())->toBe(0);
})->with([['cajera', true], ['encargado', true], ['administrador', false]]);

it('C360 detects changed contributions even if their net total remains the same', function () {
    $actor = c360Admin();
    $operation = c360Operation($actor);
    $close = c360Close($actor);
    $second = $operation->replicate();
    $second->status = 'INVALIDADA';
    $second->created_at = $operation->created_at;
    $second->save();
    $operation->update(['status' => 'INVALIDADA']);
    $second->update(['status' => 'VIGENTE']);
    $row = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    expect($row->collections_delta_cents)->toBe(0);
    expect($row->changes)->toHaveCount(2);
});

it('C360 provides review PIN confirmation separate totals and paginated history in Livewire', function () {
    $actor = c360Admin();
    c360Movement($actor);
    $close = c360Close($actor);
    c360Movement($actor, 500);
    $page = Livewire::test(MonthlyReport::class)->call('viewClose', $close->id)->call('prepareAdjustment')->assertSee('cambios detectados');
    $page->set('adjustmentReason', 'Registro posterior verificado')->set('pin', '9999')->call('confirmAdjustment')->assertHasErrors('pin')->assertSet('pin', '');
    $page->set('pin', '1234')->call('confirmAdjustment')->assertHasNoErrors()->assertSet('pin', '')->assertSee('Resultado registrado');
    $row = MonthlyReportAdjustment::sole();
    $page->call('viewAdjustment', $row->id)->assertSee('Detalle del ajuste');
    expect($close->fresh()->snapshot['report']['sales_cents'])->toBe(12345);
});

it('C360 concurrent administrators receive the same adjustment from independent processes and SQLite connections', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $folder = storage_path('framework/testing');
    if (! is_dir($folder)) {
        mkdir($folder, 0700, true);
    }
    $file = $folder.DIRECTORY_SEPARATOR.'c360-'.Str::uuid().'.sqlite';
    touch($file);
    $fixture = base_path('tests/Fixtures/Reportes/monthly-adjustment-concurrency.php');
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
        expect($first)->toBe($second)->toMatchArray(['sequence' => 1, 'sales_cents' => 12845, 'count' => 1]);
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

it('C360 freezes each adjusted export version and keeps original exports unchanged', function (string $format) {
    $actor = c360Admin();
    c360Movement($actor);
    $close = c360Close($actor);
    c360Movement($actor, 500);
    $first = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    c360Movement($actor, 100);
    app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    $response = $this->get(route('reportes.cierre-ajuste-export', ['close' => $close->id, 'adjustment' => $first->id, 'format' => $format]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $bytes = $response->getContent();
    $audit = ReportExport::sole();
    expect($audit->monthly_adjustment_id)->toBe($first->id);
    expect($audit->monthly_close_id)->toBe($close->id);
    expect($audit->document_sha256)->toBe(hash('sha256', $bytes));
    expect($response->headers->get('Content-Disposition'))->toContain('v1_');
    if ($format === 'csv') {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, substr($bytes, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        expect(collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Ventas registradas')[1])->toBe('128.45');
        expect(collect($rows)->first(fn ($row) => ($row[0] ?? '') === 'Ventas'))->toBe(['Ventas', '123.45', '5.00', '128.45']);
        expect($bytes)->toContain('Cierre ajustado v1');
    } elseif ($format === 'xlsx') {
        expect($bytes)->toStartWith('PK');
    } else {
        expect($bytes)->toStartWith('%PDF-');
    }
    if ($folder = getenv('C360_QA_DIR')) {
        file_put_contents($folder.'/adjusted-v1.'.$format, $bytes);
    }
    $original = $this->get(route('reportes.cierre-export', ['close' => $close->id, 'format' => 'csv']))->assertOk();
    expect($original->getContent())->not->toContain('Cierre ajustado');
    expect($close->fresh()->snapshot['report']['sales_cents'])->toBe(12345);
    expect(MonthlyReportAdjustment::count())->toBe(2);
})->with(['pdf', 'xlsx', 'csv']);

it('C360 paginates changes and version history without losing earlier adjustments', function () {
    $actor = c360Admin();
    $close = c360Close($actor);
    for ($i = 0; $i < 12; $i++) {
        c360Movement($actor, 1);
    }
    $first = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    expect(app(MonthlyReportAdjustmentQuery::class)->detail($actor, $close->id, $first->id)['changes']->total())->toBe(12);
    expect(app(MonthlyReportAdjustmentQuery::class)->detail($actor, $close->id, $first->id, 2)['changes']->items())->toHaveCount(5);
    for ($i = 0; $i < 6; $i++) {
        c360Movement($actor, 1);
        app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    }
    expect(app(MonthlyReportAdjustmentQuery::class)->history($actor, $close->id)->items())->toHaveCount(5);
    expect(app(MonthlyReportAdjustmentQuery::class)->history($actor, $close->id, 2)->items())->toHaveCount(2);
});

it('C360 denies adjusted downloads to guests and cashiers and binds the adjustment to its actual close', function () {
    $actor = c360Admin();
    $close = c360Close($actor);
    c360Movement($actor, 1);
    $row = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    $url = route('reportes.cierre-ajuste-export', ['close' => $close->id, 'adjustment' => $row->id, 'format' => 'csv']);
    auth()->logout();
    $this->get($url)->assertRedirect();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier)->get($url)->assertForbidden();
    $capture = app(MonthlyReportSnapshotQuery::class)->execute($actor, '2026-09');
    $other = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, ['month' => '2026-09', 'request_uuid' => (string) Str::uuid(), 'preview_hash' => $capture['hash'], 'accept_pending' => false], '1234');
    $this->actingAs($actor)->get(route('reportes.cierre-ajuste-export', ['close' => $other->id, 'adjustment' => $row->id, 'format' => 'csv']))->assertNotFound();
    expect(DB::table('report_exports')->count())->toBe(0);
});

it('C360 rechecks revoked authorization after capture and never saves an unauthorized adjustment', function () {
    $actor = c360Admin();
    $close = c360Close($actor);
    c360Movement($actor, 1);
    $input = c360Input($actor, $close->id);
    $this->mock(MonthlyReportAdjustmentQuery::class, function ($mock) use ($actor, $close): void {
        $mock->shouldReceive('preview')->once()->andReturnUsing(function () use ($actor, $close) {
            $quote = (new MonthlyReportAdjustmentQuery)->preview($actor, $close->id);
            User::findOrFail($actor->id)->removeRole('administrador');

            return $quote;
        });
    });
    expect(fn () => app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, $input, '1234'))->toThrow(AuthorizationException::class);
    expect(MonthlyReportAdjustment::count())->toBe(0);
});

it('C360 preserves a confirmed adjustment after export failure and can download it later', function () {
    $actor = c360Admin();
    $close = c360Close($actor);
    c360Movement($actor, 1);
    $row = app(ConfirmMonthlyReportAdjustmentAction::class)->execute($actor, c360Input($actor, $close->id), '1234');
    $this->mock(ReportSummaryCsv::class, function ($mock): void {
        $mock->shouldReceive('render')->once()->andThrow(new RuntimeException('Fallo simulado'));
    });
    expect(fn () => app(ExportReportSummaryAction::class)->adjusted($actor, $close->id, $row->id, 'CSV'))->toThrow(RuntimeException::class);
    expect(MonthlyReportAdjustment::count())->toBe(1);
    expect(DB::table('report_exports')->count())->toBe(0);
    $this->forgetMock(ReportSummaryCsv::class);
    $this->get(route('reportes.cierre-ajuste-export', ['close' => $close->id, 'adjustment' => $row->id, 'format' => 'csv']))->assertOk();
    expect(MonthlyReportAdjustment::count())->toBe(1);
    expect(DB::table('report_exports')->count())->toBe(1);
});
