<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Comisiones\Actions\RegisterCommissionPaymentAction;
use App\Domain\Comisiones\Actions\VoidCommissionPaymentAction;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Models\CommissionPaymentReversal;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Domain\Notificaciones\Actions\ReadPanelNoticeAction;
use App\Domain\Notificaciones\Queries\PanelInboxQuery;
use App\Domain\Notificaciones\Queries\PanelInboxRevision;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Filament\Resources\Notificaciones\PanelNotices\Pages\ListPanelNotices;
use App\Filament\Resources\Notificaciones\PanelNotices\PanelNoticeResource;
use App\Filament\Support\Notificaciones\NoticeDestination;
use App\Livewire\Notificaciones\InboxUpdates;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function c298People(): array
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
function c298Payload(User $cashier, string $amount, int $earned = 5000, int $paid = 0): array
{
    return ['user_id' => $cashier->id, 'amount' => $amount, 'expected_earned_cents' => $earned, 'expected_paid_cents' => $paid, 'request_key' => (string) Str::uuid(), 'delivered' => true, 'reference' => 'OPERACION-PRUEBA', 'note' => 'Pago externo de prueba'];
}
function c298Entry(User $user, string $kind, int $amount, string $name = 'Producto de prueba'): CommissionEntry
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => $user->name, 'payment_method' => 'CASH', 'quantity' => 1, 'total_cents' => 92000, 'cost_cents' => 80000, 'pricing_revision' => 0, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    $line = AccessorySaleLine::create(['sale_id' => $sale->id, 'product_id' => null, 'kind' => $kind, 'product_code' => 'PRUEBA', 'product_name' => $name, 'quantity' => 1, 'unit_price_cents' => 95000, 'total_cents' => 92000, 'cost_cents' => 80000, 'previous_revision' => 0, 'before_quantity' => 1, 'after_quantity' => 0, 'before_value_cents' => 80000, 'after_value_cents' => 0]);

    return CommissionEntry::create(['sale_line_id' => $line->id, 'user_id' => $user->id, 'actor_name' => $user->name, 'amount_cents' => $amount, 'snapshot' => ['private_cost' => 80000, 'secret_marker' => 'COSTO-PRIVADO-C294'], 'created_at' => now()]);
}

function c298Void(CommissionPayment $payment, string $reason = 'Registro duplicado por error'): array
{
    return ['payment_id' => $payment->id, 'reason' => $reason, 'request_key' => (string) Str::uuid(), 'confirmed' => true];
}

