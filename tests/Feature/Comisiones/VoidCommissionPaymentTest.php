<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Comisiones\Actions\RegisterCommissionPaymentAction;
use App\Domain\Comisiones\Actions\VoidCommissionPaymentAction;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Models\CommissionPaymentReversal;
use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Domain\Comisiones\Queries\OwnCommissionsQuery;
use App\Filament\Pages\Comisiones\CommissionDashboard;
use App\Filament\Widgets\Comisiones\CommissionPaymentsWidget;
use App\Filament\Widgets\Usuarios\OwnCommissionsWidget;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
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
function c297People(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed(UsuariosPermissionsSeeder::class);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create(['name' => 'Administración pagos']);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Cajera pagos']);
    $cashier->assignRole('cajera');

    return [$admin, $cashier];
}
function c297Payload(User $cashier, string $amount, int $earned = 5000, int $paid = 0): array
{
    return ['user_id' => $cashier->id, 'amount' => $amount, 'expected_earned_cents' => $earned, 'expected_paid_cents' => $paid, 'request_key' => (string) Str::uuid(), 'delivered' => true, 'reference' => 'OPERACION-PRUEBA', 'note' => 'Pago externo de prueba'];
}
function c297Entry(User $user, string $kind, int $amount, string $name = 'Producto de prueba'): CommissionEntry
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => $user->name, 'payment_method' => 'CASH', 'quantity' => 1, 'total_cents' => 92000, 'cost_cents' => 80000, 'pricing_revision' => 0, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    $line = AccessorySaleLine::create(['sale_id' => $sale->id, 'product_id' => null, 'kind' => $kind, 'product_code' => 'PRUEBA', 'product_name' => $name, 'quantity' => 1, 'unit_price_cents' => 95000, 'total_cents' => 92000, 'cost_cents' => 80000, 'previous_revision' => 0, 'before_quantity' => 1, 'after_quantity' => 0, 'before_value_cents' => 80000, 'after_value_cents' => 0]);

    return CommissionEntry::create(['sale_line_id' => $line->id, 'user_id' => $user->id, 'actor_name' => $user->name, 'amount_cents' => $amount, 'snapshot' => ['private_cost' => 80000, 'secret_marker' => 'COSTO-PRIVADO-C294'], 'created_at' => now()]);
}

