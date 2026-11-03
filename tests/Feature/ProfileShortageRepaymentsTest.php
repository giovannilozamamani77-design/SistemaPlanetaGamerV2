<?php

use App\Domain\Cajas\Actions\SetCashBoxCommissionAccountAction;
use App\Domain\Incidencias\Actions\AppealEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ConfirmGlobalRecoveryAction;
use App\Domain\Incidencias\Actions\ConfirmRecoveryAction;
use App\Domain\Incidencias\Actions\DeclareGlobalRecoveryAction;
use App\Domain\Incidencias\Actions\RejectGlobalRecoveryAction;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReportOwnGlobalRepaymentAction;
use App\Domain\Incidencias\Actions\ReportOwnRepaymentAction;
use App\Domain\Incidencias\Actions\ReviewEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReviewIncidentAppealAction;
use App\Domain\Incidencias\Actions\ReviewRepaymentReportAction;
use App\Domain\Incidencias\Actions\SaveRepaymentSettingsAction;
use App\Domain\Incidencias\Data\IncidentOrigin;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Incidencias\Queries\AdministrativeEconomicOverviewQuery;
use App\Domain\Incidencias\Queries\OwnIncidentDetailQuery;
use App\Domain\Incidencias\Queries\PendingIncidentAppealsQuery;
use App\Domain\Incidencias\Queries\PendingRepaymentReportsQuery;
use App\Domain\Incidencias\Queries\PersonnelBalancesQuery;
use App\Domain\Incidencias\Queries\RepaymentDestinationQuery;
use App\Domain\Incidencias\Queries\RepaymentPanelQuery;
use App\Domain\Incidencias\Queries\RepaymentSettingsQuery;
use App\Domain\Notificaciones\Queries\PanelInboxQuery;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Filament\Resources\Incidencias\GlobalRecoveries\GlobalRecoveryResource;
use App\Filament\Resources\Incidencias\GlobalRecoveries\Pages\ManageGlobalRecoveries;
use App\Filament\Widgets\Personal\PendingIncidentAppealsWidget;
use App\Filament\Widgets\Personal\PendingRepaymentReportsWidget;
use App\Filament\Widgets\Usuarios\OwnEconomicHistoryWidget;
use App\Filament\Widgets\Usuarios\OwnGlobalRecoveriesWidget;
use App\Filament\Widgets\Usuarios\OwnPersonnelSummaryWidget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c99Fixture(bool $confirmed = true): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, CajasPermissionsSeeder::class]);
    $admin = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $other = User::factory()->create();
    $other->assignRole('cajera');
    app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['destination' => '70000999', 'holder_name' => 'Destino ficticio C99', 'pin' => '1234', 'reason' => 'Cuenta de prueba aislada', 'request_key' => (string) Str::uuid()]);
    $case = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'cajas', 'source_reference' => 'close:test-C99', 'component' => 'total', 'box_label' => 'Caja C99 aislada', 'amount_cents' => 6000, 'reason' => 'Faltante original de cierre', 'occurred_at' => now()->toDateTimeString()]);
    if (! $confirmed) {
        $case->update(['status' => 'DETECTADA', 'pending_cents' => 0]); // Legacy record before C100.
    }
    if ($confirmed) {
        app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Responsabilidad revisada');
    }
    Filament::setCurrentPanel(Filament::getPanel('inicio'));

    return [$admin, $cashier, $other, $case->fresh()];
}

it('C99 puts shortage history second and reports a repayment using only a transaction ID without reducing debt', function () {
    [$admin, $cashier, $other, $case] = c99Fixture();
    $this->actingAs($cashier)->get(EditOwnProfile::getUrl())->assertOk()->assertSeeInOrder(['Datos personales', 'Faltantes y reposiciones', 'Asistencia']);
    Livewire::test(OwnEconomicHistoryWidget::class)->callTableAction('declararAporte', $case, data: ['request_key' => (string) Str::uuid(), 'operation_reference' => 'C99-YAPE-001'])->assertHasNoTableActionErrors();
    $payment = RecoveryPayment::firstOrFail();
    expect($payment->amount_cents)->toBe(6000)->and($payment->destination)->toBe('70000999')->and($payment->method)->toBe('QR')->and($payment->confirmed_at)->toBeNull();
    expect($case->fresh()->pending_cents)->toBe(6000);
});

it('C99 lets a closing shortage be explained and reported before responsibility confirmation', function () {
    [$admin, $cashier, $other, $case] = c99Fixture(false);
    $data = ['request_key' => (string) Str::uuid(), 'text' => 'Solicito revisar: posible error del registro'];
    app(AppealEconomicIncidentAction::class)->execute($cashier, $case->id, $data);
    app(AppealEconomicIncidentAction::class)->execute($cashier, $case->id, $data);
    expect($case->fresh()->amount_cents)->toBe(6000)->and($case->fresh()->pending_cents)->toBe(0)->and($case->fresh()->receipt_justification)->toBe($data['text']);
    $payment = app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'C99-PENDING']);
    expect($payment->confirmed_at)->toBeNull()->and($payment->amount_cents)->toBe(6000);
    app(ReviewRepaymentReportAction::class)->execute($admin, $payment->id, 'CONFIRMAR', 'Deposito ID verificado en destino', null, false, true);
    expect($case->fresh()->status)->toBe('REPUESTA')->and($case->fresh()->pending_cents)->toBe(0);
    $this->assertDatabaseCount('recovery_payments', 1);
});