it('C298 derives one private payment notice for the beneficiary and links it to personal commissions', function () {
    [$admin,$cashier] = c298People();
    c298Entry($cashier, 'PHONE', 5000);
    $data = c298Payload($cashier, '20');
    $a = app(RegisterCommissionPaymentAction::class);
    $payment = $a->execute($admin, $data);
    $a->execute($admin, $data);
    $q = app(PanelInboxQuery::class);
    $notice = $q->execute($cashier)->whereKey('commission-payment:'.$payment->id)->firstOrFail();
    expect($notice->amount_cents)->toBe(2000)->and($notice->actor_name)->toBe($admin->name)->and($notice->priority)->toBe(1)->and($notice->target)->toBe('perfil-comisiones')->and($notice->summary)->toContain('Registrado por:', 'Bs 20');
    expect($q->execute($cashier)->count())->toBe(1)->and($q->execute($admin)->count())->toBe(0);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect($q->execute($other)->count())->toBe(0);
    expect(fn () => app(ReadPanelNoticeAction::class)->execute($other, $notice->id))->toThrow(AuthorizationException::class);
    test()->actingAs($cashier);
    expect(PanelNoticeResource::getNavigationBadge())->toBe('1');
    expect(NoticeDestination::url($notice))->toBe(EditOwnProfile::getUrl(panel: 'inicio').'?profileTab=profile-commercial-commissions');
    expect($notice->getAttributes())->not->toHaveKeys(['request_key', 'payload_hash', 'snapshot', 'cost_cents']);
});
it('C298 preserves the read payment notice and creates a distinct unread cancellation notice exactly once', function () {
    [$admin,$cashier] = c298People();
    c298Entry($cashier, 'PHONE', 5000);
    $paymentData = c298Payload($cashier, '20');
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, $paymentData);
    test()->actingAs($cashier);
    $read = app(ReadPanelNoticeAction::class);
    $key = 'commission-payment:'.$payment->id;
    $read->execute($cashier, $key);
    expect(PanelNoticeResource::getNavigationBadge())->toBeNull();
    $voidData = c298Void($payment);
    $void = app(VoidCommissionPaymentAction::class)->execute($admin, $voidData);
    $key2 = 'commission-payment-void:'.$void->id;
    $q = app(PanelInboxQuery::class);
    $notice = $q->execute($cashier)->whereKey($key2)->firstOrFail();
    expect($notice->summary)->toContain('Anulado por:', 'pendiente')->and($notice->amount_cents)->toBe(2000)->and(PanelNoticeResource::getNavigationBadge())->toBe('1');
    expect($q->execute($cashier)->whereKey($key)->firstOrFail()->read_at)->not->toBeNull();
    expect($q->execute($cashier)->whereKey($key)->firstOrFail()->source_status)->toBe('ANULADO');
    app(VoidCommissionPaymentAction::class)->execute($admin, $voidData);
    app(RegisterCommissionPaymentAction::class)->execute($admin, $paymentData);
    expect($q->execute($cashier)->count())->toBe(2);
    $read->execute($cashier, $key2);
    $read->execute($cashier, $key2);
    expect(DB::table('panel_notice_reads')->count())->toBe(2)->and(PanelNoticeResource::getNavigationBadge())->toBeNull()->and(CommissionPayment::count())->toBe(1)->and(CommissionPaymentReversal::count())->toBe(1)->and(app(CommissionPaymentQuery::class)->balance($cashier, $cashier->id)['pending_cents'])->toBe(5000);
    $token = app(PanelInboxRevision::class)->token();
    app(VoidCommissionPaymentAction::class)->execute($admin, $voidData);
    expect(app(PanelInboxRevision::class)->token())->toBe($token);
});
it('C298 refreshes the existing notification signal after payments and cancellations without reading the inbox unchanged', function () {
    [$admin,$cashier] = c298People();
    c298Entry($cashier, 'PHONE', 5000);
    test()->actingAs($cashier);
    $screen = Livewire::test(InboxUpdates::class);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c298Payload($cashier, '20'));
    $screen->call('checkForUpdates')->assertDispatched('panel-inbox-refresh');
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $screen->call('checkForUpdates')->assertNotDispatched('panel-inbox-refresh');
    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'notice_items') || str_contains($sql, 'commission_payments')))->toHaveCount(0);
    app(VoidCommissionPaymentAction::class)->execute($admin, c298Void($payment));
    $screen->call('checkForUpdates')->assertDispatched('panel-inbox-refresh');
    Http::assertNothingSent();
});
it('C298 never publishes a rolled back payment or cancellation', function () {
    [$admin,$cashier] = c298People();
    c298Entry($cashier, 'PHONE', 5000);
    $revision = app(PanelInboxRevision::class);
    $before = $revision->token();
    expect(function () use ($admin, $cashier) {
        DB::transaction(function () use ($admin, $cashier) {
            app(RegisterCommissionPaymentAction::class)->execute($admin, c298Payload($cashier, '20'));
            throw new RuntimeException('Rollback pago');
        });
    })->toThrow(RuntimeException::class);
    expect($revision->token())->toBe($before)->and(app(PanelInboxQuery::class)->execute($cashier)->count())->toBe(0);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c298Payload($cashier, '20'));
    $before = $revision->token();
    expect(function () use ($admin, $payment) {
        DB::transaction(function () use ($admin, $payment) {
            app(VoidCommissionPaymentAction::class)->execute($admin, c298Void($payment));
            throw new RuntimeException('Rollback anulación');
        });
    })->toThrow(RuntimeException::class);
    expect($revision->token())->toBe($before)->and(app(PanelInboxQuery::class)->execute($cashier)->count())->toBe(1)->and(CommissionPaymentReversal::count())->toBe(0);
});
it('C298 checks current profile permission for notices and lets the owner open commissions without changing money', function () {
    [$admin,$cashier] = c298People();
    c298Entry($cashier, 'PHONE', 5000);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c298Payload($cashier, '20'));
    test()->actingAs($cashier);
    $notice = app(PanelInboxQuery::class)->execute($cashier)->whereKey('commission-payment:'.$payment->id)->firstOrFail();
    Livewire::test(ListPanelNotices::class)->assertCanSeeTableRecords([$notice])->callTableAction('abrir', $notice)->assertRedirect(NoticeDestination::url($notice));
    test()->get(NoticeDestination::url($notice))->assertOk()->assertSee('Mis comisiones');
    expect(app(CommissionPaymentQuery::class)->balance($cashier, $cashier->id)['pending_cents'])->toBe(3000);
    $cashier->restrictedPermissions()->attach(Permission::findByName('perfil.ver', 'web'));
    expect(app(PanelInboxQuery::class)->execute($cashier)->count())->toBe(0);
    expect(fn () => app(ReadPanelNoticeAction::class)->execute($cashier, $notice->id))->toThrow(AuthorizationException::class);
    $cashier->forceFill(['is_active' => false])->save();
    expect(fn () => app(PanelInboxQuery::class)->execute($cashier))->toThrow(AuthorizationException::class);
});

it('C298 keeps payment and cancellation saved when the optional notification cache fails', function () {
    [$admin,$cashier] = c298People();
    c298Entry($cashier, 'PHONE', 5000);
    Exceptions::fake();
    config(['notificaciones.revision_store' => 'missing-c298']);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, c298Payload($cashier, '20'));
    Exceptions::assertReported(InvalidArgumentException::class);
    app(VoidCommissionPaymentAction::class)->execute($admin, c298Void($payment));
    config(['notificaciones.revision_store' => 'array']);
    expect(CommissionPayment::count())->toBe(1)->and(CommissionPaymentReversal::count())->toBe(1)->and(app(PanelInboxQuery::class)->execute($cashier)->count())->toBe(2)->and(app(CommissionPaymentQuery::class)->balance($cashier, $cashier->id)['pending_cents'])->toBe(5000);
});
