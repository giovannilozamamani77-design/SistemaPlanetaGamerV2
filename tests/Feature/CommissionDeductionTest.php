<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Comisiones\Actions\RegisterCommissionDeductionAction;
use App\Domain\Comisiones\Actions\RegisterCommissionPaymentAction;
use App\Domain\Comisiones\Actions\VoidCommissionDeductionAction;
use App\Domain\Comisiones\Models\CommissionDeduction;
use App\Domain\Comisiones\Models\CommissionDeductionReversal;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Domain\Comisiones\Queries\OwnCommissionsQuery;
use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Filament\Pages\ControlHorario\PersonnelRecord;
use App\Filament\Widgets\Comisiones\CommissionDeductionsWidget;
use App\Models\User;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrador');
    $this->cashier = User::factory()->create();
    $this->cashier->assignRole('cajera');
    $this->manager = User::factory()->create();
    $this->manager->assignRole('encargado');
    $this->actingAs($this->admin);
});

function deductionIncident(User $admin, User $cashier, string $kind = 'RETRASO'): TimeIncident
{
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => now()->addDay()->toDateString(), 'period' => 'tarde', 'starts_time' => '14:00', 'ends_time' => '20:00']);

    return TimeIncident::create(['user_id' => $cashier->id, 'scheduled_workday_id' => $day->id, 'kind' => $kind, 'status' => 'CONFIRMADA', 'source_key' => (string) Str::uuid(), 'real_minutes' => 5, 'nominal_minutes' => 10, 'pending_minutes' => 10, 'observed_at' => $day->starts_at->addMinutes(20), 'calculation' => ['tolerance' => 15]]);
}

function deductionEarned(User $user): CommissionEntry
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => $user->name, 'payment_method' => 'CASH', 'quantity' => 1, 'total_cents' => 92000, 'cost_cents' => 80000, 'pricing_revision' => 0, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    $line = AccessorySaleLine::create(['sale_id' => $sale->id, 'product_id' => null, 'kind' => 'ACCESSORY', 'product_code' => 'PRUEBA', 'product_name' => 'Accesorio', 'quantity' => 1, 'unit_price_cents' => 92000, 'total_cents' => 92000, 'cost_cents' => 80000, 'previous_revision' => 0, 'before_quantity' => 1, 'after_quantity' => 0, 'before_value_cents' => 80000, 'after_value_cents' => 0]);

    return CommissionEntry::create(['sale_line_id' => $line->id, 'user_id' => $user->id, 'actor_name' => $user->name, 'amount_cents' => 5000, 'snapshot' => [], 'created_at' => now()]);
}

function deductionData(User $employee, TimeIncident $incident): array
{
    return ['user_id' => $employee->id, 'time_incident_id' => $incident->id, 'amount' => '12,50', 'reason' => 'Descuento autorizado por atraso confirmado', 'confirmed' => true, 'expected_pending_cents' => 5000, 'request_key' => (string) Str::uuid()];
}

it('records an immutable deduction once and preserves earned commissions time debt and cash funds', function () {
    $entry = deductionEarned($this->cashier);
    $incident = deductionIncident($this->admin, $this->cashier);
    $fund = CommercialFund::create(['code' => 'GENERAL', 'balance_cents' => 12345, 'revision' => 0]);
    $data = deductionData($this->cashier, $incident);
    $action = app(RegisterCommissionDeductionAction::class);
    $deduction = $action->execute($this->admin, $data);
    expect($action->execute($this->admin, $data)->id)->toBe($deduction->id);
    expect($deduction->amount_cents)->toBe(1250)->and($deduction->before_pending_cents)->toBe(5000)->and($deduction->after_pending_cents)->toBe(3750);
    expect(app(CommissionPaymentQuery::class)->balance($this->admin, $this->cashier->id)['pending_cents'])->toBe(3750)
        ->and($entry->fresh()->amount_cents)->toBe(5000)->and($incident->fresh()->pending_minutes)->toBe(10)->and($fund->fresh()->balance_cents)->toBe(12345);
    expect(CommissionDeduction::count())->toBe(1);
    expect(fn () => $deduction->update(['reason' => 'alterado']))->toThrow(LogicException::class);
    expect(fn () => $deduction->delete())->toThrow(LogicException::class);
    Http::assertNothingSent();
});