it('C99 does not let another cashier appeal declare or confirm the owners shortage', function () {
    [$admin, $cashier, $other, $case] = c99Fixture();
    expect(fn () => app(AppealEconomicIncidentAction::class)->execute($other, $case->id, ['text' => 'Dato ajeno', 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect(fn () => app(ReportOwnRepaymentAction::class)->execute($other, $case->id, ['operation_reference' => 'AJENO', 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    $payment = app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['operation_reference' => 'PROPIO', 'request_key' => (string) Str::uuid()]);
    expect(fn () => app(ReviewRepaymentReportAction::class)->execute($cashier, $payment->id, 'CONFIRMAR', 'No puede verificar su pago'))->toThrow(AuthorizationException::class);
    expect($case->fresh()->pending_cents)->toBe(6000);
});

it('C99 keeps rejected reports and permits a corrected transaction without paying twice', function () {
    [$admin, $cashier, $other, $case] = c99Fixture();
    $action = app(ReportOwnRepaymentAction::class);
    $data = ['operation_reference' => ' ERR-001 ', 'request_key' => (string) Str::uuid()];
    $first = $action->execute($cashier, $case->id, $data);
    expect($action->execute($cashier, $case->id, $data)->id)->toBe($first->id);
    app(ReviewRepaymentReportAction::class)->execute($admin, $first->id, 'RECHAZAR', 'No existe este ID de deposito');
    expect($first->fresh()->rejected_at)->not->toBeNull()->and($case->fresh()->pending_cents)->toBe(6000);
    $second = $action->execute($cashier, $case->id, ['operation_reference' => 'OK-002', 'request_key' => (string) Str::uuid()]);
    $review = app(ReviewRepaymentReportAction::class);
    $review->execute($admin, $second->id, 'CONFIRMAR', 'Ingreso real verificado', null, false, true);
    $review->execute($admin, $second->id, 'CONFIRMAR', 'Ingreso real verificado', null, false, true);
    expect($case->fresh()->pending_cents)->toBe(0)->and($case->fresh()->status)->toBe('REPUESTA');
    $this->assertDatabaseCount('recovery_payments', 2);
});

it('C99 shows the accumulated confirmed balance and only five latest rows while keeping older records reachable', function () {
    [$admin, $cashier, $other, $case] = c99Fixture();
    $cases = [$case];
    for ($i = 1; $i <= 6; $i++) {
        $entry = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => 'C99-page-'.$i, 'component' => 'efectivo', 'amount_cents' => 1000, 'reason' => 'Caso paginado '.$i, 'occurred_at' => now()->toDateTimeString()]);
        app(ReviewEconomicIncidentAction::class)->execute($admin, $entry, 'CONFIRMAR', 'Responsabilidad verificada');
        $cases[] = $entry;
    }
    $this->actingAs($cashier);
    Livewire::test(OwnPersonnelSummaryWidget::class, ['section' => 'primary'])->assertSee('Faltante acumulado pendiente')->assertSee('Bs 120.00');
    $page = Livewire::test(OwnEconomicHistoryWidget::class)->assertCanSeeTableRecords(array_slice(array_reverse($cases), 0, 5))->assertCanNotSeeTableRecords([$cases[0]]);
    $page->call('gotoPage', 2)->assertCanSeeTableRecords([$cases[0], $cases[1]]);
    expect(app(PersonnelBalancesQuery::class)->execute($cashier)['pending'])->toBe(12000);
});

it('C99 verifies or rejects reports from the actual administrative queue and never lets a cashier load it', function () {
    [$admin, $cashier, $other, $case] = c99Fixture(false);
    $payment = app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'UI-REVIEW-C99']);
    $this->actingAs($admin);
    Livewire::test(PendingRepaymentReportsWidget::class)->assertCanSeeTableRecords([$payment])
        ->callTableAction('revisarReporte', $payment, data: ['decision' => 'CONFIRMAR', 'received' => true, 'reason' => 'ID verificado y responsabilidad revisada', 'exception' => false])->assertHasNoTableActionErrors();
    expect($case->fresh()->status)->toBe('REPUESTA');
    $this->actingAs($cashier);
    expect(fn () => app(PendingRepaymentReportsQuery::class)->execute($cashier))->toThrow(AuthorizationException::class);
});

it('C99 rolls back responsibility confirmation if the report can no longer be applied', function () {
    [$admin, $cashier, $other, $case] = c99Fixture(false);
    $payment = app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'STALE-AMOUNT-C99']);
    $case->update(['amount_cents' => 3000]);
    expect(fn () => app(ReviewRepaymentReportAction::class)->execute($admin, $payment->id, 'CONFIRMAR', 'Verificacion con importe desfasado', null, false, true))->toThrow(ValidationException::class);
    expect($case->fresh()->status)->toBe('DETECTADA')->and($case->fresh()->pending_cents)->toBe(0)->and($payment->fresh()->confirmed_at)->toBeNull();
});