function c297Void(CommissionPayment $payment, string $reason = 'Registro duplicado por error'): array
{
    return ['payment_id' => $payment->id, 'reason' => $reason, 'request_key' => (string) Str::uuid(), 'confirmed' => true];
}
it('C297 reverses only the selected payment once while preserving all original earnings payments and funds', function () {
    [$admin,$cashier] = c297People();
    c297Entry($cashier, 'PHONE', 5000);
    $fund = CommercialFund::create(['code' => 'GENERAL', 'balance_cents' => 50000, 'revision' => 0]);
    $register = app(RegisterCommissionPaymentAction::class);
    $original = c297Payload($cashier, '20');
    $first = $register->execute($admin, $original);
    $register->execute($admin, c297Payload($cashier, '30', 5000, 2000));
    $before = $first->getRawOriginal();
    $void = app(VoidCommissionPaymentAction::class);
    $data = c297Void($first);
    $reversal = $void->execute($admin, $data);
    expect($reversal->amount_cents)->toBe(2000)->and($reversal->before_pending_cents)->toBe(0)->and($reversal->after_pending_cents)->toBe(2000);
    expect($void->execute($admin, $data)->id)->toBe($reversal->id);
    expect(fn () => $void->execute($admin, c297Void($first)))->toThrow(ValidationException::class);
    $data['reason'] = 'Otro motivo';
    expect(fn () => $void->execute($admin, $data))->toThrow(ValidationException::class);
    expect($register->execute($admin, $original)->id)->toBe($first->id);
    expect($first->fresh()->getRawOriginal())->toEqual($before)->and(CommissionPayment::count())->toBe(2)->and(CommissionPaymentReversal::count())->toBe(1)->and(CommissionEntry::sole()->amount_cents)->toBe(5000);
    expect(app(CommissionPaymentQuery::class)->balance($cashier, $cashier->id)['pending_cents'])->toBe(2000)->and(app(OwnCommissionsQuery::class)->overview($cashier)['paid_cents'])->toBe(3000)->and(app(AdminCommissionsQuery::class)->overview($admin)['pending_cents'])->toBe(2000);
    $row = app(AdminCommissionsQuery::class)->cashiers($admin)->where('users.id', $cashier->id)->firstOrFail();
    expect((int) $row->paid_cents)->toBe(3000);
    expect($fund->fresh()->balance_cents)->toBe(50000)->and(CommercialMovement::count())->toBe(0);
    expect(fn () => $reversal->update(['reason' => 'Cambiar']))->toThrow(LogicException::class);
    expect(fn () => $reversal->delete())->toThrow(LogicException::class);
});
it('C297 rolls back an interrupted reversal and retries without a second restoration', function () {
    [$admin,$cashier] = c297People();
    c297Entry($cashier, 'PHONE', 5000);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c297Payload($cashier, '20'));
    $data = c297Void($payment);
    $a = app(VoidCommissionPaymentAction::class);
    DB::unprepared("CREATE TRIGGER c297_interrupt BEFORE INSERT ON commission_payment_reversals BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    expect(fn () => $a->execute($admin, $data))->toThrow(QueryException::class);
    expect(CommissionPaymentReversal::count())->toBe(0)->and(app(CommissionPaymentQuery::class)->balance($cashier, $cashier->id)['pending_cents'])->toBe(3000);
    DB::unprepared('DROP TRIGGER c297_interrupt');
    $a->execute($admin, $data);
    $a->execute($admin, $data);
    expect(CommissionPaymentReversal::count())->toBe(1)->and(app(CommissionPaymentQuery::class)->balance($cashier, $cashier->id)['pending_cents'])->toBe(5000);
});
it('C297 restricts cancellation to active administration and requires a reason and confirmation', function () {
    [$admin,$cashier] = c297People();
    c297Entry($cashier, 'PHONE', 5000);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c297Payload($cashier, '20'));
    $a = app(VoidCommissionPaymentAction::class);
    foreach (['cajera', 'encargado'] as $role) {
        $u = User::factory()->create();
        $u->assignRole($role);
        expect(fn () => $a->execute($u, c297Void($payment)))->toThrow(AuthorizationException::class);
    }
    $data = c297Void($payment);
    $data['reason'] = '   ';
    expect(fn () => $a->execute($admin, $data))->toThrow(ValidationException::class);
    $data = c297Void($payment);
    $data['confirmed'] = false;
    expect(fn () => $a->execute($admin, $data))->toThrow(ValidationException::class);
    $admin->forceFill(['is_active' => false])->save();
    expect(fn () => $a->execute($admin, c297Void($payment)))->toThrow(AuthorizationException::class);
    expect(CommissionPaymentReversal::count())->toBe(0);
});
it('C297 invalidates a stale payout even when cancelling and repaying restores the same net paid balance', function () {
    [$admin,$cashier] = c297People();
    c297Entry($cashier, 'PHONE', 5000);
    $register = app(RegisterCommissionPaymentAction::class);
    $payment = $register->execute($admin, c297Payload($cashier, '20'));
    $stale = c297Payload($cashier, '10', 5000, 2000);
    app(VoidCommissionPaymentAction::class)->execute($admin, c297Void($payment));
    $fresh = c297Payload($cashier, '20');
    $fresh['expected_reversal_count'] = 1;
    $register->execute($admin, $fresh);
    expect(fn () => $register->execute($admin, $stale))->toThrow(ValidationException::class);
    expect(CommissionPayment::count())->toBe(2)->and(CommissionPaymentReversal::count())->toBe(1)->and(app(CommissionPaymentQuery::class)->balance($cashier, $cashier->id)['pending_cents'])->toBe(3000);
    Http::assertNothingSent();
});