it('requires a valid amount reason confirmation and current balance without leaving partial records', function (array $changes) {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $data = array_replace(deductionData($this->cashier, $case), $changes);
    expect(fn () => app(RegisterCommissionDeductionAction::class)->execute($this->admin, $data))->toThrow(ValidationException::class);
    expect(CommissionDeduction::count())->toBe(0)->and(app(CommissionPaymentQuery::class)->balance($this->admin, $this->cashier->id)['pending_cents'])->toBe(5000);
})->with([
    'zero' => [['amount' => '0']], 'negative' => [['amount' => '-1']], 'precision' => [['amount' => '1.001']],
    'excessive' => [['amount' => '50.01']], 'not money' => [['amount' => 'abc']],
    'empty reason' => [['reason' => '   ']], 'not confirmed' => [['confirmed' => false]], 'stale balance' => [['expected_pending_cents' => 4999]],
]);

it('refuses a repeated incident or a reused request with a different payload', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $data = deductionData($this->cashier, $case);
    $action = app(RegisterCommissionDeductionAction::class);
    $action->execute($this->admin, $data);
    expect(fn () => $action->execute($this->admin, array_replace($data, ['request_key' => (string) Str::uuid(), 'expected_pending_cents' => 3750])))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($this->admin, array_replace($data, ['amount' => '10'])))->toThrow(ValidationException::class);
    expect(CommissionDeduction::count())->toBe(1);
});

it('authorizes only active administrators and rejects foreign or resolved incidents', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $data = deductionData($this->cashier, $case);
    $action = app(RegisterCommissionDeductionAction::class);
    foreach ([$this->cashier, $this->manager] as $actor) {
        expect(fn () => $action->execute($actor, $data))->toThrow(AuthorizationException::class);
    }
    expect(fn () => $action->execute($this->admin, array_replace($data, ['user_id' => $this->manager->id])))->toThrow(ValidationException::class);
    $case->update(['status' => 'PERDONADA', 'pending_minutes' => 0]);
    expect(fn () => $action->execute($this->admin, $data))->toThrow(ValidationException::class);
    $this->admin->forceFill(['is_active' => false])->save();
    expect(fn () => $action->execute($this->admin, $data))->toThrow(AuthorizationException::class);
    expect(CommissionDeduction::count())->toBe(0);
});

it('rolls back an interrupted deduction and can retry it without duplication', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $data = deductionData($this->cashier, $case);
    DB::unprepared("CREATE TRIGGER interrupt_deduction BEFORE INSERT ON commission_deductions BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    expect(fn () => app(RegisterCommissionDeductionAction::class)->execute($this->admin, $data))->toThrow(QueryException::class);
    expect(CommissionDeduction::count())->toBe(0);
    expect(app(CommissionPaymentQuery::class)->balance($this->admin, $this->cashier->id)['pending_cents'])->toBe(5000);
    DB::unprepared('DROP TRIGGER interrupt_deduction');
    app(RegisterCommissionDeductionAction::class)->execute($this->admin, $data);
    expect(CommissionDeduction::count())->toBe(1);
});

it('blocks a stale payment form and pays only the balance remaining after the deduction', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $payment = ['user_id' => $this->cashier->id, 'amount' => '20', 'expected_earned_cents' => 5000, 'expected_paid_cents' => 0, 'request_key' => (string) Str::uuid(), 'delivered' => true];
    app(RegisterCommissionDeductionAction::class)->execute($this->admin, deductionData($this->cashier, $case));
    $action = app(RegisterCommissionPaymentAction::class);
    expect(fn () => $action->execute($this->admin, $payment))->toThrow(ValidationException::class);
    $payment['expected_deducted_cents'] = 1250;
    $payment['amount'] = '37.50';
    $action->execute($this->admin, $payment);
    expect(app(CommissionPaymentQuery::class)->balance($this->admin, $this->cashier->id))->toBe(['earned_cents' => 5000, 'paid_cents' => 3750, 'pending_cents' => 0]);
    expect(app(AdminCommissionsQuery::class)->overview($this->admin)['deducted_cents'])->toBe(1250);
    expect(app(OwnCommissionsQuery::class)->overview($this->cashier)['pending_cents'])->toBe(0);
});