it('C99 rejects empty references reused requests other cases and cross ledger duplicates', function () {
    [$admin, $cashier, $other, $case] = c99Fixture();
    $action = app(ReportOwnRepaymentAction::class);
    expect(fn () => $action->execute($cashier, $case->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => '   ']))->toThrow(ValidationException::class);
    $data = ['request_key' => (string) Str::uuid(), 'operation_reference' => 'bank-c99'];
    $first = $action->execute($cashier, $case->id, $data);
    expect($first->operation_reference)->toBe('BANK-C99');
    expect($action->execute($cashier, $case->id, [...$data, 'request_key' => (string) Str::uuid(), 'operation_reference' => ' BANK-C99 '])->id)->toBe($first->id);
    expect(fn () => $action->execute($cashier, $case->id, [...$data, 'operation_reference' => 'DIFFERENT']))->toThrow(ValidationException::class);
    $second = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => 'C99-other-case', 'component' => 'efectivo', 'amount_cents' => 6000, 'reason' => 'Otro faltante', 'occurred_at' => now()->toDateTimeString()]);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $second, 'CONFIRMAR', 'Responsabilidad revisada');
    expect(fn () => $action->execute($cashier, $second->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'BANK-C99']))->toThrow(ValidationException::class);
    app(ReviewRepaymentReportAction::class)->execute($admin, $first->id, 'RECHAZAR', 'ID ficticio no recibido');
    app(DeclareGlobalRecoveryAction::class)->execute($cashier, $cashier, ['request_key' => (string) Str::uuid(), 'method' => 'QR', 'amount_cents' => 1000, 'note' => 'Pago global de prueba', 'destination' => '70000999', 'operation_reference' => 'GLOBAL-C99']);
    expect(fn () => $action->execute($cashier, $second->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'global-c99']))->toThrow(ValidationException::class);
    expect($case->fresh()->pending_cents)->toBe(6000);
});

it('C99 does not count unconfirmed responsibility or reports as recovered money', function () {
    [$admin, $cashier, $other, $case] = c99Fixture(false);
    app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'UNVERIFIED-C99']);
    $summary = app(PersonnelBalancesQuery::class)->execute($cashier);
    expect($summary['pending'])->toBe(0)->and($summary['under_review'])->toBe(6000)->and($summary['received'])->toBe(0);
});

it('C99 requires verification and second responsibility check before settling a large unconfirmed case', function () {
    [$admin, $cashier, $other, $case] = c99Fixture(false);
    $case->update(['amount_cents' => 20000]);
    $payment = app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'LARGE-C99']);
    $review = app(ReviewRepaymentReportAction::class);
    expect(fn () => $review->execute($admin, $payment->id, 'CONFIRMAR', 'Sin verificacion'))->toThrow(ValidationException::class);
    expect(fn () => $review->execute($admin, $payment->id, 'CONFIRMAR', 'Ingreso verificado', null, false, true))->toThrow(ValidationException::class);
    expect($case->fresh()->status)->toBe('DETECTADA');
    $review->execute($admin, $payment->id, 'CONFIRMAR', 'Ingreso y responsabilidad verificados', 20000, false, true);
    expect($case->fresh()->status)->toBe('REPUESTA');
});

it('C99 explains a missing destination without crashing the report dialog and does not accept a fictitious account', function () {
    [$admin, $cashier, $other, $case] = c99Fixture();
    DB::table('cash_box_commission_accounts')->update(['is_demo' => true]);
    $this->actingAs($cashier);
    Livewire::test(OwnEconomicHistoryWidget::class)->mountTableAction('declararAporte', $case)
        ->assertMountedActionModalSee('Administración debe configurar la cuenta de comisiones');
    expect(fn () => app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'NO-DESTINATION']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('recovery_payments', 0);
});

it('C99 shows the rejection response beside the case and never confirms a rejected report through the older action', function () {
    [$admin, $cashier, $other, $case] = c99Fixture();
    $payment = app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['request_key' => (string) Str::uuid(), 'operation_reference' => 'WRONG-C99']);
    app(ReviewRepaymentReportAction::class)->execute($admin, $payment->id, 'RECHAZAR', 'El ID no coincide con el deposito');
    expect(fn () => app(ConfirmRecoveryAction::class)->execute($admin, $payment->fresh(), true, 'No debe aplicar'))->toThrow(ValidationException::class);
    $this->actingAs($cashier);
    Livewire::test(OwnEconomicHistoryWidget::class)->assertSee('El ID no coincide con el deposito')->assertTableActionVisible('declararAporte', $case);
    expect($case->fresh()->pending_cents)->toBe(6000);
});

