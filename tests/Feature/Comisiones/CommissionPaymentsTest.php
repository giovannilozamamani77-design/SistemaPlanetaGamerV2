<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Comisiones\Actions\RegisterCommissionPaymentAction;
use App\Domain\Comisiones\Actions\VoidCommissionPaymentAction;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Domain\Comisiones\Queries\OwnCommissionsQuery;
use App\Filament\Pages\Comisiones\CommissionDashboard;
use App\Filament\Widgets\Comisiones\CommissionPaymentsWidget;
use App\Filament\Widgets\Usuarios\OwnCommissionsWidget;
use App\Filament\Widgets\Usuarios\OwnProfileBalancesWidget;
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
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function c296People(): array
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
function c296Payload(User $cashier, string $amount, int $earned = 5000, int $paid = 0): array
{
    return ['user_id' => $cashier->id, 'amount' => $amount, 'expected_earned_cents' => $earned, 'expected_paid_cents' => $paid, 'request_key' => (string) Str::uuid(), 'delivered' => true, 'reference' => 'OPERACION-PRUEBA', 'note' => 'Pago externo de prueba'];
}
function c296Entry(User $user, string $kind, int $amount, string $name = 'Producto de prueba'): CommissionEntry
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => $user->name, 'payment_method' => 'CASH', 'quantity' => 1, 'total_cents' => 92000, 'cost_cents' => 80000, 'pricing_revision' => 0, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    $line = AccessorySaleLine::create(['sale_id' => $sale->id, 'product_id' => null, 'kind' => $kind, 'product_code' => 'PRUEBA', 'product_name' => $name, 'quantity' => 1, 'unit_price_cents' => 95000, 'total_cents' => 92000, 'cost_cents' => 80000, 'previous_revision' => 0, 'before_quantity' => 1, 'after_quantity' => 0, 'before_value_cents' => 80000, 'after_value_cents' => 0]);

    return CommissionEntry::create(['sale_line_id' => $line->id, 'user_id' => $user->id, 'actor_name' => $user->name, 'amount_cents' => $amount, 'snapshot' => ['private_cost' => 80000, 'secret_marker' => 'COSTO-PRIVADO-C294'], 'created_at' => now()]);
}

