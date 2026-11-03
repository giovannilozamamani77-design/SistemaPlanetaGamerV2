<?php

use App\Domain\Cajas\Actions\ApproveRegisteredCashBoxCloseAction;
use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\ConfirmCashBoxClosingAction;
use App\Domain\Cajas\Actions\LiquidateRegisteredCashBoxCloseAction;
use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Actions\RecordCashBoxTemporaryFundAction;
use App\Domain\Cajas\Actions\RecordQuickCashBoxCommissionAction;
use App\Domain\Cajas\Actions\RequestCashBoxCorrectionAction;
use App\Domain\Cajas\Actions\RequestCashBoxEarlyCloseAction;
use App\Domain\Cajas\Actions\RestoreRegisteredCashBoxCapitalAction;
use App\Domain\Cajas\Actions\ReviewCashBoxEarlyCloseAction;
use App\Domain\Cajas\Actions\SetCashBoxCommissionAccountAction;
use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Queries\CashBoxPreviousHandoverQuery;
use App\Domain\Cajas\Queries\ClosingHistoryQuery;
use App\Domain\Cajas\Services\ClosingConfirmationRules;
use App\Domain\Cajas\Services\ClosingRestoration;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Actions\ProcessCashBoxScheduleEventAction;
use App\Domain\ControlHorario\Actions\TimeHistoryAudit;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\Incidencias\Actions\ActivateAuthorizedHistoricalShortagesAction;
use App\Domain\Incidencias\Actions\EconomicHistoryAudit;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReportOwnRepaymentAction;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Queries\OwnIncidentDetailQuery;
use App\Domain\Liquidaciones\Actions\RecordClosingLiquidationAction;
use App\Domain\Liquidaciones\Actions\SetLiquidationRulesAction;
use App\Domain\Liquidaciones\Queries\ClosingLiquidationPreviewQuery;
use App\Domain\Liquidaciones\Queries\LiquidationHistoryQuery;
use App\Domain\Liquidaciones\Queries\RegisteredClosingLiquidationQuery;
use App\Domain\Notificaciones\Actions\ReadPanelNoticeAction;
use App\Domain\Notificaciones\Queries\PanelInboxQuery;
use App\Filament\Pages\Cajas\CashBoxCatalog;
use App\Filament\Pages\Cajas\CashBoxClosingStart;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Filament\Resources\Cajas\EarlyRequests\CashBoxEarlyRequestResource;
use App\Filament\Resources\Cajas\EarlyRequests\Pages\ListCashBoxEarlyRequests;
use App\Filament\Resources\Cajas\History\Pages\ListCashBoxCloses;
use App\Filament\Resources\Cajas\Workflow\CashBoxCorrectionResource;
use App\Filament\Resources\Cajas\Workflow\Pages\ListCashBoxCorrectionRecords;
use App\Filament\Resources\Liquidaciones\History\LiquidationHistoryResource;
use App\Filament\Resources\Liquidaciones\History\Pages\ListLiquidations;
use App\Filament\Widgets\Usuarios\OwnEconomicHistoryWidget;
use App\Models\User;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c80OpenFixture(string $source = 'bcp', ?string $destinationName = null): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'America/La_Paz'));
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole('administrador');
    $box = CashBox::factory()->create();
    $accounts = [];
    foreach (CashBoxComponents::forType('BCP') as $code => $name) {
        $accounts[$code] = ['name' => $name, 'initial' => match ($code) {
            'bcp' => '15000', 'efectivo' => '2000', default => '3000'
        }];
    }
    app(ConfigureCashBoxAction::class)->execute($user, $box, ['accounts' => $accounts, 'base_capital' => '20000', 'shifts' => CashBoxComponents::defaultShifts(), 'expected_revision' => $box->revision, 'reason' => 'Configuracion ficticia de prueba', 'request_key' => (string) Str::uuid()]);
    if ($source !== 'bcp') {
        app(SetLiquidationRulesAction::class)->execute($user, $box->id, ['source' => $source, 'source_reference' => '76537982', 'morning' => false, 'afternoon' => true, 'expected_revision' => $box->fresh()->revision, 'reason' => 'Origen de prueba', 'request_key' => (string) Str::uuid()]);
    }
    if ($destinationName !== null) {
        $destinationId = app(SetCashBoxCommissionAccountAction::class)->execute($user, ['name' => $destinationName, 'holder_name' => 'Titular seleccionado',
            'destination' => '80000000', 'reason' => 'Destino ficticio por caja', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
        $destinationKey = DB::table('cash_box_commission_accounts')->find($destinationId)->account_key;
        app(SetLiquidationRulesAction::class)->execute($user, $box->id, ['source' => $source, 'destination_key' => $destinationKey,
            'morning' => false, 'afternoon' => true, 'expected_revision' => $box->fresh()->revision, 'reason' => 'Asignación ficticia', 'request_key' => (string) Str::uuid()]);
    }
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->actingAs($user);

    return [$user, $box, $session];
}

function c80Confirmation($user, $session): array
{
    $account = app(SetCashBoxCommissionAccountAction::class)->execute($user, [
        'destination' => '70000000', 'holder_name' => 'Titular ficticio', 'reason' => 'Cuenta aislada de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid(),
    ]);

    return ['period' => 'tarde', 'balances' => ['efectivo' => '5000', 'bcp' => '15050', 'yape' => ''],
        'readings' => ['general' => '100', 'fixed' => '50'], 'expected_revision' => $session->fresh()->revision,
        'liquidate' => true, 'account_id' => $account, 'amount_cents' => 5000, 'operation_reference' => 'TEST-TRANSFER-001',
        'transferred' => true, 'reason' => '', 'cash_count' => null, 'request_key' => (string) Str::uuid()];
}

it('confirms liquidation and closing once preserving counted balances capital and history', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = c80Confirmation($user, $session);
    $action = app(ConfirmCashBoxClosingAction::class);
    $close = $action->execute($user, $session->id, $data);
    expect($action->execute($user, $session->id, $data)->id)->toBe($close->id);
    expect($action->execute($user, $session->id, [...$data, 'operation_reference' => '  TEST-TRANSFER-001  '])->id)->toBe($close->id);
    expect($close->counted_balances['bcp'])->toBe(1505000);
    expect($close->real_balances['bcp'])->toBe(1500000);
    expect($session->fresh()->status)->toBe('CERRADA');
    expect($session->fresh()->active_slot)->toBeNull();
    expect(array_sum($session->fresh()->handover_balances))->toBe(2000000);
    expect($session->fresh()->current_balances['efectivo'])->toBe(500000);
    $this->assertDatabaseCount('cash_box_closes', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
    $this->assertDatabaseCount('cash_box_counter_readings', 1);
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
    $this->assertDatabaseHas('cash_box_liquidations', ['cash_box_close_id' => $close->id, 'amount_cents' => 5000, 'source' => 'bcp']);
});

it('rolls back a failure after recording the transfer and permits a clean retry', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = c80Confirmation($user, $session);
    $before = $session->fresh()->getAttributes();
    $real = new RecordClosingLiquidationAction;
    $this->mock(RecordClosingLiquidationAction::class, function ($mock) use ($real) {
        $mock->shouldReceive('execute')->once()->andReturnUsing(function (...$args) use ($real) {
            $real->execute(...$args);
            throw new RuntimeException('Interrupción simulada después de descontar');
        });
    });
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(RuntimeException::class);
    expect($session->fresh()->getAttributes())->toBe($before);
    foreach (['cash_box_closes', 'cash_box_liquidations', 'cash_box_liquidation_allocations', 'cash_box_counter_readings', 'cash_box_commands'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    app()->instance(RecordClosingLiquidationAction::class, $real);
    app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
});

it('rejects stale revisions changed amounts missing declarations references and invalid readings atomically', function ($changes) {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = array_replace_recursive(c80Confirmation($user, $session), $changes);
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    expect($session->fresh()->status)->toBe('ABIERTA');
    foreach (['cash_box_closes', 'cash_box_liquidations', 'cash_box_counter_readings', 'cash_box_close_drafts'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with([
    'revision' => [['expected_revision' => 99]], 'amount' => [['amount_cents' => 6000]],
    'declaration' => [['transferred' => false]], 'reference' => [['operation_reference' => '']],
    'counter' => [['readings' => ['fixed' => '-1']]], 'balance' => [['balances' => ['bcp' => '-1']]],
    'source' => [['balances' => ['bcp' => '0', 'efectivo' => '21000']]],
]);

it('rejects another owner duplicate request payload and a second closing under a new key', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = c80Confirmation($user, $session);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    $action = app(ConfirmCashBoxClosingAction::class);
    expect(fn () => $action->execute($other, $session->id, $data))->toThrow(AuthorizationException::class);
    $action->execute($user, $session->id, $data);
    expect(fn () => $action->execute($user, $session->id, [...$data, 'operation_reference' => 'OTRA']))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($user, $session->id, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_closes', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
});

it('blocks early closing and unresolved corrections without freezing the open box', function () {
    [$user, $box, $session] = c80OpenFixture();
    $data = c80Confirmation($user, $session);
    $action = app(ConfirmCashBoxClosingAction::class);
    expect(fn () => $action->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 2, 'request_key' => (string) Str::uuid()]);
    app(RequestCashBoxCorrectionAction::class)->execute($user, $operation, ['reason' => 'Corregir dato ficticio', 'request_key' => (string) Str::uuid()]);
    $data['expected_revision'] = $session->fresh()->revision;
    expect(fn () => $action->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    expect($session->fresh()->status)->toBe('ABIERTA');
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('rejects a demonstration or superseded destination without retaining any closing', function ($demo) {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = c80Confirmation($user, $session);
    if ($demo) {
        DB::table('cash_box_commission_accounts')->where('id', $data['account_id'])->update(['is_demo' => true]);
    } else {
        app(SetCashBoxCommissionAccountAction::class)->execute($user, ['destination' => '70000001', 'holder_name' => 'Nuevo titular de prueba', 'reason' => 'Cambio ficticio', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    }
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
})->with([true, false]);

it('closes a non liquidating shift without a transfer and hands its balances to the next PIN opening', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(14, 0));
    $data = c80Confirmation($user, $session);
    $data = [...$data, 'period' => 'manana', 'balances' => ['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000'], 'liquidate' => false, 'amount_cents' => 0];
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->requires_liquidation)->toBeFalse();
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    expect($access->expected_balances)->toBe(['bcp' => 1500000, 'efectivo' => 200000, 'yape' => 300000]);
    $next = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => $data['balances'], 'request_key' => (string) Str::uuid()]);
    expect($next->previous_session_id)->toBe($session->id);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('records an optional count from its pieces and packed amounts without trusting its supplied total', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = c80Confirmation($user, $session);
    $data['cash_count'] = ['quantities' => ['b100' => '40'], 'packed_amounts_cents' => ['b100' => 100000], 'total_cents' => 1];
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->count_details['cash_count']['total_cents'])->toBe(500000);
    expect($close->count_details['method'])->toBe('ARQUEO');
});

it('keeps a shortage pending for administration while liquidating only the real available excess', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 200, 'request_key' => (string) Str::uuid()]);
    $data = c80Confirmation($user, $session);
    $data['reason'] = 'Faltante ficticio de cincuenta bolivianos';
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->difference_cents)->toBe(-5000);
    expect($session->fresh()->status)->toBe('CIERRE_REGISTRADO');
    expect($session->fresh()->active_slot)->toBe(1);
    expect($close->economic_incident_id)->not->toBeNull();
    expect(fn () => app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid()))->toThrow(ValidationException::class);
    $review = ['reason' => 'Confirmación ficticia con capital intacto', 'exception' => true, 'request_key' => (string) Str::uuid()];
    $approval = app(ApproveRegisteredCashBoxCloseAction::class);
    $approved = $approval->execute($user, $close->id, $review);
    expect($approval->execute($user, $close->id, $review)->id)->toBe($approved->id);
    expect($session->fresh()->status)->toBe('CERRADA_CON_INCIDENCIAS');
    $this->assertDatabaseCount('economic_incidents', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
});

it('never releases a shortage invading capital or invents a transfer to fill it', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14900', 'efectivo' => '5000'], 'amount_cents' => 0, 'reason' => 'Falta capital real de prueba'];
    $close = c98LegacyPending($user, $session, $data);
    expect(fn () => app(ApproveRegisteredCashBoxCloseAction::class)->execute($user, $close->id, ['reason' => 'Revisar sin inventar dinero', 'exception' => true, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect($session->fresh()->handover_balances)->toBeNull();
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('confirms through the summary modal and leaves cancellation without writes', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    c80Confirmation($user, $session);
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'tarde')->call('nextStep')
        ->set('balances.efectivo', '5000')->set('balances.bcp', '15050')->call('nextStep')->set('readings.general', '100')->set('readings.fixed', '50')->call('nextStep')
        ->assertSeeHtml('wire:click="openLiquidationPreview"')->assertDontSee('Próximamente')->assertDontSee('están pendientes de habilitar')
        ->call('openLiquidationPreview')->assertSee('Confirmar liquidación y cierre')->call('dismissLiquidationPreview');
    $this->assertDatabaseCount('cash_box_closes', 0);
    $page->call('openLiquidationPreview')->set('operationReference', 'TEST-UI-001')->set('transferred', true)->call('confirmClosing')->assertHasNoErrors()->assertRedirect(CashBoxCatalog::getUrl());
    $this->assertDatabaseCount('cash_box_closes', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
    Livewire::test(ListCashBoxCloses::class)->assertSee('Historial de cierres')->assertCanSeeTableRecords(CashBoxClose::all());
});

it('deducts from the configured Yape origin and never from BCP or cash', function () {
    [$user, $box, $session] = c80OpenFixture('yape');
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '12000', 'yape' => '3050', 'efectivo' => '5000']];
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->real_balances)->toBe(['bcp' => 1200000, 'efectivo' => 500000, 'yape' => 300000]);
    $this->assertDatabaseHas('cash_box_liquidations', ['source' => 'yape', 'amount_cents' => 5000]);
});

it('excludes custody and uses operating capital after a real temporary deposit', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    app(RecordCashBoxTemporaryFundAction::class)->execute($user, $session->id, ['kind' => 'DEPOSITO', 'component' => 'bcp', 'amount' => '5000', 'reason' => 'Fondos temporales de prueba', 'occurred' => true, 'request_key' => (string) Str::uuid()]);
    $this->mock(ReservedCashBoxFunds::class, function ($mock) use ($box) {
        $mock->shouldReceive('forBox')->once()->with($box->id)->andReturn(['bcp' => 10000]);
    });
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '20150', 'efectivo' => '5000']];
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->capital_cents)->toBe(2500000);
    expect($close->liquidated_cents)->toBe(5000);
    expect(array_sum($session->fresh()->handover_balances))->toBe(2510000);
    expect($close->reserved_funds_snapshot)->toBe(['bcp' => 10000]);
});

