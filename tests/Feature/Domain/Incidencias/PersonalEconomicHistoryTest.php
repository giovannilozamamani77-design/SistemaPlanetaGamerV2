<?php

use App\Domain\Incidencias\Actions\CondoneSmallMonthlyShortagesAction;
use App\Domain\Incidencias\Actions\ConfirmRecoveryAction;
use App\Domain\Incidencias\Actions\DeclareRecoveryAction;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReverseRecoveryAction;
use App\Domain\Incidencias\Actions\ReviewEconomicIncidentAction;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Incidencias\Queries\OwnEconomicHistoryQuery;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function economicPeople(): array
{
    app(UsuariosPermissionsSeeder::class)->run();
    app(ControlHorarioPermissionsSeeder::class)->run();
    app(IncidenciasPermissionsSeeder::class)->run();
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');

    return [$admin, $cashier];
}
function economicReport(User $admin, User $cashier, string $reference = 'case-one', int $cents = 20000): EconomicIncident
{
    return app(ReportEconomicIncidentAction::class)->execute($admin, $cashier, ['source_module' => 'manual', 'source_reference' => $reference, 'component' => 'efectivo', 'amount_cents' => $cents, 'reason' => 'Faltante comprobado en arqueo', 'occurred_at' => now()->format('Y-m-d H:i:s')]);
}
function economicPayment(User $actor, EconomicIncident $case, int $cents = 5000, array $changes = []): RecoveryPayment
{
    return app(DeclareRecoveryAction::class)->execute($actor, $case, array_replace(['request_key' => (string) Str::uuid(), 'kind' => 'RECUPERACION', 'method' => 'EFECTIVO', 'amount_cents' => $cents, 'note' => 'Entrega para verificación administrativa'], $changes));
}
it('keeps reported differences separate from confirmed debt and checks large differences twice', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    expect($case->fresh()->pending_cents)->toBe(0);
    expect(fn () => app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Confirmar sin reingresar'))->toThrow(ValidationException::class);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Segundo conteo confirmado', 20000);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Reintento', 20000);
    expect($case->fresh()->pending_cents)->toBe(20000);
    expect(DB::table('economic_history_events')->where('action', 'FALTANTE_CONFIRMAR')->count())->toBe(1);
});
it('does not duplicate a case from the same source and component', function () {
    [$admin,$cashier] = economicPeople();
    $one = economicReport($admin, $cashier);
    $two = economicReport($admin, $cashier);
    expect($one->id)->toBe($two->id);
    expect(EconomicIncident::count())->toBe(1);
});
it('declared contributions do not reduce debt until received and verified by Administration', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $payment = economicPayment($cashier, $case);
    expect($case->fresh()->pending_cents)->toBe(20000);
    expect(fn () => app(ConfirmRecoveryAction::class)->execute($cashier, $payment, true, 'Intento propio'))->toThrow(AuthorizationException::class);
    app(ConfirmRecoveryAction::class)->execute($admin, $payment, true, 'Recibí Bs50 en efectivo de la responsable');
    app(ConfirmRecoveryAction::class)->execute($admin, $payment, true, 'Reintento');
    expect($case->fresh()->pending_cents)->toBe(15000);
    expect($case->fresh()->status)->toBe('PAGO_PARCIAL');
    expect(DB::table('economic_history_events')->where('action', 'RECEPCION_VERIFICADA')->count())->toBe(1);
});
it('administrative funding does not cancel or duplicate the cashiers personal debt', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $payment = economicPayment($admin, $case, 20000, ['kind' => 'APORTE_ADMINISTRATIVO']);
    app(ConfirmRecoveryAction::class)->execute($admin, $payment, true, 'Aporte de Administración registrado');
    expect($case->fresh()->pending_cents)->toBe(20000);
    expect($case->fresh()->status)->toBe('CONFIRMADA');
    expect($payment->fresh()->confirmed_by)->toBe($admin->id);
});
it('records a programmed salary recovery without settling until the actual application is confirmed', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $payment = economicPayment($cashier, $case, 20000, ['method' => 'SUELDO', 'salary_period' => '2026-10']);
    expect($case->fresh()->status)->toBe('DESCUENTO_PROGRAMADO');
    expect($case->fresh()->pending_cents)->toBe(20000);
    expect(fn () => app(ConfirmRecoveryAction::class)->execute($admin, $payment, false, 'Todavía no aplicado'))->toThrow(ValidationException::class);
    app(ConfirmRecoveryAction::class)->execute($admin, $payment, true, 'Planilla octubre aplicada');
    expect($case->fresh()->status)->toBe('REPUESTA');
    expect($case->fresh()->pending_cents)->toBe(0);
});
it('rejects QR verification without operation and destination', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $payment = economicPayment($cashier, $case, 5000, ['method' => 'QR']);
    expect(fn () => app(ConfirmRecoveryAction::class)->execute($admin, $payment, true, 'Intento'))->toThrow(ValidationException::class);
    expect($case->fresh()->pending_cents)->toBe(20000);
});
it('rejects overpayment and preserves the pending balance', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    expect(fn () => economicPayment($cashier, $case, 20001))->toThrow(ValidationException::class);
});
it('does not permit a delegated manager to confirm recovery receipts', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $payment = economicPayment($cashier, $case);
    $manager = User::factory()->create();
    $manager->assignRole('encargado');
    $manager->givePermissionTo('incidencias.reposiciones.confirmar');
    expect(fn () => app(ConfirmRecoveryAction::class)->execute($manager, $payment, true, 'Delegado'))->toThrow(AuthorizationException::class);
});
it('scopes history and contribution declarations to the authenticated owner', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(app(OwnEconomicHistoryQuery::class)->execute($other)->count())->toBe(0);
    expect(fn () => economicPayment($other, $case))->toThrow(AuthorizationException::class);
    expect(app(OwnEconomicHistoryQuery::class)->execute($cashier)->count())->toBe(1);
});
it('rolls back receipt and balance when the audit fails', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $payment = economicPayment($cashier, $case);
    DB::unprepared("CREATE TRIGGER reject_receipt_audit BEFORE INSERT ON economic_history_events BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    expect(fn () => app(ConfirmRecoveryAction::class)->execute($admin, $payment, true, 'Constancia de ingreso'))->toThrow(QueryException::class);
    expect($case->fresh()->pending_cents)->toBe(20000);
    expect($payment->fresh()->confirmed_at)->toBeNull();
});
it('requires a distinct administrator for self approval except the explicitly confirmed sole administrator path', function () {
    [$admin] = economicPeople();
    $case = economicReport($admin, $admin, 'own', 1000);
    expect(fn () => app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Mío'))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Excepción', null, true))->toThrow(AuthorizationException::class);
    app(ReviewEconomicIncidentAction::class)->execute($other, $case, 'CONFIRMAR', 'Revisión por otro administrador');
    expect($case->fresh()->pending_cents)->toBe(1000);
});