it('C297 administration cancels in history while the cashier sees the status reason and restored pending only', function () {
    [$admin,$cashier] = c297People();
    c297Entry($cashier, 'PHONE', 5000);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c297Payload($cashier, '50'));
    test()->actingAs($admin);
    $dashboard = Livewire::test(CommissionDashboard::class);
    $widget = Livewire::test(CommissionPaymentsWidget::class, ['userId' => $cashier->id])->mountTableAction('voidPayment', $payment);
    $widget->setActionData(['reason' => '', 'confirmed' => true])->callMountedAction()->assertHasActionErrors(['reason']);
    expect(CommissionPaymentReversal::count())->toBe(0);
    $widget->setActionData(['reason' => 'No se entregó el dinero', 'confirmed' => true])->callMountedAction()->assertHasNoErrors()->assertDispatched('commission-payments-updated')->assertSee('Anulado')->assertSee('No se entregó el dinero');
    $widget->assertTableActionHidden('voidPayment', $payment);
    $dashboard->dispatch('commission-payments-updated');
    $row = $dashboard->instance()->getTableRecord((string) $cashier->id);
    expect((int) $row->pending_cents)->toBe(5000)->and((int) $row->paid_cents)->toBe(0);
    test()->actingAs($cashier);
    Livewire::test(CommissionPaymentsWidget::class)->assertSee('Anulado')->assertSee('No se entregó el dinero')->assertTableActionHidden('voidPayment', $payment)->assertCanSeeTableRecords([$payment]);
    Livewire::test(OwnCommissionsWidget::class)->assertSee('Pendiente')->assertSee('Bs 50');
    $history = app(CommissionPaymentQuery::class)->history($cashier, $cashier->id)->sole();
    expect($history->reversed_by_name)->toBe($admin->name)->and($history->reversed_at)->not->toBeNull();
    expect(CommissionPayment::count())->toBe(1)->and(CommissionPaymentReversal::count())->toBe(1);
});
it('C297 preserves a failed cancellation form and safely retries it without deleting the original payment', function () {
    [$admin,$cashier] = c297People();
    c297Entry($cashier, 'PHONE', 5000);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c297Payload($cashier, '20'));
    test()->actingAs($admin);
    $widget = Livewire::test(CommissionPaymentsWidget::class, ['userId' => $cashier->id])->mountTableAction('voidPayment', $payment)->setActionData(['reason' => 'Pago informado por error', 'confirmed' => true]);
    DB::unprepared("CREATE TRIGGER c297_ui_interrupt BEFORE INSERT ON commission_payment_reversals BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    $widget->callMountedAction()->assertHasActionErrors(['reason'])->assertActionMounted(TestAction::make('voidPayment')->table($payment));
    expect(CommissionPaymentReversal::count())->toBe(0);
    DB::unprepared('DROP TRIGGER c297_ui_interrupt');
    $widget->callMountedAction()->assertHasNoErrors();
    expect(CommissionPaymentReversal::count())->toBe(1)->and(CommissionPayment::count())->toBe(1);
});
it('C297 an administrator may correct another administrators payment but cannot reuse their cancellation UUID', function () {
    [$admin,$cashier] = c297People();
    c297Entry($cashier, 'PHONE', 5000);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c297Payload($cashier, '20'));
    $other = User::factory()->create();
    $other->assignRole('administrador');
    $data = c297Void($payment);
    $a = app(VoidCommissionPaymentAction::class);
    $a->execute($other, $data);
    expect(fn () => $a->execute($admin, $data))->toThrow(AuthorizationException::class);
    expect(CommissionPaymentReversal::sole()->voided_by_user_id)->toBe($other->id)->and(CommissionPayment::sole()->registered_by_user_id)->toBe($admin->id);
});