it('lets administration confirm a managers absence deduction from the personnel record and keeps an owned history', function () {
    deductionEarned($this->manager);
    $case = deductionIncident($this->admin, $this->manager, 'FALTA');
    $screen = Livewire::withQueryParams(['employee' => $this->manager->id])->test(PersonnelRecord::class)->call('selectSection', 'commissions')->mountTableAction('deductCommission', $this->manager);
    $screen->setActionData(['time_incident_id' => $case->id, 'amount' => '12,50', 'reason' => 'Falta confirmada por Administración', 'confirmed' => false])->callMountedAction()->assertHasActionErrors(['confirmed']);
    expect(CommissionDeduction::count())->toBe(0);
    $screen->setActionData(['confirmed' => true])->callMountedAction()->assertHasNoErrors()->assertSee('Bs 37.50');
    $record = CommissionDeduction::sole();
    expect($record->user_id)->toBe($this->manager->id)->and($record->registered_by_user_id)->toBe($this->admin->id)->and($record->snapshot['incident_kind'])->toBe('FALTA');
    Livewire::test(CommissionDeductionsWidget::class, ['userId' => $this->manager->id])->assertCanSeeTableRecords([$record])->assertSee('Falta confirmada por Administración');
    $this->actingAs($this->manager);
    Livewire::test(CommissionDeductionsWidget::class)->assertCanSeeTableRecords([$record]);
    Livewire::test(CommissionDeductionsWidget::class, ['userId' => $this->cashier->id])->assertForbidden();
});

it('keeps the deduction dialog and request after an interrupted save for a safe retry', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $screen = Livewire::withQueryParams(['employee' => $this->cashier->id])->test(PersonnelRecord::class)->call('selectSection', 'commissions')->mountTableAction('deductCommission', $this->cashier);
    DB::unprepared("CREATE TRIGGER interrupt_deduction_ui BEFORE INSERT ON commission_deductions BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    $screen->setActionData(['time_incident_id' => $case->id, 'amount' => '10', 'reason' => 'Motivo revisado por Administración', 'confirmed' => true])->callMountedAction()->assertHasActionErrors(['amount']);
    expect(CommissionDeduction::count())->toBe(0);
    DB::unprepared('DROP TRIGGER interrupt_deduction_ui');
    $screen->callMountedAction()->assertHasNoErrors();
    expect(CommissionDeduction::count())->toBe(1)->and(CommissionDeduction::sole()->amount_cents)->toBe(1000);
});

it('rechecks administrative permission after the form was opened', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $data = deductionData($this->cashier, $case);
    $this->admin->removeRole('administrador');
    expect(fn () => app(RegisterCommissionDeductionAction::class)->execute($this->admin, $data))->toThrow(AuthorizationException::class);
    expect(CommissionDeduction::count())->toBe(0);
});