it('blocks closing when a redistribution cannot cover reserved custody', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = c80Confirmation($user, $session);
    $this->mock(ReservedCashBoxFunds::class, function ($mock) use ($box) {
        $mock->shouldReceive('forBox')->once()->with($box->id)->andReturn(['bcp' => 1600000]);
    });
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('preserves the commission operation and refuses further commissions after the closing', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $before = $operation->getAttributes();
    $data = c80Confirmation($user, $session);
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->difference_cents)->toBe(0);
    expect($close->commission_cents)->toBe(5000);
    expect($operation->fresh()->getAttributes())->toBe($before);
    expect(fn () => app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('records an authorized early closing without treating an unapproved request as permission', function () {
    [$user, $box, $session] = c80OpenFixture();
    $data = c80Confirmation($user, $session);
    DB::table('cash_box_early_requests')->insert(['cash_box_session_id' => $session->id, 'period' => 'tarde', 'reason' => 'Salida ficticia autorizada', 'status' => 'PENDIENTE', 'created_at' => now()]);
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    DB::table('cash_box_early_requests')->update(['status' => 'APROBADA', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_reason' => 'Permiso de prueba']);
    app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    $this->assertDatabaseCount('cash_box_closes', 1);
});

it('C124 shares closing history without exposing settlement data or administrative screens', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14900', 'efectivo' => '5000'], 'amount_cents' => 0, 'reason' => 'Faltante ficticio'];
    $close = c98LegacyPending($user, $session, $data);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    $page = Livewire::test(ListCashBoxCloses::class)->assertCanSeeTableRecords([$close])->assertTableColumnHidden('liquidated_cents');
    $projected = app(ClosingHistoryQuery::class)->forUser($cashier)->findOrFail($close->id);
    foreach (['commission_cents', 'liquidated_cents', 'counted_balances', 'count_details', 'expected_balances', 'commission_account_id', 'difference_cents'] as $private) {
        expect(array_key_exists($private, $projected->getAttributes()))->toBeFalse();
    }
    $this->get(CashBoxEarlyRequestResource::getUrl())->assertForbidden();
    $this->get(CashBoxCorrectionResource::getUrl())->assertForbidden();
    $this->get(LiquidationHistoryResource::getUrl())->assertForbidden();

    $page->mountTableAction('detail', $close)->assertMountedActionModalSee('Saldos posteriores')
        ->assertMountedActionModalDontSee('Comisiones de la sesión')->assertMountedActionModalDontSee('Liquidación');
    foreach ([ListLiquidations::class,
        ListCashBoxEarlyRequests::class,
        ListCashBoxCorrectionRecords::class] as $screen) {
        Livewire::test($screen)->assertForbidden();
    }
    expect(fn () => app(LiquidationHistoryQuery::class)->forUser($cashier))->toThrow(AuthorizationException::class);
    $this->actingAs($user);
    Livewire::test(ListCashBoxCloses::class)->assertTableColumnVisible('liquidated_cents')
        ->mountTableAction('detail', $close)->assertMountedActionModalSee('Comisiones de la sesión');
    expect(fn () => app(ApproveRegisteredCashBoxCloseAction::class)->execute($cashier, $close->id, ['reason' => 'No autorizado', 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
});

it('restores only real missing capital once and preserves the cashiers original shortage', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14900', 'efectivo' => '5000'], 'amount_cents' => 0, 'reason' => 'Falta capital de prueba'];
    $close = c98LegacyPending($user, $session, $data);
    $restore = ['component' => 'bcp', 'amount' => '100', 'receipt' => 'Transferencia real simulada en prueba', 'occurred' => true,
        'expected_revision' => $session->fresh()->revision, 'request_key' => (string) Str::uuid()];
    $action = app(RestoreRegisteredCashBoxCapitalAction::class);
    $restored = $action->execute($user, $close->id, $restore);
    $action->execute($user, $close->id, $restore);
    expect($restored->counted_balances['bcp'])->toBe(1490000);
    expect($restored->real_balances['bcp'])->toBe(1500000);
    expect($restored->difference_cents)->toBe(-10000);
    expect(fn () => $action->execute($user, $close->id, [...$restore, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    app(ApproveRegisteredCashBoxCloseAction::class)->execute($user, $close->id, ['reason' => 'Revisión después de aporte real', 'exception' => true, 'request_key' => (string) Str::uuid()]);
    expect(array_sum($session->fresh()->handover_balances))->toBe(2000000);
    $this->assertDatabaseCount('cash_box_capital_restorations', 1);
    $this->assertDatabaseCount('economic_incidents', 1);
    $this->assertDatabaseHas('economic_incidents', ['id' => $restored->economic_incident_id, 'amount_cents' => 10000]);
});

it('warns against transferring while the closing is premature or stale', function () {
    [$user, $box, $session] = c80OpenFixture();
    c80Confirmation($user, $session);
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertRedirect(CashBoxOperations::getUrl(['cashBox' => $box->id]));
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'tarde')->call('nextStep')->set('balances.efectivo', '5000')->set('balances.bcp', '15050')
        ->call('nextStep')->set('readings.general', '100')->set('readings.fixed', '50')->call('nextStep')->call('openLiquidationPreview')
        ->assertSet('liquidationReady', true)->assertSee('Confirmar liquidación y cierre');
    $page->call('dismissLiquidationPreview');
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $page->call('openLiquidationPreview')->assertSet('liquidationReady', false)->assertSee('La caja cambió desde la revisión.');
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('rejects reuse of the bank transfer reference in another closing without touching either history', function () {
    [$firstUser, $firstBox, $firstSession] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    app(ConfirmCashBoxClosingAction::class)->execute($firstUser, $firstSession->id, c80Confirmation($firstUser, $firstSession));
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = c80Confirmation($user, $session);
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    expect($session->fresh()->status)->toBe('ABIERTA');
    $this->assertDatabaseCount('cash_box_closes', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
});

it('renders the administrative restoration form with revision control and rejects excess replenishment', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14900', 'efectivo' => '5000'], 'amount_cents' => 0, 'reason' => 'Falta capital de prueba'];
    $close = c98LegacyPending($user, $session, $data);
    $page = Livewire::test(ListCashBoxCloses::class)
        ->mountTableAction('restore', $close)->assertTableActionDataSet(['expected_revision' => $session->fresh()->revision])
        ->setTableActionData(['component' => 'bcp', 'amount' => '101', 'receipt' => 'Transferencia simulada', 'occurred' => true, 'expected_revision' => $session->fresh()->revision, 'request_key' => (string) Str::uuid()])
        ->callMountedTableAction()->assertHasTableActionErrors(['receipt']);
    $this->assertDatabaseCount('cash_box_capital_restorations', 0);
});

it('cannot finalize below operating capital even if the count happens to match an insufficient opening', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $session->update(['operating_capital_cents' => 3000000]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000'], 'amount_cents' => 0];
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    expect($session->fresh()->status)->toBe('ABIERTA');
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('confirms only the destination selected for the session and preserves its historical version on retries', function () {
    [$user, $box, $session] = c80OpenFixture(destinationName: 'Destino exclusivo de caja');
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = c80Confirmation($user, $session);
    $query = app(ClosingLiquidationPreviewQuery::class);
    $quote = $query->execute($user, $session->id, 'tarde', $data['balances']);
    expect($quote['account']->name)->toBe('Destino exclusivo de caja');
    $action = app(ConfirmCashBoxClosingAction::class);
    expect(fn () => $action->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
    $data['account_id'] = $quote['account']->id;
    $close = $action->execute($user, $session->id, $data);
    expect($action->execute($user, $session->id, $data)->id)->toBe($close->id);
    expect($close->commission_account_id)->toBe($quote['account']->id);
    expect($close->settlement_destination)->toBe('80000000');
    expect($close->realTotal())->toBe(2000000);
    app(SetCashBoxCommissionAccountAction::class)->execute($user, ['account_id' => $quote['account']->id, 'name' => 'Destino actualizado',
        'holder_name' => 'Nuevo titular', 'destination' => '80000001', 'reason' => 'Cambio ficticio posterior', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    expect($close->fresh()->destinationAccount->name)->toBe('Destino exclusivo de caja');
    expect($close->fresh()->destinationAccount->holder_name)->toBe('Titular seleccionado');
    $this->assertDatabaseCount('cash_box_liquidations', 1);
});

it('does not redirect an open session when another destination is assigned for future openings', function () {
    [$user, $box, $session] = c80OpenFixture(destinationName: 'Destino congelado');
    $data = c80Confirmation($user, $session);
    $before = $session->fresh()->getAttributes();
    $new = app(SetCashBoxCommissionAccountAction::class)->execute($user, ['name' => 'Destino futuro', 'holder_name' => 'Titular futuro',
        'destination' => '90000000', 'reason' => 'Otra cuenta ficticia', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    app(SetLiquidationRulesAction::class)->execute($user, $box->id, ['source' => 'bcp', 'destination_key' => DB::table('cash_box_commission_accounts')->find($new)->account_key,
        'morning' => false, 'afternoon' => true, 'expected_revision' => $box->fresh()->revision, 'reason' => 'Cambiar próxima apertura', 'request_key' => (string) Str::uuid()]);
    $quote = app(ClosingLiquidationPreviewQuery::class)->execute($user, $session->id, 'tarde', $data['balances']);
    expect($quote['account']->name)->toBe('Destino congelado');
    expect($session->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('rejects a destination revision changed after preview without persisting a partial close', function () {
    [$user, $box, $session] = c80OpenFixture(destinationName: 'Destino revisado');
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $data = c80Confirmation($user, $session);
    $quote = app(ClosingLiquidationPreviewQuery::class)->execute($user, $session->id, 'tarde', $data['balances']);
    $data['account_id'] = $quote['account']->id;
    $new = app(SetCashBoxCommissionAccountAction::class)->execute($user, ['account_id' => $data['account_id'], 'name' => 'Destino revisado',
        'holder_name' => 'Otro titular', 'destination' => '80000001', 'reason' => 'Nueva versión ficticia', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
    expect($session->fresh()->status)->toBe('ABIERTA');
    $data['account_id'] = $new;
    expect(app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data)->settlement_destination)->toBe('80000001');
});

it('shows the previous handover inline after PIN with only its actual closing count', function (bool $withCount) {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(14, 0));
    $data = [...c80Confirmation($user, $session), 'period' => 'manana',
        'balances' => ['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000'], 'liquidate' => false, 'amount_cents' => 0];
    if ($withCount) {
        $data['cash_count'] = ['quantities' => ['b100' => '10', 'm010' => '1000'], 'packed_amounts_cents' => ['m1' => 90000]];
    }
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $page = Livewire::test(CashBoxCatalog::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->assertSet('openingBalances.efectivo', '2000')->assertSeeInOrder(['Ver registro anterior', 'Saldos de apertura']);
    expect($page->instance()->previousHandover['balances'])->toBe($session->fresh()->handover_balances);
    if ($withCount) {
        $page->assertSee('Arqueo disponible')->assertSee('Ver arqueo')->assertSee('Billetes')->assertSee('Monedas')->assertSee('Piezas sueltas')->assertSee('Valor embalado')->assertSee('Total del corte')
            ->assertSeeHtml('data-count-denomination="b100"')->assertSeeHtml('data-count-denomination="m1"')->assertSeeHtml('data-count-denomination="m010"')
            ->assertDontSeeHtml('data-count-denomination="b200"')->assertDontSeeHtml('data-count-denomination="m5"');
        expect($page->instance()->previousHandover['cash_count'])->toBe($close->count_details['cash_count']);
    } else {
        $page->assertSee('No se tiene arqueo.')->assertDontSee('Arqueo disponible');
        expect($page->instance()->previousHandover['cash_count'])->toBeNull();
    }
    $page->set('openingBalances.efectivo', '100');
    expect($page->instance()->previousHandover['balances']['efectivo'])->toBe(200000);
    $this->assertDatabaseCount('cash_box_sessions', 1);
    $this->assertDatabaseCount('cash_box_closes', 1);
    $accessId = $page->get('accessId');
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(fn () => app(CashBoxPreviousHandoverQuery::class)->execute($other, $accessId))
        ->toThrow(AuthorizationException::class);
})->with([true, false]);

it('allows an early closing in paused mode without changing its financial protections', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    $data = c80Confirmation($user, $session);
    $action = app(ConfirmCashBoxClosingAction::class);
    expect(app(ClosingConfirmationRules::class)->preview($user, $session->id, $data)['ready'])->toBeTrue();
    $close = $action->execute($user, $session->id, $data);
    expect($close->realTotal())->toBe(2000000)->and($close->liquidated_cents)->toBe(5000);
    expect($action->execute($user, $session->id, $data)->id)->toBe($close->id);
    $this->assertDatabaseCount('cash_box_closes', 1);
    $this->assertDatabaseCount('time_incidents', 0);
    $this->assertDatabaseCount('cash_box_early_requests', 0);
});

it('C98 blocks liquidation below capital before recording any closing', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14900', 'efectivo' => '5000'], 'amount_cents' => 0, 'reason' => 'Faltante original de prueba'];
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
    expect($session->fresh()->status)->toBe('ABIERTA');
});

it('C98 restores the full shortage atomically and preserves the original count and incident', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14990', 'efectivo' => '5000'], 'reason' => 'Faltante original sesenta bolivianos'];
    $service = app(ClosingRestoration::class);
    $data['restoration_token'] = $service->authorize($user, $session->id, $data, ['amount' => '60', 'component' => 'bcp', 'receipt' => 'Aporte completo recibido', 'received' => true], $user->id, '');
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->countedTotal())->toBe(1999000)->and($close->difference_cents)->toBe(-6000)
        ->and($close->realTotal())->toBe(2000000)->and($close->liquidated_cents)->toBe(5000);
    $this->assertDatabaseHas('economic_incidents', ['id' => $close->economic_incident_id, 'amount_cents' => 6000]);
    $this->assertDatabaseHas('cash_box_capital_restorations', ['cash_box_close_id' => $close->id, 'amount_cents' => 6000]);
    expect(app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data)->id)->toBe($close->id);
    $this->assertDatabaseCount('cash_box_capital_restorations', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
    expect($session->fresh()->status)->toBe('CIERRE_REGISTRADO');
});

it('C98 permits administrative continuity of a non liquidating shortage below capital', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    $data = [...c80Confirmation($user, $session), 'period' => 'manana', 'liquidate' => false, 'balances' => ['bcp' => '14990', 'efectivo' => '5000'], 'amount_cents' => 0, 'reason' => 'Faltante no liquidante'];
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    app(ApproveRegisteredCashBoxCloseAction::class)->execute($user, $close->id, ['reason' => 'Continuidad con saldo real', 'exception' => true, 'request_key' => (string) Str::uuid()]);
    expect($session->fresh()->status)->toBe('CERRADA_CON_INCIDENCIAS');
    expect(array_sum($session->fresh()->handover_balances))->toBe(1999000);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('C98 preserves every commission shortage cent with and without liquidation', function (string $missing, bool $liquidates) {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $missingCents = Bolivianos::cents($missing);
    $data = [...c80Confirmation($user, $session), 'period' => $liquidates ? 'tarde' : 'manana', 'liquidate' => $liquidates,
        'balances' => ['bcp' => Bolivianos::format(1505000 - $missingCents), 'efectivo' => '5000'],
        'amount_cents' => $liquidates ? 5000 - $missingCents : 0, 'reason' => 'Diferencia de prueba por centavos'];
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->difference_cents)->toBe(-$missingCents)->and($close->commission_cents)->toBe(5000)
        ->and($close->liquidated_cents)->toBe($liquidates ? 5000 - $missingCents : 0);
    $this->assertDatabaseHas('economic_incidents', ['id' => $close->economic_incident_id, 'amount_cents' => $missingCents]);
    expect($session->fresh()->status)->toBe('CIERRE_REGISTRADO');
    $this->assertDatabaseCount('cash_box_liquidations', $liquidates && $missingCents < 5000 ? 1 : 0);
})->with(['0.10', '2', '3', '5', '10', '20', '49', '50'])->with([true, false]);

it('C98 rejects altered expired revoked or forged restoration grants without writes', function (string $change) {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14900', 'efectivo' => '5000'], 'reason' => 'Faltante original de prueba', 'amount_cents' => 0];
    $service = app(ClosingRestoration::class);
    $data['restoration_token'] = $service->authorize($user, $session->id, $data, ['amount' => '100', 'component' => 'bcp', 'receipt' => 'Aporte real de prueba', 'received' => true], $user->id, '');
    if ($change === 'balances') {
        $data['balances']['bcp'] = '14901';
    }
    if ($change === 'expired') {
        $this->travel(31)->minutes();
    }
    if ($change === 'revision') {
        $session->increment('revision');
        $data['expected_revision'] = $session->fresh()->revision;
    }
    if ($change === 'forged') {
        $data['restoration_token'] .= 'alterado';
    }
    if ($change === 'revoked') {
        $user->removeRole('administrador');
        $user->assignRole('cajera');
    }
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow($change === 'revoked' ? AuthorizationException::class : ValidationException::class);
    foreach (['cash_box_closes', 'cash_box_capital_restorations', 'cash_box_liquidations'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with(['balances', 'expired', 'revision', 'forged', 'revoked']);

it('C98 accepts the minimum real restoration without erasing the full shortage or creating a zero transfer', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14990', 'efectivo' => '5000'], 'reason' => 'Sesenta faltantes originales', 'amount_cents' => 0];
    $data['restoration_token'] = app(ClosingRestoration::class)->authorize($user, $session->id, $data,
        ['amount' => '10', 'component' => 'bcp', 'receipt' => 'Aporte minimo recibido', 'received' => true], $user->id, '');
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->difference_cents)->toBe(-6000)->and($close->realTotal())->toBe(2000000);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
    $this->assertDatabaseCount('economic_incidents', 1);
});

it('C98 applies the same restoration in the summary modal and final confirmation without draft writes', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    c80Confirmation($user, $session);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'tarde')->call('nextStep')
        ->set('balances.bcp', '14990')->set('balances.efectivo', '5000')->call('nextStep')->set('readings.general', '100')->set('readings.fixed', '50')->call('nextStep')
        ->set('closingReason', 'Faltante original conservado')->assertSee('Capital por reponer')
        ->call('openLiquidationPreview')->assertSet('liquidationReady', false)->call('dismissLiquidationPreview')
        ->set('restorationAdminId', $user->id)->set('restorationInput', ['amount' => '60', 'component' => 'bcp', 'receipt' => 'Aporte de prueba recibido', 'received' => true])
        ->call('authorizeRestoration')->assertHasNoErrors()->assertSet('restorationAdminPin', '');
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_capital_restorations', 0);
    $page->call('openLiquidationPreview')->assertSet('liquidationReady', true)->set('operationReference', 'C98-UI-TRANSFER')->set('transferred', true)->call('confirmClosing')->assertHasNoErrors();
    $this->assertDatabaseHas('cash_box_closes', ['difference_cents' => -6000, 'liquidated_cents' => 5000]);
});

/** Fictitious legacy close: old versions allowed recording below capital before restoration. */
function c98LegacyPending($user, $session, array $data): CashBoxClose
{
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, [...$data, 'period' => 'manana', 'liquidate' => false]);
    $close->update(['period' => 'tarde', 'requires_liquidation' => true, 'liquidation_completed' => false]);

    return $close;
}

it('C98 allows full restoration of a registered shortage then settles the excess once before administrative release', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14990', 'efectivo' => '5000'], 'reason' => 'Faltante completo sesenta', 'amount_cents' => 0];
    $close = c98LegacyPending($user, $session, $data);
    $restore = ['amount' => '60', 'component' => 'bcp', 'receipt' => 'Reposicion completa recibida', 'occurred' => true, 'expected_revision' => $session->fresh()->revision, 'request_key' => (string) Str::uuid()];
    app(RestoreRegisteredCashBoxCapitalAction::class)->execute($user, $close->id, $restore);
    expect($close->fresh()->liquidation_completed)->toBeFalse();
    $review = ['reason' => 'Revision de continuidad', 'exception' => true, 'request_key' => (string) Str::uuid()];
    expect(fn () => app(ApproveRegisteredCashBoxCloseAction::class)->execute($user, $close->id, $review))->toThrow(ValidationException::class);
    $settle = [...$data, 'amount_cents' => 5000, 'operation_reference' => 'C98-POST-RESTORE', 'expected_revision' => $session->fresh()->revision, 'request_key' => (string) Str::uuid()];
    $action = app(LiquidateRegisteredCashBoxCloseAction::class);
    $action->execute($user, $close->id, $settle);
    $action->execute($user, $close->id, $settle);
    expect($close->fresh()->difference_cents)->toBe(-6000)->and($close->fresh()->realTotal())->toBe(2000000);
    app(ApproveRegisteredCashBoxCloseAction::class)->execute($user, $close->id, $review);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
    $this->assertDatabaseHas('economic_incidents', ['id' => $close->economic_incident_id, 'amount_cents' => 6000]);
});

it('C98 rolls back a restoration if final liquidation fails and allows one retry', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14990', 'efectivo' => '5000'], 'reason' => 'Faltante original sesenta'];
    $data['restoration_token'] = app(ClosingRestoration::class)->authorize($user, $session->id, $data,
        ['amount' => '60', 'component' => 'bcp', 'receipt' => 'Aporte recibido rollback', 'received' => true], $user->id, '');
    $before = $session->fresh()->getAttributes();
    $real = new RecordClosingLiquidationAction;
    $this->mock(RecordClosingLiquidationAction::class, function ($mock) use ($real) {
        $mock->shouldReceive('execute')->once()->andReturnUsing(function (...$args) use ($real) {
            $real->execute(...$args);
            throw new RuntimeException('Interrupcion simulada C98');
        });
    });
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(RuntimeException::class);
    expect($session->fresh()->getAttributes())->toBe($before);
    foreach (['cash_box_closes', 'cash_box_capital_restorations', 'cash_box_liquidations', 'economic_incidents', 'cash_box_counter_readings'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    app()->instance(RecordClosingLiquidationAction::class, $real);
    app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    $this->assertDatabaseCount('cash_box_capital_restorations', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
});

it('C98 requires a real received amount and fresh administrative identity for restoration', function (array $changes) {
    [$user, $box, $session] = c80OpenFixture();
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14900', 'efectivo' => '5000']];
    $input = array_replace(['amount' => '100', 'component' => 'bcp', 'receipt' => 'Aporte de prueba', 'received' => true], $changes);
    expect(fn () => app(ClosingRestoration::class)->authorize($user, $session->id, $data, $input, $user->id, ''))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_capital_restorations', 0);
})->with([[['amount' => '101']], [['amount' => '0']], [['amount' => '-1']], [['received' => false]], [['component' => 'otra']]]);

it('C98 verifies another administrators PIN without allowing the cashier to self authorize', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '14900', 'efectivo' => '5000'], 'amount_cents' => 0, 'reason' => 'Faltante original de prueba'];
    $admin = User::factory()->create(['pin_hash' => Hash::make('4321')]);
    $admin->assignRole('administrador');
    $user->removeRole('administrador');
    $user->assignRole('cajera');
    $input = ['amount' => '100', 'component' => 'bcp', 'receipt' => 'Aporte validado por admin', 'received' => true];
    $service = app(ClosingRestoration::class);
    expect(fn () => $service->authorize($user, $session->id, $data, $input, $user->id, ''))->toThrow(AuthorizationException::class);
    expect(fn () => $service->authorize($user, $session->id, $data, $input, $admin->id, '0000'))->toThrow(ValidationException::class);
    $data['restoration_token'] = $service->authorize($user, $session->id, $data, $input, $admin->id, '4321');
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    $this->assertDatabaseHas('cash_box_capital_restorations', ['cash_box_close_id' => $close->id, 'actor_user_id' => $admin->id]);
});

it('C98 blocks the operations step when a correction is unresolved without persisting a closing', function () {
    [$user, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 2, 'request_key' => (string) Str::uuid()]);
    app(RequestCashBoxCorrectionAction::class)->execute($user, $operation, ['reason' => 'Correccion pendiente de prueba', 'request_key' => (string) Str::uuid()]);
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'tarde')->call('nextStep')->call('nextStep')
        ->set('readings.general', '10')->set('readings.fixed', '5')->call('nextStep')->assertSet('step', 3)->assertHasErrors(['readings']);
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('C98 blocks insufficient restoration and an unfunded source despite restoring the total', function (bool $emptySource) {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $data = [...c80Confirmation($user, $session), 'balances' => $emptySource ? ['bcp' => '0', 'efectivo' => '19990'] : ['bcp' => '14990', 'efectivo' => '5000'], 'reason' => 'Faltante original sesenta'];
    $data['restoration_token'] = app(ClosingRestoration::class)->authorize($user, $session->id, $data,
        ['amount' => $emptySource ? '60' : '5', 'component' => 'efectivo', 'receipt' => 'Aporte real registrado al confirmar', 'received' => true], $user->id, '');
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_capital_restorations', 0);
})->with([true, false]);

it('C98 cancels authorized preparation back to commissions without storing or recovering a closing', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'tarde')->call('nextStep')
        ->set('balances.bcp', '14900')->set('balances.efectivo', '5000')->call('nextStep')->set('readings.general', '100')->set('readings.fixed', '50')->call('nextStep')
        ->set('restorationAdminId', $user->id)->set('restorationInput', ['amount' => '100', 'component' => 'bcp', 'receipt' => 'Declaracion ficticia sin movimiento real', 'received' => true])
        ->call('authorizeRestoration')->assertHasNoErrors();
    $page->call('cancelClosing')->assertSet('restorationToken', null)->assertSet('step', 1)
        ->assertRedirect(CashBoxOperations::getUrl(['cashBox' => $box->id]));
    foreach (['cash_box_closes', 'cash_box_capital_restorations', 'cash_box_liquidations', 'cash_box_close_drafts'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect($session->fresh()->status)->toBe('ABIERTA');
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertSet('step', 1)->assertSet('restorationToken', null)->assertSet('period', null);
});

it('C98 settles a later full commission restoration without duplicating the original liquidation', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '15030', 'efectivo' => '5000'], 'amount_cents' => 3000, 'reason' => 'Faltante original de veinte'];
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    $restore = ['amount' => '20', 'component' => 'bcp', 'receipt' => 'Aporte completo posterior', 'occurred' => true, 'expected_revision' => $session->fresh()->revision, 'request_key' => (string) Str::uuid()];
    app(RestoreRegisteredCashBoxCapitalAction::class)->execute($user, $close->id, $restore);
    expect($close->fresh()->liquidation_completed)->toBeFalse();
    $quote = app(RegisteredClosingLiquidationQuery::class)->execute($user, $close->id);
    expect($quote['calculation']['amount_cents'])->toBe(2000);
    Livewire::test(ListCashBoxCloses::class)->mountTableAction('settleRestored', $close->fresh())
        ->assertTableActionDataSet(['amount_cents' => 2000, 'expected_revision' => $session->fresh()->revision])
        ->setTableActionData(['amount_cents' => 2000, 'account_id' => $quote['account']->id, 'operation_reference' => 'C98-SECOND-TRANSFER', 'transferred' => true, 'expected_revision' => $quote['revision'], 'request_key' => (string) Str::uuid()])
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($close->fresh()->liquidated_cents)->toBe(5000)->and($close->fresh()->realTotal())->toBe(2000000)->and($close->fresh()->difference_cents)->toBe(-2000);
    $this->assertDatabaseCount('cash_box_liquidations', 2);
    $this->assertDatabaseCount('economic_incidents', 1);
    Livewire::test(ListCashBoxCloses::class)->mountTableAction('detail', $close->fresh())->assertMountedActionModalSee('Reposiciones reales')->assertMountedActionModalSee('Aporte completo posterior');
});

it('C98 does not charge a new cashier for inherited capital deficiency', function (bool $liquidates) {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    $session->update(['operating_capital_cents' => 3000000]);
    $data = [...c80Confirmation($user, $session), 'period' => $liquidates ? 'tarde' : 'manana', 'liquidate' => $liquidates, 'amount_cents' => 0,
        'balances' => ['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000']];
    if ($liquidates) {
        $data['restoration_token'] = app(ClosingRestoration::class)->authorize($user, $session->id, $data,
            ['amount' => '10000', 'component' => 'bcp', 'receipt' => 'Restauracion de capital heredado', 'received' => true], $user->id, '');
    }
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->difference_cents)->toBe(0)->and($close->realTotal())->toBe($liquidates ? 3000000 : 2000000);
    $this->assertDatabaseCount('economic_incidents', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
})->with([true, false]);

it('C98 preserves a declared bank reference on modal return and blocks a changed quote without another transfer', function () {
    [$user, $box, $session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    c80Confirmation($user, $session);
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'tarde')->call('nextStep')
        ->set('balances.bcp', '15050')->set('balances.efectivo', '5000')->call('nextStep')->set('readings.general', '100')->set('readings.fixed', '50')->call('nextStep')
        ->call('openLiquidationPreview')->set('operationReference', 'DECLARADA-NO-REPETIR')->set('transferred', true)->call('dismissLiquidationPreview')
        ->call('openLiquidationPreview')->assertSet('operationReference', 'DECLARADA-NO-REPETIR')->assertSet('transferred', true)->assertSet('liquidationReady', true)
        ->call('dismissLiquidationPreview')->set('balances.bcp', '15060')->call('openLiquidationPreview')->assertSet('liquidationReady', false)->call('confirmClosing')->assertHasErrors(['closing']);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('C99 routes the cashiers repayment report to the destination configured for the closed cash box without modifying its funds', function () {
    [$user, $box, $session] = c80OpenFixture(destinationName: 'Destino propio C99');
    config(['cajas.schedule_controls_enabled' => false]);
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $data = [...c80Confirmation($user, $session), 'balances' => ['bcp' => '15030', 'efectivo' => '5000'], 'amount_cents' => 3000, 'reason' => 'Faltante de veinte bolivianos'];
    $data['account_id'] = app(ClosingLiquidationPreviewQuery::class)->execute($user, $session->id, 'tarde', $data['balances'])['account']->id;
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    $before = $session->fresh()->getAttributes();
    $report = app(ReportOwnRepaymentAction::class)->execute($user, $close->economic_incident_id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'C99-REPAYMENT']);
    expect($report->amount_cents)->toBe(2000)->and($report->destination)->toBe('80000000')->and($report->confirmed_at)->toBeNull();
    expect($session->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('cash_box_liquidations', 1);
});

function c101HistoricalFixture(): array
{
    config(['cajas.schedule_controls_enabled' => false]);
    $sessions = [];
    foreach (['14990', '14920'] as $balance) {
        [$user, $box, $session] = c80OpenFixture();
        $data = [...c80Confirmation($user, $session), 'period' => 'manana', 'liquidate' => false, 'amount_cents' => 0,
            'balances' => ['bcp' => $balance, 'efectivo' => '2000', 'yape' => '3000'], 'reason' => 'Faltante histórico ficticio C101'];
        $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
        DB::table('economic_incidents')->where('id', $close->economic_incident_id)->update(['status' => 'DETECTADA', 'pending_cents' => 0]);
        $sessions[] = $session;
    }

    return $sessions;
}

it('C101 activates only the two authorized historical shortages once preserving all cash box data', function () {
    $sessions = c101HistoricalFixture();
    $before = collect(['cash_boxes', 'cash_box_sessions', 'cash_box_closes', 'cash_box_liquidations', 'cash_box_operations'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
    $owner = User::findOrFail($sessions[0]->user_id);
    $other = app(ReportEconomicIncidentAction::class)->execute($owner, $owner, ['source_module' => 'manual', 'source_reference' => 'C101-unrelated', 'component' => 'total', 'amount_cents' => 500, 'reason' => 'Caso ajeno al alcance', 'occurred_at' => now()->toDateTimeString()]);
    $otherBefore = $other->fresh()->getAttributes();
    $action = app(ActivateAuthorizedHistoricalShortagesAction::class);
    expect($action->execute(str_repeat('a', 64)))->toBe(2);
    expect(DB::table('economic_incidents')->sum('pending_cents'))->toBe(9000);
    expect($other->fresh()->getAttributes())->toBe($otherBefore);
    expect($action->execute(str_repeat('a', 64)))->toBe(0);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
    expect(DB::table('economic_history_events')->where('action', 'FALTANTE_ACTIVADO_C101')->count())->toBe(2);
    DB::table('economic_incidents')->where('id', 1)->update(['pending_cents' => 500, 'status' => 'PAGO_PARCIAL']);
    expect($action->execute(str_repeat('a', 64)))->toBe(0);
    expect(DB::table('economic_incidents')->where('id', 1)->value('pending_cents'))->toBe(500);
});

it('C101 rejects a changed historical case atomically', function (string $change) {
    c101HistoricalFixture();
    match ($change) {
        'amount' => DB::table('economic_incidents')->where('id', 2)->update(['amount_cents' => 7999]),
        'pending' => DB::table('economic_incidents')->where('id', 2)->update(['pending_cents' => 1]),
        'cancelled' => DB::table('cash_box_closes')->where('economic_incident_id', 2)->update(['cancelled_at' => now()]),
        'owner' => DB::table('economic_incidents')->where('id', 2)->update(['user_id' => 1]),
    };
    expect(fn () => app(ActivateAuthorizedHistoricalShortagesAction::class)->execute(str_repeat('a', 64)))->toThrow(ValidationException::class);
    expect(DB::table('economic_incidents')->where('id', 1)->value('status'))->toBe('DETECTADA');
    expect(DB::table('economic_history_events')->where('action', 'FALTANTE_ACTIVADO_C101')->count())->toBe(0);
})->with(['amount', 'pending', 'cancelled', 'owner']);

it('C101 rolls both activations back if audit recording is interrupted', function () {
    c101HistoricalFixture();
    $this->mock(EconomicHistoryAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Interrupción ficticia C101'));
    expect(fn () => app(ActivateAuthorizedHistoricalShortagesAction::class)->execute(str_repeat('a', 64)))->toThrow(RuntimeException::class);
    expect(DB::table('economic_incidents')->sum('pending_cents'))->toBe(0);
});

it('C101 does not change anything when the command is a preview or lacks a verified backup', function () {
    c101HistoricalFixture();
    $this->artisan('incidencias:activate-authorized-c101')->assertSuccessful();
    $this->artisan('incidencias:activate-authorized-c101', ['--apply' => true, '--backup' => 'C:/no-c101-backup.sqlite'])->assertFailed();
    expect(DB::table('economic_incidents')->sum('pending_cents'))->toBe(0);
    expect(DB::table('economic_history_events')->where('action', 'FALTANTE_ACTIVADO_C101')->count())->toBe(0);
});

it('C102 identifies an own shift closing shortage and its recorded shift without modifying debt', function () {
    $sessions = c101HistoricalFixture();
    $owner = User::findOrFail($sessions[0]->user_id);
    $case = EconomicIncident::findOrFail(1);
    $before = $case->getAttributes();
    $this->actingAs($owner);
    Livewire::test(OwnEconomicHistoryWidget::class)->assertSee('Cierre de turno');
    $detail = app(OwnIncidentDetailQuery::class)->execute($owner, 1);
    expect($detail['origin']['label'])->toBe('Cierre de turno')->and($detail['origin']['shift'])->toBe('Mañana')->and($detail['origin']['detected_by'])->toBe($owner->name);
    expect($case->fresh()->getAttributes())->toBe($before);
});

function c103PendingClose(): array
{
    [$owner,$box,$session] = c80OpenFixture();
    config(['cajas.schedule_controls_enabled' => false]);
    app(RecordQuickCashBoxCommissionAction::class)->execute($owner, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $data = [...c80Confirmation($owner, $session), 'balances' => ['bcp' => '15030', 'efectivo' => '5000'], 'amount_cents' => 3000, 'reason' => 'Faltante ficticio C103 de veinte bolivianos'];
    $data['account_id'] = app(ClosingLiquidationPreviewQuery::class)->execute($owner, $session->id, 'tarde', $data['balances'])['account']->id;

    return [$owner, $session, app(ConfirmCashBoxClosingAction::class)->execute($owner, $session->id, $data)];
}

it('C103 hides self confirmation for other owners and accepts an optional reason while retaining their debt', function (string $role) {
    [$owner,$session,$close] = c103PendingClose();
    if ($role === 'cajera') {
        $owner->syncRoles(['cajera']);
    }
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $case = EconomicIncident::findOrFail($close->economic_incident_id);
    $before = $case->getAttributes();
    $balances = $session->fresh()->current_balances;
    $this->actingAs($admin);
    Livewire::test(ListCashBoxCloses::class)->mountTableAction('approve', $close)->assertFormFieldIsHidden('exception')
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($close->fresh()->review_reason)->not->toBeEmpty()->and($close->fresh()->reviewed_by)->toBe($admin->id);
    expect($session->fresh()->status)->toBe('CERRADA_CON_INCIDENCIAS')->and($session->fresh()->current_balances)->toBe($balances);
    expect($case->fresh()->getAttributes())->toBe($before);
})->with(['cajera', 'administrador']);

it('C103 keeps explicit self confirmation for the sole administrator with an optional reason and idempotent retry', function () {
    [$owner,$session,$close] = c103PendingClose();
    $this->actingAs($owner);
    Livewire::test(ListCashBoxCloses::class)->mountTableAction('approve', $close)->assertFormFieldIsVisible('exception');
    $action = app(ApproveRegisteredCashBoxCloseAction::class);
    expect(fn () => $action->execute($owner, $close->id, ['request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect($session->fresh()->status)->toBe('CIERRE_REGISTRADO');
    $data = ['exception' => true, 'request_key' => (string) Str::uuid()];
    $action->execute($owner, $close->id, $data);
    $action->execute($owner, $close->id, $data);
    expect($close->fresh()->review_reason)->not->toBeEmpty()->and($session->fresh()->status)->toBe('CERRADA_CON_INCIDENCIAS');
    expect(DB::table('economic_incidents')->where('id', $close->economic_incident_id)->value('pending_cents'))->toBe(2000);
    expect(DB::table('cash_box_audits')->where('action', 'CIERRE_APROBADO')->count())->toBe(1);
});

it('C103 still requires another administrator when the owner has an active administrative peer', function () {
    [$owner,$session,$close] = c103PendingClose();
    $peer = User::factory()->create();
    $peer->assignRole('administrador');
    expect(fn () => app(ApproveRegisteredCashBoxCloseAction::class)->execute($owner, $close->id, ['exception' => true, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect($session->fresh()->status)->toBe('CIERRE_REGISTRADO');
});

it('C103 rejects a cashier attempting approval despite sending a self confirmation flag', function () {
    [$owner,$session,$close] = c103PendingClose();
    $owner->syncRoles(['cajera']);
    expect(fn () => app(ApproveRegisteredCashBoxCloseAction::class)->execute($owner, $close->id, ['exception' => true, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect($session->fresh()->status)->toBe('CIERRE_REGISTRADO');
});

it('C103 submits the own closing form without a reason when its exceptional checkbox is accepted', function () {
    [$owner,$session,$close] = c103PendingClose();
    $this->actingAs($owner);
    Livewire::test(ListCashBoxCloses::class)->callTableAction('approve', $close, data: ['exception' => true, 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors();
    expect($session->fresh()->status)->toBe('CERRADA_CON_INCIDENCIAS')->and($close->fresh()->review_reason)->not->toBeEmpty();
});

it('C104 reflects a real pending closing and its authorization without changing the full shortage', function () {
    [$owner, $session, $close] = c103PendingClose();
    $owner->syncRoles(['cajera']);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $query = app(PanelInboxQuery::class);
    $key = 'close-approval:'.$close->id;
    expect($query->execute($admin)->where('id', $key)->count())->toBe(1);
    app(ReadPanelNoticeAction::class)->execute($admin, $key);
    expect($session->fresh()->status)->toBe('CIERRE_REGISTRADO');
    app(ApproveRegisteredCashBoxCloseAction::class)->execute($admin, $close->id, ['request_key' => (string) Str::uuid()]);
    expect($query->execute($admin)->where('id', $key)->count())->toBe(0)
        ->and($query->execute($owner)->where('id', 'close-response:'.$close->id)->count())->toBe(1)
        ->and(DB::table('economic_incidents')->where('id', $close->economic_incident_id)->value('pending_cents'))->toBe(2000);
});

it('C116 records only an effective authorized early departure and retries a failed time evaluation without undoing closing', function () {
    [$user, $box, $session] = c80OpenFixture();
    $day = ScheduledWorkday::create(['user_id' => $user->id, 'created_by' => $user->id, 'work_date' => '2026-10-20',
        'period' => 'manana', 'starts_at' => now('UTC'), 'ends_at' => now('UTC')->addMinute()]);
    DB::table('personnel_rule_versions')->insert(['created_by' => $user->id, 'request_key' => (string) Str::uuid(), 'reason' => 'QA un minuto',
        'values' => json_encode(['morning_closing_minutes' => 0]), 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    app(PersonnelRules::class)->forget();
    $session->update(['scheduled_workday_id' => $day->id]);
    $request = app(RequestCashBoxEarlyCloseAction::class)->execute($user, $session->id, ['reason' => 'Urgencia QA', 'request_key' => (string) Str::uuid()]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    app(ReviewCashBoxEarlyCloseAction::class)->execute($admin, $request->id, ['decision' => 'APROBADA', 'reason' => 'Permiso un minuto', 'request_key' => (string) Str::uuid()]);
    $this->assertDatabaseCount('time_movements', 0);
    $data = [...c80Confirmation($user, $session), 'period' => 'manana', 'liquidate' => false, 'balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'amount_cents' => 0, 'transferred' => false];
    $audit = app(TimeHistoryAudit::class);
    $this->mock(TimeHistoryAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('QA interrupted time audit'));
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($session->fresh()->status)->toBe('CERRADA');
    $this->assertDatabaseCount('cash_box_closes', 1);
    $this->assertDatabaseCount('time_incidents', 0);
    $event = CashBoxScheduleEvent::where('source_key', 'closing:'.$close->id)->sole();
    expect($event->status)->toBe('FAILED');
    app()->instance(TimeHistoryAudit::class, $audit);
    $processor = app(ProcessCashBoxScheduleEventAction::class);
    $processor->execute($event->id);
    $processor->execute($event->id);
    expect($event->fresh()->status)->toBe('DONE');
    $this->assertDatabaseHas('time_incidents', ['source_key' => 'early:'.$day->id, 'pending_minutes' => 1]);
    $this->assertDatabaseCount('time_movements', 1);
    expect(app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data)->id)->toBe($close->id);
});

it('C116 cancels a one minute closing without saving and can then close normally at its exact limit', function () {
    [$user, $box, $session] = c80OpenFixture();
    $day = ScheduledWorkday::create(['user_id' => $user->id, 'created_by' => $user->id, 'work_date' => '2026-10-20',
        'period' => 'manana', 'starts_at' => now('UTC'), 'ends_at' => now('UTC')->addMinute()]);
    DB::table('personnel_rule_versions')->insert(['created_by' => $user->id, 'request_key' => (string) Str::uuid(), 'reason' => 'QA cierre un minuto',
        'values' => json_encode(['morning_closing_minutes' => 0]), 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    app(PersonnelRules::class)->forget();
    $session->update(['scheduled_workday_id' => $day->id]);
    $this->travel(1)->minutes();
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'manana')->call('nextStep')
        ->set('balances.efectivo', '2000')->call('cancelClosing')->assertRedirect(CashBoxOperations::getUrl(['cashBox' => $box->id]));
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
    expect($session->fresh()->status)->toBe('ABIERTA');
    $data = [...c80Confirmation($user, $session), 'period' => 'manana', 'liquidate' => false,
        'balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'amount_cents' => 0, 'transferred' => false];
    $close = app(ConfirmCashBoxClosingAction::class)->execute($user, $session->id, $data);
    expect($close->realTotal())->toBe(2000000)->and($session->fresh()->status)->toBe('CERRADA');
    $this->assertDatabaseCount('cash_box_early_requests', 0);
    $this->assertDatabaseCount('time_incidents', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('C125 filters shared closing history by cashier and inclusive Bolivian dates without changing role privacy', function (string $role) {
    [$admin, $box, $session] = c80OpenFixture();
    $this->travelTo(now('America/La_Paz')->setTime(20, 0));
    $close = app(ConfirmCashBoxClosingAction::class)->execute($admin, $session->id, c80Confirmation($admin, $session));
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $records = collect();
    foreach (['2026-10-20 03:59:59', '2026-10-20 04:00:00', '2026-10-21 03:59:59', '2026-10-21 04:00:00'] as $stamp) {
        $copy = $close->replicate();
        $copy->forceFill(['active_slot' => null, 'cancelled_at' => now(), 'created_at' => $stamp])->save();
        $records->push($copy);
    }
    $foreign = $close->replicate();
    $foreign->forceFill(['active_slot' => null, 'cancelled_at' => now(), 'user_id' => $other->id, 'created_at' => '2026-10-20 12:00:00'])->save();
    $actor = $role === 'administrador' ? $admin : $other;
    $this->actingAs($actor);

    $page = Livewire::test(ListCashBoxCloses::class)
        ->filterTable('user_id', $admin->id)
        ->filterTable('dates', ['from' => '2026-10-20', 'until' => '2026-10-20'])
        ->assertCanSeeTableRecords([$close, $records[1], $records[2]])
        ->assertCanNotSeeTableRecords([$records[0], $records[3], $foreign]);
    $page->filterTable('user_id', $other->id)->assertCanSeeTableRecords([$foreign])->assertCanNotSeeTableRecords([$close]);
    $page->filterTable('user_id', $admin->id)->filterTable('dates', ['from' => '2026-10-21', 'until' => null])
        ->assertCanSeeTableRecords([$records[3]])->assertCanNotSeeTableRecords([$records[2]]);
    $page->filterTable('dates', ['from' => null, 'until' => '2026-10-19'])
        ->assertCanSeeTableRecords([$records[0]])->assertCanNotSeeTableRecords([$records[1]]);
    $query = app(ClosingHistoryQuery::class);
    expect(fn () => $query->dates($query->forUser($actor), ['from' => '2026-02-30']))->toThrow(ValidationException::class);
    expect(fn () => $query->dates($query->forUser($actor), ['from' => '2026-10-21', 'until' => '2026-10-20']))->toThrow(ValidationException::class);

    $page->resetTableFilters()->call('gotoPage', 2)->assertCanSeeTableRecords([$close]);
    if ($role === 'cajera') {
        $page->assertTableColumnHidden('liquidated_cents');
        $record = $page->instance()->getTableRecords()->first();
        expect(array_key_exists('commission_cents', $record->getAttributes()))->toBeFalse();
    } else {
        $page->assertTableColumnVisible('liquidated_cents');
    }
})->with(['administrador', 'cajera']);