it('C296 records partial and total payments once without changing earned history or any cash fund', function () {
    [$admin,$cashier] = c296People();
    c296Entry($cashier, 'ACCESSORY', 5000);
    $fund = CommercialFund::create(['code' => 'GENERAL', 'balance_cents' => 50000, 'revision' => 0]);
    $action = app(RegisterCommissionPaymentAction::class);
    $data = c296Payload($cashier, '20');
    $first = $action->execute($admin, $data);
    expect($first->amount_cents)->toBe(2000)->and($first->source)->toBe('ADMIN_EXTERNAL');
    expect($action->execute($admin, $data)->id)->toBe($first->id);
    expect(app(CommissionPaymentQuery::class)->balance($admin, $cashier->id))->toBe(['earned_cents' => 5000, 'paid_cents' => 2000, 'pending_cents' => 3000]);
    $other = c296Payload($cashier, '20');
    expect(fn () => $action->execute($admin, $other))->toThrow(ValidationException::class);
    $action->execute($admin, c296Payload($cashier, '30', 5000, 2000));
    expect(CommissionPayment::count())->toBe(2)->and(CommissionEntry::sole()->amount_cents)->toBe(5000)->and($fund->fresh()->balance_cents)->toBe(50000)->and(CommercialMovement::count())->toBe(0);
    expect(app(OwnCommissionsQuery::class)->overview($cashier)['pending_cents'])->toBe(0)->and(app(AdminCommissionsQuery::class)->overview($admin)['paid_cents'])->toBe(5000);
    expect(fn () => $action->execute($admin, c296Payload($cashier, '0.01', 5000, 5000)))->toThrow(ValidationException::class);
    expect(fn () => $first->update(['amount_cents' => 1]))->toThrow(LogicException::class);
    expect(fn () => $first->delete())->toThrow(LogicException::class);
    Http::assertNothingSent();
});
it('C296 permits cent payments below twenty and rejects invalid excessive or unconfirmed amounts', function () {
    [$admin,$cashier] = c296People();
    c296Entry($cashier, 'PHONE', 125);
    $action = app(RegisterCommissionPaymentAction::class);
    foreach (['0', '-1', '1.26', '0.001', 'abc'] as $amount) {
        expect(fn () => $action->execute($admin, c296Payload($cashier, $amount, 125)))->toThrow(ValidationException::class);
    }
    $data = c296Payload($cashier, '1.25', 125);
    $data['delivered'] = false;
    expect(fn () => $action->execute($admin, $data))->toThrow(ValidationException::class);
    $action->execute($admin, c296Payload($cashier, '0.01', 125));
    $action->execute($admin, c296Payload($cashier, '1.24', 125, 1));
    expect(app(CommissionPaymentQuery::class)->balance($admin, $cashier->id)['pending_cents'])->toBe(0)->and(CommissionPayment::count())->toBe(2);
});
it('C296 rolls back an interrupted payment and retries the same request with a single immutable record', function () {
    [$admin,$cashier] = c296People();
    c296Entry($cashier, 'PHONE', 5000);
    $data = c296Payload($cashier, '20');
    DB::unprepared("CREATE TRIGGER c296_interrupt BEFORE INSERT ON commission_payments BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    $a = app(RegisterCommissionPaymentAction::class);
    expect(fn () => $a->execute($admin, $data))->toThrow(QueryException::class);
    expect(CommissionPayment::count())->toBe(0)->and(app(CommissionPaymentQuery::class)->balance($admin, $cashier->id)['pending_cents'])->toBe(5000);
    DB::unprepared('DROP TRIGGER c296_interrupt');
    $a->execute($admin, $data);
    $a->execute($admin, $data);
    expect(CommissionPayment::count())->toBe(1);
    $data['amount'] = '21';
    expect(fn () => $a->execute($admin, $data))->toThrow(ValidationException::class);
});

it('C296 admin records a partial payment in the panel and both profiles retain the paid and pending history', function () {
    [$admin,$cashier] = c296People();
    c296Entry($cashier, 'ACCESSORY', 5000);
    test()->actingAs($admin);
    $page = Livewire::test(CommissionDashboard::class)->mountTableAction('pay', $cashier);
    $page->setActionData(['amount' => '50.01', 'delivered' => true])->callMountedAction()->assertHasActionErrors(['amount']);
    expect(CommissionPayment::count())->toBe(0);
    $page->setActionData(['amount' => '20', 'delivered' => true, 'reference' => 'OP-296'])->callMountedAction()->assertHasNoErrors()->assertSee('Pendiente')->assertSee('Bs 30');
    $payment = CommissionPayment::sole();
    expect($payment->before_pending_cents)->toBe(5000)->and($payment->after_pending_cents)->toBe(3000);
    $page->mountTableAction('payments', $cashier)->assertMountedActionModalSee('Pagos de '.$cashier->name);
    Livewire::test(CommissionPaymentsWidget::class, ['userId' => $cashier->id])->assertCanSeeTableRecords([$payment])->assertSee('OP-296')->assertSee('Administración pagos');
    test()->actingAs($cashier);
    Livewire::test(OwnCommissionsWidget::class)->assertSee('Pagado')->assertSee('Pendiente')->assertSee('Bs 50')->assertSee('Bs 20')->assertSee('Bs 30');
    Livewire::test(CommissionPaymentsWidget::class)->assertCanSeeTableRecords([$payment]);
    expect(AccessorySale::count())->toBe(1)->and(CommissionEntry::count())->toBe(1);
});
it('C296 refuses unauthorized payment attempts and protects another cashiers payment history', function () {
    [$admin,$cashier] = c296People();
    c296Entry($cashier, 'PHONE', 5000);
    $data = c296Payload($cashier, '20');
    $a = app(RegisterCommissionPaymentAction::class);
    expect(fn () => $a->execute($cashier, $data))->toThrow(AuthorizationException::class);
    $manager = User::factory()->create();
    $manager->assignRole('encargado');
    expect(fn () => $a->execute($manager, $data))->toThrow(AuthorizationException::class);
    $a->execute($admin, $data);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    test()->actingAs($other);
    expect(fn () => app(CommissionPaymentQuery::class)->history($other, $cashier->id))->toThrow(AuthorizationException::class);
    Livewire::test(CommissionPaymentsWidget::class, ['userId' => $cashier->id])->assertForbidden();
    test()->actingAs($admin);
    $widget = Livewire::test(CommissionPaymentsWidget::class, ['userId' => $cashier->id]);
    expect(fn () => $widget->set('userId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
    $admin->removeRole('administrador');
    expect(fn () => $a->execute($admin, c296Payload($cashier, '1', 5000, 2000)))->toThrow(AuthorizationException::class);
    expect(CommissionPayment::count())->toBe(1);
});
it('C296 paginates payments separately and prevents reusing another administrators payment request', function () {
    [$admin,$cashier] = c296People();
    c296Entry($cashier, 'PHONE', 5000);
    $a = app(RegisterCommissionPaymentAction::class);
    $rows = collect();
    for ($i = 0; $i < 7; $i++) {
        $data = c296Payload($cashier, '1', 5000, $i * 100);
        $rows->push($a->execute($admin, $data));
    }
    test()->actingAs($cashier);
    $widget = Livewire::test(CommissionPaymentsWidget::class)->assertCanSeeTableRecords($rows->slice(2))->assertCanNotSeeTableRecords($rows->take(2));
    $widget->call('gotoPage', 2, 'commissionPaymentsPage')->assertCanSeeTableRecords($rows->take(2));
    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('administrador');
    expect(fn () => $a->execute($otherAdmin, $data))->toThrow(AuthorizationException::class);
    expect(app(CommissionPaymentQuery::class)->balance($cashier, $cashier->id)['pending_cents'])->toBe(4300)->and(CommissionPayment::count())->toBe(7);
});
it('C296 requires reopening a review if new earnings arrive and can settle a former inactive cashiers debt', function () {
    [$admin,$cashier] = c296People();
    c296Entry($cashier, 'PHONE', 5000);
    $data = c296Payload($cashier, '50');
    c296Entry($cashier, 'ACCESSORY', 100);
    $a = app(RegisterCommissionPaymentAction::class);
    expect(fn () => $a->execute($admin, $data))->toThrow(ValidationException::class);
    $cashier->removeRole('cajera');
    $cashier->forceFill(['is_active' => false])->save();
    $a->execute($admin, c296Payload($cashier, '51', 5100));
    $row = app(AdminCommissionsQuery::class)->cashiers($admin)->where('users.id', $cashier->id)->firstOrFail();
    expect((int) $row->paid_cents)->toBe(5100)->and((int) $row->pending_cents)->toBe(0)->and((int) $row->total_cents)->toBe(5100);
});

it('C296 preserves the payment form and request when an insert fails so the administrator can safely retry', function () {
    [$admin,$cashier] = c296People();
    c296Entry($cashier, 'PHONE', 5000);
    test()->actingAs($admin);
    $page = Livewire::test(CommissionDashboard::class)->mountTableAction('pay', $cashier)->setActionData(['amount' => '20', 'delivered' => true]);
    DB::unprepared("CREATE TRIGGER c296_ui_interrupt BEFORE INSERT ON commission_payments BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    $page->callMountedAction()->assertHasActionErrors(['amount'])->assertActionMounted(TestAction::make('pay')->table($cashier));
    expect(CommissionPayment::count())->toBe(0);
    DB::unprepared('DROP TRIGGER c296_ui_interrupt');
    $page->callMountedAction()->assertHasNoErrors();
    expect(CommissionPayment::count())->toBe(1)->and(CommissionPayment::sole()->amount_cents)->toBe(2000);
});

it('C299 shows personal pending commissions in the main profile cards and refreshes after payment or cancellation', function () {
    [$admin,$cashier] = c296People();
    c296Entry($cashier, 'PHONE', 5000);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    c296Entry($other, 'PHONE', 99999);
    test()->actingAs($cashier);
    $widget = Livewire::test(OwnProfileBalancesWidget::class)->assertSee('Comisiones pendientes')->assertSee('Bs 50')->assertDontSee('Bs 999.99')->assertSee('profileTab=profile-commercial-commissions', false);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c296Payload($cashier, '20'));
    $widget->dispatch('commission-payments-updated')->assertSee('Bs 30')->assertSee('Pagado: Bs 20');
    app(VoidCommissionPaymentAction::class)->execute($admin, ['payment_id' => $payment->id, 'reason' => 'Registro de prueba incorrecto', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $widget->dispatch('commission-payments-updated')->assertSee('Bs 50')->assertSee('Pagado: Bs 0');
    expect(CommissionEntry::count())->toBe(2)->and(CommissionPayment::count())->toBe(1);
});
