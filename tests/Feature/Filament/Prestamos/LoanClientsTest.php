<?php

use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\SaveBorrowerAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanCommand;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Filament\Pages\Prestamos\LoanClients;
use App\Filament\Pages\Prestamos\RegisterLoan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function clientFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'America/La_Paz'));
    test()->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $loan = app(RegisterLoanAction::class)->execute($cashier, ['ci' => '000123 LP', 'name' => 'Ana Cliente', 'phone' => '70001234', 'type_id' => $type->id, 'type_revision' => 1, 'description' => 'Garrafa azul', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'delivery_method' => 'CASH', 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    test()->actingAs($cashier);

    return [$admin, $cashier, $type, $loan];
}
it('C216 searches existing loan clients by CI name and phone without copying records', function (string $search) {
    [$admin,$cashier,$type,$loan] = clientFixture();
    $client = Borrower::sole();
    $other = Borrower::create(['ci' => 'NO-MATCH', 'name' => 'Otro cliente', 'phone' => '69999999']);
    Livewire::test(LoanClients::class)->searchTable($search)->assertCanSeeTableRecords([$client])->assertCanNotSeeTableRecords([$other]);
    expect(Borrower::count())->toBe(2)->and(LoanFund::sole()->balance_cents)->toBe(80000);
})->with(['000123', 'ana', '70001234']);
it('C216 creates clients naturally and reuses their normalized CI without overwriting existing profiles', function () {
    [$admin,$cashier,$type,$loan] = clientFixture();
    Livewire::test(LoanClients::class)->callAction('createClient', data: ['ci' => ' 000123   lp ', 'name' => 'Ana Cliente', 'phone' => '70001234'])->assertHasNoActionErrors();
    expect(Borrower::count())->toBe(1);
    Livewire::test(LoanClients::class)->callAction('createClient', data: ['ci' => '000123 LP', 'name' => 'Nombre distinto', 'phone' => '70001234'])->assertHasActionErrors(['ci']);
    Livewire::test(LoanClients::class)->callAction('createClient', data: ['ci' => 'NEW-CLIENT', 'name' => 'Cliente Nuevo', 'phone' => '70007777'])->assertHasNoActionErrors();
    $client = Borrower::where('ci', 'NEW-CLIENT')->sole();
    $this->actingAs($cashier);
    Livewire::test(RegisterLoan::class)->set('data.ci', 'new-client')->assertSet('data.name', 'Cliente Nuevo')->assertSet('data.phone', '70007777')->set('data.type_id', $type->id)->set('data.description', 'Otra garrafa')->set('data.capital', '100')->set('data.days', 30)->set('data.delivery_confirmed', true)->call('register')->assertHasNoErrors()->callMountedAction()->assertHasNoErrors();
    expect(Borrower::count())->toBe(2)->and(Loan::latest('id')->first()->borrower_id)->toBe($client->id)->and(LoanFund::sole()->balance_cents)->toBe(70000);
});
it('C216 edits contact details once without rewriting existing loan receipts or the CI', function () {
    [$admin,$cashier,$type,$loan] = clientFixture();
    $client = Borrower::sole();
    $before = $loan->borrower_snapshot;
    Livewire::test(LoanClients::class)->callTableAction('editClient', $client, data: ['name' => 'Ana Actualizada', 'phone' => '71112222'])->assertHasNoTableActionErrors();
    expect($client->fresh()->name)->toBe('Ana Actualizada')->and($client->fresh()->phone)->toBe('71112222')->and($client->fresh()->ci)->toBe('000123 LP')->and($loan->fresh()->borrower_snapshot)->toBe($before);
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk()->assertSee('Ana Cliente')->assertDontSee('Ana Actualizada');
    expect(LoanFund::sole()->balance_cents)->toBe(80000)->and(LoanMovement::count())->toBe(2);
});
it('C216 rejects stale edits and rolls back interrupted saves then replays only once', function () {
    [$admin,$cashier,$type,$loan] = clientFixture();
    $client = Borrower::sole();
    $version = $client->profileVersion();
    $data = ['ci' => $client->ci, 'name' => 'Ana uno', 'phone' => '70001234', 'expected_version' => $version, 'request_key' => (string) Str::uuid()];
    $action = app(SaveBorrowerAction::class);
    DB::statement("CREATE TRIGGER fail_client BEFORE INSERT ON loan_commands WHEN NEW.kind = 'CLIENT_SAVE' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    expect(fn () => $action->execute($cashier, $data, $client))->toThrow(QueryException::class);
    expect($client->fresh()->name)->toBe('Ana Cliente');
    DB::statement('DROP TRIGGER fail_client');
    $action->execute($cashier, $data, $client);
    $action->execute($cashier, $data, $client);
    expect(LoanCommand::where('kind', 'CLIENT_SAVE')->count())->toBe(1);
    expect(fn () => $action->execute($cashier, array_replace($data, ['name' => 'Cambio obsoleto', 'request_key' => (string) Str::uuid()]), $client))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($cashier, array_replace($data, ['ci' => 'OTRO-CI', 'expected_version' => $client->fresh()->profileVersion(), 'request_key' => (string) Str::uuid()]), $client))->toThrow(ValidationException::class);
});
it('C216 paginates and limits read only users to clients from their visible loans', function () {
    [$admin,$cashier,$type,$loan] = clientFixture();
    $own = Borrower::sole();
    for ($i = 0; $i < 6; $i++) {
        Borrower::create(['ci' => 'EXTRA-'.$i, 'name' => 'Cliente '.$i]);
    }
    $all = Borrower::orderByDesc('id')->get();
    Livewire::test(LoanClients::class)->assertCanSeeTableRecords($all->take(5))->assertCanNotSeeTableRecords($all->skip(5))->call('gotoPage', 2)->assertCanSeeTableRecords($all->skip(5));
    $cashier->restrictedPermissions()->attach(Permission::findByName('prestamos.registrar'));
    $this->actingAs($cashier->fresh());
    Livewire::test(LoanClients::class)->assertActionHidden('createClient')->assertCanSeeTableRecords([$own])->assertCountTableRecords(1)->assertTableActionHidden('editClient', $own);
});

it('C216 refuses revoked client writes and revoked reads even on a previously opened screen', function () {
    [$admin,$cashier,$type,$loan] = clientFixture();
    $data = ['ci' => 'NEW-REVOKED', 'name' => 'No guardar', 'request_key' => (string) Str::uuid()];
    $page = Livewire::test(LoanClients::class)->mountAction('createClient')->setActionData(['ci' => 'NEW-REVOKED', 'name' => 'No guardar']);
    $cashier->restrictedPermissions()->attach(Permission::findByName('prestamos.registrar'));
    expect(fn () => app(SaveBorrowerAction::class)->execute($cashier, $data))->toThrow(AuthorizationException::class);
    $page->callMountedAction();
    expect(LoanCommand::where('kind', 'CLIENT_SAVE')->count())->toBe(0);
    expect(Borrower::count())->toBe(1);
    $cashier->restrictedPermissions()->attach(Permission::findByName('prestamos.ver'));
    $this->actingAs($cashier->fresh());
    $this->get(LoanClients::getUrl())->assertForbidden();
});
it('C216 escapes client names and keeps matching text free of financial disclosure', function () {
    [$admin,$cashier,$type,$loan] = clientFixture();
    $profile = ['ci' => 'XSS-CLIENT', 'name' => '<script>alert(1)</script>', 'phone' => '70009999'];
    Livewire::test(LoanClients::class)->callAction('createClient', data: $profile)->assertHasNoActionErrors();
    Livewire::test(LoanClients::class)->searchTable('XSS-CLIENT')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Capital prestado')->assertDontSee('Interés pendiente');
});