it('C100 activates a closing shortage immediately without administrative confirmation', function () {
    [$admin, $cashier, $other, $case] = c99Fixture(false);
    $new = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'cajas', 'source_reference' => 'close:C100-new', 'component' => 'total', 'amount_cents' => 6000, 'reason' => 'Nuevo faltante de caja', 'occurred_at' => now()->toDateTimeString()]);
    expect($new->status)->toBe('CONFIRMADA')->and($new->pending_cents)->toBe(6000);
});

it('C100 allows exactly one appeal including after rejection and preserves retry identity', function () {
    [$admin, $cashier, $other, $case] = c99Fixture();
    $data = ['text' => 'Solicito revisar este faltante', 'request_key' => (string) Str::uuid()];
    $action = app(AppealEconomicIncidentAction::class);
    $action->execute($cashier, $case->id, $data);
    $action->execute($cashier, $case->id, $data);
    expect(fn () => $action->execute($cashier, $case->id, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    app(ReviewIncidentAppealAction::class)->execute($admin, $case->id, 'RECHAZAR', 'Se conserva responsabilidad');
    expect($case->fresh()->pending_cents)->toBe(6000)->and($case->fresh()->policy_snapshot['appeal_decision'])->toBe('RECHAZAR');
    expect(fn () => $action->execute($cashier, $case->id, ['text' => 'Nueva explicación', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
});

it('C100 requires a fresh administrator PIN for cancellation and forgiveness with optional explanation', function () {
    [$admin, $cashier, $other, $case] = c99Fixture();
    $action = app(ReviewEconomicIncidentAction::class);
    expect(fn () => $action->execute($admin, $case, 'ANULAR', ''))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($admin, $case, 'ANULAR', '', null, false, '0000'))->toThrow(ValidationException::class);
    expect($case->fresh()->pending_cents)->toBe(6000);
    $action->execute($admin, $case, 'ANULAR', '', null, false, '1234');
    expect($case->fresh()->status)->toBe('ANULADA')->and($case->fresh()->pending_cents)->toBe(0);
    expect(fn () => $action->execute($admin, $case, 'ANULAR', ''))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('recovery_payments', 0);
});

it('C100 accepts one appeal with PIN and never invents a repayment', function () {
    [$admin,$cashier,$other,$case] = c99Fixture();
    app(AppealEconomicIncidentAction::class)->execute($cashier, $case->id, ['text' => 'No corresponde a mi turno', 'request_key' => (string) Str::uuid()]);
    $review = app(ReviewIncidentAppealAction::class);
    expect(fn () => $review->execute($admin, $case->id, 'ACEPTAR'))->toThrow(ValidationException::class);
    $review->execute($admin, $case->id, 'ACEPTAR', '', '1234');
    $review->execute($admin, $case->id, 'ACEPTAR', '', '1234');
    expect($case->fresh()->status)->toBe('ANULADA')->and($case->fresh()->pending_cents)->toBe(0)->and($case->fresh()->amount_cents)->toBe(6000);
    $this->assertDatabaseCount('recovery_payments', 0);
    $this->assertDatabaseCount('economic_history_events', 4);
});

it('C100 allows forgiveness without an appeal but with PIN and preserves amount', function () {
    [$admin,$cashier,$other,$case] = c99Fixture();
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONDONAR', '', null, false, '1234');
    expect($case->fresh()->status)->toBe('CONDONADA')->and($case->fresh()->forgiven_cents)->toBe(6000)->and($case->fresh()->amount_cents)->toBe(6000);
});

it('C100 exposes the appeal queue only to Administration and removes decided appeals', function () {
    [$admin,$cashier,$other,$case] = c99Fixture();
    app(AppealEconomicIncidentAction::class)->execute($cashier, $case->id, ['text' => 'Explicación para revisar', 'request_key' => (string) Str::uuid()]);
    $query = app(PendingIncidentAppealsQuery::class);
    expect(fn () => $query->execute($cashier))->toThrow(AuthorizationException::class);
    expect($query->execute($admin)->count())->toBe(1);
    $this->actingAs($admin);
    Livewire::test(PendingIncidentAppealsWidget::class)->callTableAction('revisarApelacion', $case, data: ['decision' => 'RECHAZAR', 'reason' => 'Faltante confirmado por conteo'])->assertHasNoTableActionErrors();
    expect($query->execute($admin)->count())->toBe(0)->and($case->fresh()->pending_cents)->toBe(6000);
    $this->actingAs($cashier);
    Livewire::test(OwnEconomicHistoryWidget::class)->assertSee('Apelación rechazada')->assertTableActionHidden('apelar', $case);
});

it('C100 versions an independent repayment destination without changing cash box configuration', function () {
    [$admin,$cashier,$other,$case] = c99Fixture();
    $before = Illuminate\Support\Facades\DB::table('cash_box_configurations')->get()->toJson();
    $action = app(SaveRepaymentSettingsAction::class);
    $accountId = app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['name' => 'Cuenta exclusiva de reposiciones', 'destination' => '70000100', 'holder_name' => 'Destino ficticio independiente', 'pin' => '1234', 'reason' => 'Configuración de prueba independiente', 'request_key' => (string) Str::uuid()]);
    $key = Illuminate\Support\Facades\DB::table('cash_box_commission_accounts')->where('id', $accountId)->value('account_key');
    $data = ['destination_key' => $key, 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    expect(fn () => $action->execute($cashier, $data))->toThrow(AuthorizationException::class);
    $action->execute($admin, $data);
    $action->execute($admin, $data);
    expect(app(RepaymentSettingsQuery::class)->execute($admin)['destination_key'])->toBe($key);
    expect(app(RepaymentDestinationQuery::class)->execute($cashier, $case->id)->destination)->toBe('70000100');
    expect(Illuminate\Support\Facades\DB::table('cash_box_configurations')->get()->toJson())->toBe($before);
    $this->actingAs($admin)->get(GlobalRecoveryResource::getUrl())->assertOk()->assertSee('Reposiciones de caja')->assertSee('Configuración');
});

it('C102 does not invent a closing or opening origin when the stored reference is incomplete', function () {
    [$admin,$cashier,$other,$case] = c99Fixture();
    $detail = app(OwnIncidentDetailQuery::class)->execute($cashier, $case->id);
    expect($detail['origin']['label'])->toBe('Origen de caja no detallado')->and($detail['origin']['detected_by'])->toBeNull()->and($detail['origin']['shift'])->toBeNull();
    $case->update(['source_module' => 'manual']);
    expect(IncidentOrigin::label($case))->toBe('Registro manual');
});

function c105Cases(): array
{
    [$admin, $cashier, $other, $first] = c99Fixture();
    $first->update(['amount_cents' => 3000, 'pending_cents' => 3000]);
    $second = app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'cajas', 'source_reference' => 'close:C105-second', 'component' => 'total', 'box_label' => 'Caja ficticia', 'amount_cents' => 7000, 'reason' => 'Segundo faltante', 'occurred_at' => now()->toDateTimeString()]);

    return [$admin, $cashier, $other, $first->fresh(), $second->fresh()];
}
it('C105 applies eighty to thirty and seventy once and individual repayment asks only for twenty', function () {
    [$admin, $cashier, $other, $first, $second] = c105Cases();
    $action = app(ReportOwnGlobalRepaymentAction::class);
    $data = ['mode' => 'partial', 'amount' => '80', 'operation_reference' => ' C105-80 ', 'request_key' => (string) Str::uuid()];
    $batch = $action->execute($cashier, $data);
    expect($action->execute($cashier, $data)->id)->toBe($batch->id)->and($first->fresh()->pending_cents)->toBe(3000);
    expect(fn () => app(ReportOwnRepaymentAction::class)->execute($cashier, $second->id, ['operation_reference' => 'DUPLICATE', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $confirm = app(ConfirmGlobalRecoveryAction::class);
    $confirm->execute($admin, $batch, true, 'Depósito ficticio verificado');
    $confirm->execute($admin, $batch, true, 'Depósito ficticio verificado');
    expect($first->fresh()->pending_cents)->toBe(0)->and($first->fresh()->status)->toBe('REPUESTA')->and($second->fresh()->pending_cents)->toBe(2000)->and($second->fresh()->status)->toBe('PAGO_PARCIAL');
    $payment = app(ReportOwnRepaymentAction::class)->execute($cashier, $second->id, ['operation_reference' => 'C105-LAST20', 'request_key' => (string) Str::uuid()]);
    expect($payment->amount_cents)->toBe(2000)->and(app(PersonnelBalancesQuery::class)->execute($cashier)['pending'])->toBe(2000);
});
it('C105 validates total partial amounts references and ownership on the server', function () {
    [$admin, $cashier, $other, $first, $second] = c105Cases();
    $action = app(ReportOwnGlobalRepaymentAction::class);
    foreach (['0', '-1', '100.01', '1.001'] as $amount) {
        expect(fn () => $action->execute($cashier, ['mode' => 'partial', 'amount' => $amount, 'operation_reference' => 'BAD', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    }
    expect(fn () => $action->execute($other, ['mode' => 'total', 'amount' => '100', 'operation_reference' => 'OTHER', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $batch = $action->execute($cashier, ['mode' => 'total', 'amount' => '100', 'operation_reference' => 'TOTAL105', 'request_key' => (string) Str::uuid()]);
    expect($batch->amount_cents)->toBe(10000)->and($batch->user_id)->toBe($cashier->id);
    expect(fn () => $action->execute($cashier, ['mode' => 'partial', 'amount' => '10', 'operation_reference' => 'SECOND', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
});
it('C105 shows total and partial buttons on the accumulated card and submits a partial report', function () {
    [$admin, $cashier, $other, $first, $second] = c105Cases();
    $this->actingAs($cashier);
    Livewire::test(OwnPersonnelSummaryWidget::class, ['section' => 'primary'])
        ->assertSee('Pagar totalidad')->assertSee('Pagar una cantidad')
        ->callAction('payPartial', data: ['amount' => '80', 'operation_reference' => 'UI-C105-80', 'request_key' => (string) Str::uuid()])->assertHasNoErrors()
        ->assertSee('por verificar');
    expect(GlobalRecovery::firstOrFail()->amount_cents)->toBe(8000)->and($first->fresh()->pending_cents)->toBe(3000);
});
it('C105 waits for individual verification before accepting a global report and reuses no transaction ID', function () {
    [$admin, $cashier, $other, $first, $second] = c105Cases();
    $individual = app(ReportOwnRepaymentAction::class)->execute($cashier, $first->id, ['operation_reference' => 'C105-INDIVIDUAL', 'request_key' => (string) Str::uuid()]);
    $action = app(ReportOwnGlobalRepaymentAction::class);
    expect(fn () => $action->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => 'GLOBAL-BLOCKED', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    app(ReviewRepaymentReportAction::class)->execute($admin, $individual->id, 'RECHAZAR', 'ID de prueba no recibido');
    expect(fn () => $action->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => ' c105-individual ', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $key = (string) Str::uuid();
    $action->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => 'GLOBAL-UNIQUE', 'request_key' => $key]);
    expect(fn () => $action->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => 'CHANGED-ID', 'request_key' => $key]))->toThrow(ValidationException::class);
    $this->actingAs($cashier);
    Livewire::test(OwnEconomicHistoryWidget::class)->assertTableActionHidden('declararAporte', $second);
});
it('C105 rolls back a stale global confirmation and rejects a changed total before reporting', function () {
    [$admin, $cashier, $other, $first, $second] = c105Cases();
    $action = app(ReportOwnGlobalRepaymentAction::class);
    expect(fn () => $action->execute($cashier, ['mode' => 'total', 'amount' => '90', 'operation_reference' => 'STALE-TOTAL', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $batch = $action->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => 'STALE-CONFIRM', 'request_key' => (string) Str::uuid()]);
    $first->update(['pending_cents' => 0, 'status' => 'REPUESTA']);
    expect(fn () => app(ConfirmGlobalRecoveryAction::class)->execute($admin, $batch, true, 'Intento sobre saldo cambiado'))->toThrow(ValidationException::class);
    expect($batch->fresh()->confirmed_at)->toBeNull()->and($second->fresh()->pending_cents)->toBe(7000)->and($batch->payments()->count())->toBe(0);
});
it('C105 confirms total repayment without deleting either original shortage', function () {
    [$admin, $cashier, $other, $first, $second] = c105Cases();
    $this->actingAs($cashier);
    Livewire::test(OwnPersonnelSummaryWidget::class, ['section' => 'primary'])->callAction('payTotal', data: ['amount' => '100', 'operation_reference' => 'UI-TOTAL105', 'request_key' => (string) Str::uuid()])->assertHasNoErrors();
    $batch = GlobalRecovery::firstOrFail();
    app(ConfirmGlobalRecoveryAction::class)->execute($admin, $batch, true, 'Ingreso total ficticio verificado');
    expect(app(PersonnelBalancesQuery::class)->execute($cashier)['pending'])->toBe(0)->and($first->fresh()->amount_cents)->toBe(3000)->and($second->fresh()->amount_cents)->toBe(7000)->and($batch->payments()->count())->toBe(2);
});

it('C106 rejects a global report once without paying debt and allows a fresh report from its owner', function () {
    [$admin, $cashier, $other, $first, $second] = c105Cases();
    $report = app(ReportOwnGlobalRepaymentAction::class);
    $old = $report->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => 'C106-WRONG', 'request_key' => (string) Str::uuid()]);
    $before = [$first->getAttributes(), $second->getAttributes()];
    $reject = app(RejectGlobalRecoveryAction::class);
    $reject->execute($admin, $old, 'ID no encontrado en la cuenta de destino');
    $reject->execute($admin, $old, 'ID no encontrado en la cuenta de destino');
    expect(app(AdministrativeEconomicOverviewQuery::class)->execute($admin, 'reposiciones')['queue'])->toBe(0);
    expect($old->fresh()->rejected_at)->not->toBeNull()->and($old->fresh()->rejected_by)->toBe($admin->id)->and($old->fresh()->confirmed_at)->toBeNull();
    expect([$first->fresh()->getAttributes(), $second->fresh()->getAttributes()])->toBe($before)->and($old->payments()->count())->toBe(0);
    expect(Illuminate\Support\Facades\DB::table('user_audits')->where('action', 'global_recovery_rejected')->count())->toBe(1);
    expect(fn () => app(ConfirmGlobalRecoveryAction::class)->execute($admin, $old, true, 'No debe confirmar un rechazo'))->toThrow(ValidationException::class);
    expect(fn () => $report->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => ' c106-wrong ', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $new = $report->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => 'C106-CORRECT', 'request_key' => (string) Str::uuid()]);
    expect($new->id)->not->toBe($old->id);
    app(ConfirmGlobalRecoveryAction::class)->execute($admin, $new, true, 'Ingreso válido verificado');
    expect($first->fresh()->pending_cents)->toBe(0)->and($second->fresh()->pending_cents)->toBe(2000)->and($old->fresh()->rejection_reason)->toBe('ID no encontrado en la cuenta de destino');
});
it('C106 protects rejection authorization validates the reason and never rejects an already confirmed payment', function () {
    [$admin, $cashier, $other, $first, $second] = c105Cases();
    $batch = app(ReportOwnGlobalRepaymentAction::class)->execute($cashier, ['mode' => 'total', 'amount' => '100', 'operation_reference' => 'C106-AUTH', 'request_key' => (string) Str::uuid()]);
    $action = app(RejectGlobalRecoveryAction::class);
    expect(fn () => $action->execute($cashier, $batch, 'Intento no autorizado'))->toThrow(AuthorizationException::class);
    expect(fn () => $action->execute($other, $batch, 'Intento ajeno'))->toThrow(AuthorizationException::class);
    expect(fn () => $action->execute($admin, $batch, '  '))->toThrow(ValidationException::class);
    app(ConfirmGlobalRecoveryAction::class)->execute($admin, $batch, true, 'Pago verificado');
    expect(fn () => $action->execute($admin, $batch, 'No se puede rechazar después'))->toThrow(ValidationException::class);
    expect($batch->fresh()->rejected_at)->toBeNull()->and($first->fresh()->pending_cents)->toBe(0);
});
it('C106 rejects through Administration and shows the reason and reporting options to the cashier', function () {
    [$admin,$cashier,$other,$first,$second] = c105Cases();
    $batch = app(ReportOwnGlobalRepaymentAction::class)->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => 'C106-UI', 'request_key' => (string) Str::uuid()]);
    $this->actingAs($admin);
    Livewire::test(ManageGlobalRecoveries::class)
        ->callTableAction('rechazar', $batch, data: ['reason' => 'El ID no coincide con ningún depósito', 'exception' => false])->assertHasNoTableActionErrors()
        ->assertTableActionHidden('recibir', $batch)->assertTableActionHidden('rechazar', $batch);
    $this->actingAs($cashier);
    Livewire::test(OwnGlobalRecoveriesWidget::class)->assertSee('Rechazada')->assertSee('El ID no coincide con ningún depósito');
    Livewire::test(OwnPersonnelSummaryWidget::class, ['section' => 'primary'])->assertSee('Pagar totalidad')->assertSee('Pagar una cantidad');
    Livewire::test(OwnEconomicHistoryWidget::class)->assertTableActionVisible('declararAporte', $second);
    $query = app(PanelInboxQuery::class);
    expect($query->execute($admin)->where('id', 'global-review:'.$batch->id)->count())->toBe(0)
        ->and($query->execute($cashier)->where('id', 'global-response:'.$batch->id)->count())->toBe(1)
        ->and($query->execute($other)->where('id', 'global-response:'.$batch->id)->count())->toBe(0);
});
it('C106 rolls back rejection if its audit cannot be saved and permits a safe retry', function () {
    [$admin,$cashier,$other,$first,$second] = c105Cases();
    $batch = app(ReportOwnGlobalRepaymentAction::class)->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => 'C106-ROLLBACK', 'request_key' => (string) Str::uuid()]);
    Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER c106_audit_failure BEFORE INSERT ON user_audits WHEN NEW.action = 'global_recovery_rejected' BEGIN SELECT RAISE(ABORT, 'C106 simulated interruption'); END;");
    $action = app(RejectGlobalRecoveryAction::class);
    expect(fn () => $action->execute($admin, $batch, 'ID inválido de prueba'))->toThrow(QueryException::class);
    expect($batch->fresh()->rejected_at)->toBeNull()->and($first->fresh()->pending_cents)->toBe(3000)->and($second->fresh()->pending_cents)->toBe(7000);
    Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER c106_audit_failure');
    $action->execute($admin, $batch, 'ID inválido de prueba');
    expect($batch->fresh()->rejected_at)->not->toBeNull();
});

it('C107 sums all active pending shortages and removes an annulled debt without double counting reports', function () {
    [$admin,$cashier,$other,$case] = c99Fixture();
    $case->update(['amount_cents' => 10000, 'pending_cents' => 10000]);
    $second = app(ReportEconomicIncidentAction::class)->execute($admin, $other, ['source_module' => 'cajas', 'source_reference' => 'close:C107', 'component' => 'total', 'amount_cents' => 5000, 'reason' => 'Otro faltante', 'occurred_at' => now()]);
    $query = app(RepaymentPanelQuery::class);
    expect($query->overview($admin)['pending_cents'])->toBe(15000);
    app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['operation_reference' => 'C107-PENDING', 'request_key' => (string) Str::uuid()]);
    expect($query->overview($admin, ['user_id' => $cashier->id])['pending_cents'])->toBe(15000);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $second, 'ANULAR', 'Error de registro', null, false, '1234');
    expect($query->overview($admin)['pending_cents'])->toBe(10000);
    expect(fn () => $query->overview($cashier))->toThrow(AuthorizationException::class);
});
it('C107 shares cashier filters across three independent queues and orders the panel sections', function () {
    [$admin,$cashier,$other,$case] = c99Fixture();
    $payment = app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['operation_reference' => 'C107-REPORT', 'request_key' => (string) Str::uuid()]);
    app(AppealEconomicIncidentAction::class)->execute($cashier, $case->id, ['text' => 'Revisar diferencia', 'request_key' => (string) Str::uuid()]);
    $this->actingAs($admin);
    $screen = Livewire::test(ManageGlobalRecoveries::class)
        ->assertSeeInOrder(['Reposiciones por verificar', 'Reposiciones globales', 'Apelaciones por revisar'])
        ->callAction('filtrarReposiciones', data: ['user_id' => $cashier->id])->assertHasNoActionErrors();
    expect($screen->instance()->panelFilters['user_id'])->toBe($cashier->id);
    $screen->call('selectPanelSection', 'global')->assertSet('panelSection', 'global');
    $screen->call('selectPanelSection', 'appeals')->assertSet('panelSection', 'appeals');
    $q = app(RepaymentPanelQuery::class);
    expect($q->query($admin, 'reports', ['user_id' => $other->id])->count())->toBe(0)
        ->and($q->query($admin, 'reports', ['user_id' => $cashier->id])->pluck('id')->all())->toBe([$payment->id])
        ->and($q->overview($admin)['appeals'])->toBe(1);
});
it('C107 has one compact filter set and keeps the full global history reachable', function () {
    [$admin,$cashier,$other,$case] = c99Fixture();
    $a = GlobalRecovery::create(['user_id' => $cashier->id, 'declared_by' => $cashier->id, 'method' => 'QR', 'amount_cents' => 100, 'note' => 'Historial ficticio', 'request_key' => (string) Str::uuid()]);
    $b = GlobalRecovery::create(['user_id' => $other->id, 'declared_by' => $other->id, 'method' => 'QR', 'amount_cents' => 100, 'note' => 'Otro historial ficticio', 'request_key' => (string) Str::uuid()]);
    $this->actingAs($admin);
    $screen = Livewire::test(ManageGlobalRecoveries::class)
        ->call('selectPanelSection', 'global')
        ->callAction('filtrarReposiciones', data: ['user_id' => $cashier->id])->assertHasNoActionErrors()
        ->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    expect($screen->instance()->getTable()->getFilters())->toBe([]);
    $screen->callAction('limpiarFiltros')->assertCanSeeTableRecords([$a, $b]);
});
it('C107 uses complete Bolivia dates for the shared filters without changing financial records', function () {
    [$admin,$cashier,$other,$case] = c99Fixture();
    $payment = app(ReportOwnRepaymentAction::class)->execute($cashier, $case->id, ['operation_reference' => 'C107-DATE', 'request_key' => (string) Str::uuid()]);
    $payment->update(['created_at' => CarbonImmutable::parse('2026-10-21 03:59', 'UTC')]);
    $q = app(RepaymentPanelQuery::class);
    expect($q->query($admin, 'reports', ['desde' => '2026-10-20', 'hasta' => '2026-10-20'])->count())->toBe(1)
        ->and($q->query($admin, 'reports', ['hasta' => '2026-10-19'])->count())->toBe(0);
    expect(fn () => $q->filters(['desde' => '2026-10-20', 'hasta' => '2026-10-19']))->toThrow(ValidationException::class);
});
it('C107 renders the shared filter modal even when the active table belongs to a child widget', function () {
    [$admin] = c99Fixture();
    $this->actingAs($admin);
    $screen = Livewire::test(ManageGlobalRecoveries::class);
    $screen->assertSee("livewireId: '".$screen->instance()->getId()."'", false);
    $screen->mountAction('filtrarReposiciones')->assertActionMounted('filtrarReposiciones');
    expect($screen->instance()->getMountedAction()->getModalHeading())->toBe('Filtros de reposiciones');
    expect($screen->instance()->getSchema($screen->instance()->getMountedActionSchemaName())->toHtml())->toContain('Cajera', 'Desde', 'Hasta');
});
it('C107 reduces the general accumulated balance only after a real repayment is verified', function () {
    [$admin,$cashier,$other,$first,$second] = c105Cases();
    $q = app(RepaymentPanelQuery::class);
    $batch = app(ReportOwnGlobalRepaymentAction::class)->execute($cashier, ['mode' => 'partial', 'amount' => '80', 'operation_reference' => 'C107-80', 'request_key' => (string) Str::uuid()]);
    expect($q->overview($admin)['pending_cents'])->toBe(10000);
    app(ConfirmGlobalRecoveryAction::class)->execute($admin, $batch, true, 'Ingreso ficticio verificado');
    expect($q->overview($admin)['pending_cents'])->toBe(2000);
});
