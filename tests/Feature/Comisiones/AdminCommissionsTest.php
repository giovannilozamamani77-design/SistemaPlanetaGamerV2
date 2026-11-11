<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialRules;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Filament\Pages\Comisiones\CommissionDashboard;
use App\Filament\Widgets\Comisiones\AdminCommissionHistoryWidget;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function c295People(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed(UsuariosPermissionsSeeder::class);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create(['name' => 'Administración prueba']);
    $admin->assignRole('administrador');
    $a = User::factory()->create(['name' => 'Cajera Alba']);
    $a->assignRole('cajera');
    $b = User::factory()->create(['name' => 'Cajera Berta']);
    $b->assignRole('cajera');

    return [$admin, $a, $b];
}
function c295Entry(User $user, string $kind, int $amount, string $name = 'Producto de prueba'): CommissionEntry
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => $user->name, 'payment_method' => 'CASH', 'quantity' => 1, 'total_cents' => 92000, 'cost_cents' => 80000, 'pricing_revision' => 0, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    $line = AccessorySaleLine::create(['sale_id' => $sale->id, 'product_id' => null, 'kind' => $kind, 'product_code' => 'PRUEBA', 'product_name' => $name, 'quantity' => 1, 'unit_price_cents' => 95000, 'total_cents' => 92000, 'cost_cents' => 80000, 'previous_revision' => 0, 'before_quantity' => 1, 'after_quantity' => 0, 'before_value_cents' => 80000, 'after_value_cents' => 0]);

    return CommissionEntry::create(['sale_line_id' => $line->id, 'user_id' => $user->id, 'actor_name' => $user->name, 'amount_cents' => $amount, 'snapshot' => ['private_cost' => 80000, 'secret_marker' => 'COSTO-PRIVADO-C294'], 'created_at' => now()]);
}

it('C295 aggregates stored commissions per cashier and globally including former sellers and zero balances', function () {
    [$admin,$a,$b] = c295People();
    c295Entry($a, 'ACCESSORY', 100);
    c295Entry($a, 'PHONE', 2400);
    c295Entry($b, 'PHONE', 600);
    $b->removeRole('cajera');
    $b->forceFill(['is_active' => false])->save();
    $zero = User::factory()->create(['name' => 'Sin ventas']);
    $zero->assignRole('cajera');
    $query = app(AdminCommissionsQuery::class);
    expect($query->overview($admin))->toBe(['deducted_cents' => 0, 'paid_cents' => 0, 'pending_cents' => 3100, 'total_cents' => 3100, 'accessory_cents' => 100, 'phone_cents' => 3000]);
    $people = $query->cashiers($admin)->get()->keyBy('id');
    expect($people)->toHaveCount(3)->and((int) $people[$a->id]->total_cents)->toBe(2500)->and((int) $people[$b->id]->phone_cents)->toBe(600)->and((int) $people[$zero->id]->total_cents)->toBe(0);
    $rows = $query->history($admin, $a->id)->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->getAttributes())->not->toHaveKeys(['snapshot', 'cost_cents', 'customer_snapshot', 'phone_snapshot']);
    }
    CommercialRules::create(['scope' => 'COMMISSIONS', 'rules' => ['enabled' => false, 'phone_bps' => 0, 'ranges' => [['from_cents' => 0, 'amount_cents' => 0]]], 'revision' => 1]);
    expect($query->overview($admin)['total_cents'])->toBe(3100)->and(CommissionEntry::count())->toBe(3);
});
it('C295 opens the admin panel and selected cashier history with independent pagination and type filter', function () {
    [$admin,$a,$b] = c295People();
    test()->actingAs($admin);
    $rows = collect();
    for ($i = 0; $i < 7; $i++) {
        $rows->push(c295Entry($a, $i % 2 ? 'PHONE' : 'ACCESSORY', 100, 'Venta propia '.$i));
    }
    $other = c295Entry($b, 'PHONE', 9900, 'OTRA-CAJERA-C295');
    Livewire::test(CommissionDashboard::class)->assertSee('Comisiones por cajera')->assertSee('Bs 106')->assertCanSeeTableRecords([$a, $b])->mountTableAction('history', $a)->assertActionMounted(TestAction::make('history')->table($a))->assertMountedActionModalSee('Cajera Alba');
    $history = Livewire::test(AdminCommissionHistoryWidget::class, ['userId' => $a->id])->assertCanSeeTableRecords($rows->slice(2))->assertCanNotSeeTableRecords([$other, $rows[0], $rows[1]])->assertDontSee('COSTO-PRIVADO-C294');
    $history->call('gotoPage', 2, 'adminCommissionHistoryPage')->assertCanSeeTableRecords($rows->take(2));
    $history->filterTable('kind', 'PHONE')->assertCanSeeTableRecords($rows->filter(fn ($r, $i) => $i % 2)->values())->assertCanNotSeeTableRecords([$other]);
    expect(fn () => $history->set('userId', $b->id))->toThrow(CannotUpdateLockedPropertyException::class);
    Livewire::test(CommissionDashboard::class)->searchTable('Berta')->assertCountTableRecords(1)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])->assertSee('Bs 106');
    expect(CommissionEntry::count())->toBe(8);
    Http::assertNothingSent();
});
it('C295 denies cashier and manager access and rechecks revoked administrator authorization', function () {
    [$admin,$a] = c295People();
    $query = app(AdminCommissionsQuery::class);
    foreach (['cajera', 'encargado'] as $role) {
        $u = User::factory()->create();
        $u->assignRole($role);
        test()->actingAs($u);
        expect(CommissionDashboard::canAccess())->toBeFalse();
        expect(fn () => $query->cashiers($u))->toThrow(AuthorizationException::class);
        Livewire::test(CommissionDashboard::class)->assertForbidden();
        Livewire::test(AdminCommissionHistoryWidget::class, ['userId' => $a->id])->assertForbidden();
    }
    test()->actingAs($admin);
    $page = Livewire::test(CommissionDashboard::class);
    $admin->removeRole('administrador');
    $page->call('$refresh')->assertForbidden();
    $admin->assignRole('administrador');
    $admin->forceFill(['is_active' => false])->save();
    expect(fn () => $query->overview($admin))->toThrow(AuthorizationException::class);
});
it('C295 shows an empty panel without writing data and paginates earlier cashiers', function () {
    [$admin,$a,$b] = c295People();
    test()->actingAs($admin);
    expect(app(AdminCommissionsQuery::class)->overview($admin))->toBe(['deducted_cents' => 0, 'paid_cents' => 0, 'pending_cents' => 0, 'total_cents' => 0, 'accessory_cents' => 0, 'phone_cents' => 0]);
    Livewire::test(AdminCommissionHistoryWidget::class, ['userId' => $a->id])->assertCountTableRecords(0)->assertSee('Sin comisiones registradas');
    for ($i = 0; $i < 5; $i++) {
        $u = User::factory()->create(['name' => 'Cajera Extra '.$i]);
        $u->assignRole('cajera');
    }
    $page = Livewire::test(CommissionDashboard::class)->assertCountTableRecords(7);
    expect($page->instance()->getTableRecords())->toHaveCount(5);
    $page->call('gotoPage', 2, 'commissionCashiersPage');
    expect($page->instance()->getTableRecords())->toHaveCount(2);
    expect(CommissionEntry::count())->toBe(0)->and(AccessorySale::count())->toBe(0);
});
