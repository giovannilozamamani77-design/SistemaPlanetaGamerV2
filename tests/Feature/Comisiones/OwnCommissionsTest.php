<?php

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialRules;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Queries\OwnCommissionsQuery;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Filament\Widgets\Usuarios\OwnCommissionsWidget;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function c294People(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed(UsuariosPermissionsSeeder::class);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $a = User::factory()->create();
    $a->assignRole('cajera');
    $b = User::factory()->create();
    $b->assignRole('cajera');

    return [$a, $b];
}
function c294Entry(User $user, string $kind, int $amount, string $name = 'Producto de prueba'): CommissionEntry
{
    $sale = AccessorySale::create(['user_id' => $user->id, 'actor_name' => $user->name, 'payment_method' => 'CASH', 'quantity' => 1, 'total_cents' => 92000, 'cost_cents' => 80000, 'pricing_revision' => 0, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
    $line = AccessorySaleLine::create(['sale_id' => $sale->id, 'product_id' => null, 'kind' => $kind, 'product_code' => 'PRUEBA', 'product_name' => $name, 'quantity' => 1, 'unit_price_cents' => 95000, 'total_cents' => 92000, 'cost_cents' => 80000, 'previous_revision' => 0, 'before_quantity' => 1, 'after_quantity' => 0, 'before_value_cents' => 80000, 'after_value_cents' => 0]);

    return CommissionEntry::create(['sale_line_id' => $line->id, 'user_id' => $user->id, 'actor_name' => $user->name, 'amount_cents' => $amount, 'snapshot' => ['private_cost' => 80000, 'secret_marker' => 'COSTO-PRIVADO-C294'], 'created_at' => now()]);
}
it('C294 returns only the owners frozen commissions and separates accessory and phone totals', function () {
    [$a,$b] = c294People();
    c294Entry($a, 'ACCESSORY', 100);
    c294Entry($a, 'PHONE', 2400);
    c294Entry($b, 'PHONE', 99999, 'AJENO-C294');
    $query = app(OwnCommissionsQuery::class);
    expect($query->overview($a))->toBe(['deducted_cents' => 0, 'paid_cents' => 0, 'pending_cents' => 2500, 'total_cents' => 2500, 'accessory_cents' => 100, 'phone_cents' => 2400]);
    $rows = $query->history($a)->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->getAttributes())->not->toHaveKeys(['snapshot', 'cost_cents', 'before_value_cents', 'phone_snapshot', 'customer_snapshot']);
    }
    CommercialRules::create(['scope' => 'COMMISSIONS', 'rules' => ['enabled' => false, 'phone_bps' => 0, 'ranges' => [['from_cents' => 0, 'amount_cents' => 0]]], 'revision' => 4]);
    expect($query->overview($a)['total_cents'])->toBe(2500)->and(CommissionEntry::count())->toBe(3);
    Http::assertNothingSent();
});
it('C294 paginates own history and filters type without leaking other cashier data or changing the lifetime totals', function () {
    [$a,$b] = c294People();
    test()->actingAs($a);
    $rows = collect();
    for ($i = 0; $i < 7; $i++) {
        $rows->push(c294Entry($a, $i % 2 ? 'PHONE' : 'ACCESSORY', 100, 'Propio '.$i));
    }
    $other = c294Entry($b, 'PHONE', 99999, 'AJENO-C294');
    $widget = Livewire::test(OwnCommissionsWidget::class)->assertSee('Comisiones acumuladas')->assertSee('Bs 7')->assertDontSee('COSTO-PRIVADO-C294')->assertCanSeeTableRecords($rows->slice(2))->assertCanNotSeeTableRecords([$other, $rows[0], $rows[1]]);
    $widget->call('gotoPage', 2, 'ownCommissionsPage')->assertCanSeeTableRecords($rows->take(2))->assertCanNotSeeTableRecords($rows->slice(2));
    $widget->filterTable('kind', 'PHONE')->assertCanSeeTableRecords($rows->filter(fn ($r, $i) => $i % 2)->values())->assertCanNotSeeTableRecords($rows->filter(fn ($r, $i) => ! ($i % 2))->values())->assertSee('Bs 7');
    expect(CommissionEntry::count())->toBe(8);
});
it('C294 adds the commissions tab to the existing profile and denies inactive or revoked profiles', function () {
    [$a] = c294People();
    test()->actingAs($a);
    test()->get(EditOwnProfile::getUrl())->assertOk()->assertSee('Mis comisiones');
    $a->forceFill(['is_active' => false])->save();
    expect(fn () => app(OwnCommissionsQuery::class)->overview($a))->toThrow(AuthorizationException::class);
    Livewire::test(OwnCommissionsWidget::class)->assertForbidden();
    $a->forceFill(['is_active' => true])->save();
    $a->removeRole('cajera');
    expect(fn () => app(OwnCommissionsQuery::class)->history($a))->toThrow(AuthorizationException::class);
});

it('C294 renders a zero balance and empty history without creating commissions or changing sales', function () {
    [$a] = c294People();
    test()->actingAs($a);
    $query = app(OwnCommissionsQuery::class);
    expect($query->overview($a))->toBe(['deducted_cents' => 0, 'paid_cents' => 0, 'pending_cents' => 0, 'total_cents' => 0, 'accessory_cents' => 0, 'phone_cents' => 0]);
    Livewire::test(OwnCommissionsWidget::class)->assertSee('Todavía no tienes comisiones registradas')->assertSee('Bs 0')->assertCountTableRecords(0);
    expect(CommissionEntry::count())->toBe(0)->and(AccessorySale::count())->toBe(0);
    Http::assertNothingSent();
});