it('returns the deducted amount exactly once while retaining both immutable historical records', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $deduction = app(RegisterCommissionDeductionAction::class)->execute($this->admin, deductionData($this->cashier, $case));
    $original = $deduction->fresh()->getAttributes();
    $data = ['deduction_id' => $deduction->id, 'reason' => 'Monto registrado por error, devolución aprobada', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
    $action = app(VoidCommissionDeductionAction::class);
    $reversal = $action->execute($this->admin, $data);
    expect($action->execute($this->admin, $data)->id)->toBe($reversal->id);
    expect($reversal->amount_cents)->toBe(1250)->and($reversal->before_pending_cents)->toBe(3750)->and($reversal->after_pending_cents)->toBe(5000);
    expect($deduction->fresh()->getAttributes())->toBe($original);
    expect(app(CommissionPaymentQuery::class)->balance($this->admin, $this->cashier->id)['pending_cents'])->toBe(5000)
        ->and(app(AdminCommissionsQuery::class)->overview($this->admin)['deducted_cents'])->toBe(0)
        ->and(app(OwnCommissionsQuery::class)->overview($this->cashier)['pending_cents'])->toBe(5000)
        ->and($case->fresh()->pending_minutes)->toBe(10);
    expect(CommissionDeduction::count())->toBe(1)->and(CommissionDeductionReversal::count())->toBe(1);
    expect(fn () => $action->execute($this->admin, array_replace($data, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    expect(fn () => $reversal->update(['reason' => 'alterado']))->toThrow(LogicException::class);
    expect(fn () => $reversal->delete())->toThrow(LogicException::class);
});

it('rejects a reversal without reason or confirmation', function (array $changes) {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $deduction = app(RegisterCommissionDeductionAction::class)->execute($this->admin, deductionData($this->cashier, $case));
    $data = array_replace(['deduction_id' => $deduction->id, 'reason' => 'Revisión de error confirmado', 'confirmed' => true, 'request_key' => (string) Str::uuid()], $changes);
    expect(fn () => app(VoidCommissionDeductionAction::class)->execute($this->admin, $data))->toThrow(ValidationException::class);
    expect(app(CommissionPaymentQuery::class)->balance($this->admin, $this->cashier->id)['pending_cents'])->toBe(3750);
})->with(['empty reason' => [['reason' => '  ']], 'no confirmation' => [['confirmed' => false]], 'reason too long' => [['reason' => str_repeat('x', 501)]]]);

it('prevents cashiers managers and inactive administrators from reversing a deduction', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $deduction = app(RegisterCommissionDeductionAction::class)->execute($this->admin, deductionData($this->cashier, $case));
    $data = ['deduction_id' => $deduction->id, 'reason' => 'Error confirmado', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
    $action = app(VoidCommissionDeductionAction::class);
    foreach ([$this->cashier, $this->manager] as $actor) {
        expect(fn () => $action->execute($actor, $data))->toThrow(AuthorizationException::class);
    }
    $this->actingAs($this->cashier);
    Livewire::test(CommissionDeductionsWidget::class)->assertTableActionHidden('voidDeduction', $deduction);
    $this->admin->forceFill(['is_active' => false])->save();
    expect(fn () => $action->execute($this->admin, $data))->toThrow(AuthorizationException::class);
    expect(CommissionDeductionReversal::count())->toBe(0);
});

it('reverses against the current balance after a partial payment and refreshes the administrator history', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $deduction = app(RegisterCommissionDeductionAction::class)->execute($this->admin, deductionData($this->cashier, $case));
    app(RegisterCommissionPaymentAction::class)->execute($this->admin, ['user_id' => $this->cashier->id, 'amount' => '10', 'expected_earned_cents' => 5000, 'expected_paid_cents' => 0, 'expected_deducted_cents' => 1250, 'request_key' => (string) Str::uuid(), 'delivered' => true]);
    $screen = Livewire::test(CommissionDeductionsWidget::class, ['userId' => $this->cashier->id]);
    $screen->callTableAction('voidDeduction', $deduction, data: ['reason' => 'Corrección revisada por Administración', 'confirmed' => true])->assertHasNoErrors()->assertSee('Anulado')->assertSee('Corrección revisada por Administración')->assertDispatched('commission-payments-updated');
    expect(app(CommissionPaymentQuery::class)->balance($this->admin, $this->cashier->id))->toBe(['earned_cents' => 5000, 'paid_cents' => 1000, 'pending_cents' => 4000]);
    $reversal = CommissionDeductionReversal::sole();
    expect($reversal->before_pending_cents)->toBe(2750)->and($reversal->after_pending_cents)->toBe(4000);
    $screen->assertTableActionHidden('voidDeduction', $deduction);
});

it('keeps the reversal form after interruption and safely retries without another credit', function () {
    deductionEarned($this->cashier);
    $case = deductionIncident($this->admin, $this->cashier);
    $deduction = app(RegisterCommissionDeductionAction::class)->execute($this->admin, deductionData($this->cashier, $case));
    $screen = Livewire::test(CommissionDeductionsWidget::class, ['userId' => $this->cashier->id])->mountTableAction('voidDeduction', $deduction);
    DB::unprepared("CREATE TRIGGER interrupt_deduction_reversal BEFORE INSERT ON commission_deduction_reversals BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    $screen->setActionData(['reason' => 'Corrección de monto erróneo', 'confirmed' => true])->callMountedAction()->assertHasActionErrors(['reason']);
    expect(app(CommissionPaymentQuery::class)->balance($this->admin, $this->cashier->id)['pending_cents'])->toBe(3750);
    DB::unprepared('DROP TRIGGER interrupt_deduction_reversal');
    $screen->callMountedAction()->assertHasNoErrors();
    expect(CommissionDeductionReversal::count())->toBe(1);
    expect(app(CommissionPaymentQuery::class)->balance($this->admin, $this->cashier->id)['pending_cents'])->toBe(5000);
});