it('does not disclose an existing foreign report by guessing its source reference', function () {
    [$admin,$cashier] = economicPeople();
    economicReport($admin, $cashier, 'shared-origin', 1000);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(fn () => economicReport($other, $other, 'shared-origin', 1000))->toThrow(AuthorizationException::class);
});
it('never condones small cases at the monthly boundary without administration', function (int $count, int $cents, bool $condoned) {
    [$admin,$cashier] = economicPeople();
    $this->travelTo(Carbon::parse('2026-10-14 23:00', 'UTC'));
    for ($index = 0; $index < $count; $index++) {
        $case = economicReport($admin, $cashier, 'small-'.$index, $cents);
        app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Confirmado');
    }
    $this->travelTo(Carbon::parse('2026-11-01 04:00', 'UTC'));
    $action = app(CondoneSmallMonthlyShortagesAction::class);
    $action->execute('2026-10');
    $action->execute('2026-10');
    expect((int) EconomicIncident::sum('pending_cents'))->toBe($count * $cents);
    expect(DB::table('economic_month_closures')->count())->toBe(0);
    expect(DB::table('economic_history_events')->where('action', 'CONDONACION_MENSUAL')->count())->toBe(0);
})->with([[2, 100, true], [3, 100, false], [1, 200, true], [1, 201, false]]);

it('records contributions by another cashier without transferring the original responsibility', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $contributor = User::factory()->create();
    $contributor->assignRole('cajera');
    $payment = economicPayment($admin, $case, 5000, ['paid_by_user_id' => $contributor->id]);
    app(ConfirmRecoveryAction::class)->execute($admin, $payment, true, 'Otra cajera entregó Bs50 voluntariamente');
    expect($payment->paid_by_user_id)->toBe($contributor->id);
    expect($case->fresh()->user_id)->toBe($cashier->id);
    expect($case->fresh()->pending_cents)->toBe(15000);
    expect(app(OwnEconomicHistoryQuery::class)->execute($contributor)->count())->toBe(0);
});
it('does not apply a deposit operation twice even with a different request key', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $one = economicPayment($cashier, $case, 5000, ['method' => 'DEPOSITO', 'operation_reference' => 'BANK-001', 'destination' => 'BCP-DEST']);
    $two = economicPayment($cashier, $case, 5000, ['method' => 'DEPOSITO', 'operation_reference' => 'BANK-001', 'destination' => 'BCP-DEST']);
    expect($two->id)->toBe($one->id);
    app(ConfirmRecoveryAction::class)->execute($admin, $one, true, 'Depósito recibido');
    expect($case->fresh()->pending_cents)->toBe(15000);
});
it('restores economic debt only after a real repayment reversal and preserves both receipts', function () {
    [$admin,$cashier] = economicPeople();
    $case = economicReport($admin, $cashier);
    app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'CONFIRMAR', 'Verificado', 20000);
    $payment = economicPayment($cashier, $case, 5000);
    app(ConfirmRecoveryAction::class)->execute($admin, $payment, true, 'Recibido');
    expect(fn () => app(ReverseRecoveryAction::class)->execute($admin, $payment, false, 'Promesa'))->toThrow(ValidationException::class);
    $reverse = app(ReverseRecoveryAction::class)->execute($admin, $payment, true, 'Devolución efectivamente aplicada');
    app(ReverseRecoveryAction::class)->execute($admin, $payment, true, 'Reintento');
    expect($case->fresh()->pending_cents)->toBe(20000);
    expect(RecoveryPayment::count())->toBe(2);
    expect($payment->fresh()->confirmed_at)->not->toBeNull();
});
