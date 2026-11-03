<?php

use App\Domain\Tarjetas\Actions\RecordEntelDigitalFundingAction;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelDigitalMovement;
use App\Domain\Tarjetas\Queries\EntelDigitalQuery;
use App\Filament\Pages\Tarjetas\EntelDigitalBalances;
use App\Livewire\Navigation\Sidebar;
use App\Models\User;
use Database\Seeders\Tarjetas\EntelDigitalPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function c153Panel(string $role = 'administrador'): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, EntelDigitalPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}
it('C153 shows two uninitialized balances without writing accounts when opening the page', function () {
    c153Panel();
    Livewire::test(EntelDigitalBalances::class)->assertSee('Sin saldo inicial')->assertSee('Crédito')->assertSee('Paquetes')
        ->assertActionVisible('INITIAL_CREDIT')->assertActionVisible('INITIAL_PACKAGES')->assertActionHidden('TOP_UP_CREDIT');
    expect(EntelDigitalAccount::count())->toBe(0)->and(EntelDigitalMovement::count())->toBe(0);
});
it('C153 initializes through the modal and immediately shows the funding action and new balance', function () {
    c153Panel();
    $page = Livewire::test(EntelDigitalBalances::class)->callAction('INITIAL_CREDIT', data: ['credited_amount' => '1000', 'request_key' => (string) Str::uuid()])
        ->assertHasNoActionErrors()->assertSee('Bs 1000')->assertActionHidden('INITIAL_CREDIT')->assertActionVisible('TOP_UP_CREDIT')->assertActionVisible('INITIAL_PACKAGES');
    $page->callAction('TOP_UP_CREDIT', data: ['paid_amount' => '5000', 'credited_amount' => '5200', 'reference' => 'carga de prueba', 'request_key' => (string) Str::uuid()])
        ->assertHasNoActionErrors()->assertSee('Bs 6200');
    expect(EntelDigitalMovement::count())->toBe(2)->and(EntelDigitalAccount::count())->toBe(1);
});
it('C153 previews actual bonus and cancelling does not create a top up', function () {
    $user = c153Panel();
    app(RecordEntelDigitalFundingAction::class)->execute($user, ['kind' => 'PACKAGES', 'operation' => 'INITIAL', 'credited_amount' => '0', 'request_key' => (string) Str::uuid()]);
    Livewire::test(EntelDigitalBalances::class)->mountAction('TOP_UP_PACKAGES')->fillForm(['paid_amount' => '5000', 'credited_amount' => '5250'])
        ->assertMountedActionModalSee('Bs 250')->assertMountedActionModalSee('5.00 %')->unmountAction();
    expect(EntelDigitalAccount::sole()->balance_cents)->toBe(0)->and(EntelDigitalMovement::count())->toBe(1);
});
it('C153 displays domain validation on the modal without changing the balance', function () {
    $user = c153Panel();
    app(RecordEntelDigitalFundingAction::class)->execute($user, ['kind' => 'CREDIT', 'operation' => 'INITIAL', 'credited_amount' => '0', 'request_key' => (string) Str::uuid()]);
    Livewire::test(EntelDigitalBalances::class)->callAction('TOP_UP_CREDIT', data: ['paid_amount' => '4000', 'credited_amount' => '4200', 'request_key' => (string) Str::uuid()])->assertHasActionErrors(['paid_amount']);
    expect(EntelDigitalAccount::sole()->balance_cents)->toBe(0)->and(EntelDigitalMovement::count())->toBe(1);
});
it('C153 protects the page URL from cashier and manager and unauthenticated access', function () {
    foreach (['cajera', 'encargado'] as $role) {
        c153Panel($role);
        $this->get(EntelDigitalBalances::getUrl())->assertForbidden();
        expect(EntelDigitalBalances::canAccess())->toBeFalse();
        Livewire::test(Sidebar::class)->assertDontSee('Saldos digitales Entel');
    }
    auth()->logout();
    $this->get(EntelDigitalBalances::getUrl())->assertRedirect();
});
it('C153 paginates older funding history and filters by the right book', function () {
    $user = c153Panel();
    $action = app(RecordEntelDigitalFundingAction::class);
    $first = $action->execute($user, ['kind' => 'CREDIT', 'operation' => 'INITIAL', 'credited_amount' => '0', 'request_key' => (string) Str::uuid()]);
    $packages = $action->execute($user, ['kind' => 'PACKAGES', 'operation' => 'INITIAL', 'credited_amount' => '10', 'request_key' => (string) Str::uuid()]);
    for ($i = 0; $i < 5; $i++) {
        $action->execute($user, ['kind' => 'CREDIT', 'operation' => 'TOP_UP', 'paid_amount' => '5000', 'credited_amount' => '5000', 'request_key' => (string) Str::uuid()]);
    }
    Livewire::test(EntelDigitalBalances::class)->assertCanNotSeeTableRecords([$first])->call('gotoPage', 2)->assertCanSeeTableRecords([$first, $packages]);
    Livewire::test(EntelDigitalBalances::class)->filterTable('kind', 'PACKAGES')->assertCanSeeTableRecords([$packages])->assertCanNotSeeTableRecords([$first]);
});
it('C153 escapes the funding reference in the history detail modal', function () {
    $user = c153Panel();
    $m = app(RecordEntelDigitalFundingAction::class)->execute($user, ['kind' => 'CREDIT', 'operation' => 'INITIAL', 'credited_amount' => '0', 'reference' => '<script>alert(1)</script>', 'request_key' => (string) Str::uuid()]);
    Livewire::test(EntelDigitalBalances::class)->mountTableAction('detail', $m)->assertMountedActionModalSee('<script>alert(1)</script>')->assertDontSeeHtml('<script>alert(1)</script>');
});

it('C153 supports read only administrators and rejects individually restricted reads', function () {
    $user = c153Panel();
    $permission = Permission::findByName('tarjetas.entel.registrar');
    $user->restrictedPermissions()->attach($permission);
    $this->actingAs($user->fresh());
    Livewire::test(EntelDigitalBalances::class)->assertActionHidden('INITIAL_CREDIT')->assertSee('Sin saldo inicial');
    $user->restrictedPermissions()->attach(Permission::findByName('tarjetas.entel.ver'));
    $this->actingAs($user->fresh());
    $this->get(EntelDigitalBalances::getUrl())->assertForbidden();
    expect(fn () => app(EntelDigitalQuery::class)->movements($user))->toThrow(AuthorizationException::class);
});
