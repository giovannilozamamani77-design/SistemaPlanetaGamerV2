<?php

use App\Domain\Prestamos\Actions\AnnulLoanAction;
use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\SaveLoanAccountAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Actions\SettleLoanAction;
use App\Domain\Prestamos\Calculators\LoanTermCalculator;
use App\Domain\Prestamos\Data\LoanRenewalHistoryData;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanAnnulment;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanObjectSale;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Domain\Prestamos\Models\LoanSettlement;
use App\Domain\Prestamos\Models\LoanTermChange;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Filament\Pages\Prestamos\LoanCapitalHistory;
use App\Filament\Pages\Prestamos\LoanList;
use App\Filament\Pages\Prestamos\LoanObjectTypes;
use App\Filament\Pages\Prestamos\LoanRecord;
use App\Filament\Pages\Prestamos\LoanSettings;
use App\Filament\Pages\Prestamos\LoanTermHistory;
use App\Filament\Pages\Prestamos\RegisterLoan;
use App\Livewire\Prestamos\LoanRenewalHistory;
use App\Models\User;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function loanPageFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'America/La_Paz'));
    test()->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $user = User::factory()->create();
    $user->assignRole('cajera');
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);

    return [$admin, $user, $type];
}
function loanPageData($type): array
{
    return ['ci' => 'CI-123', 'name' => 'Cliente del formulario', 'type_id' => $type->id, 'type_revision' => $type->revision, 'description' => 'Garrafa de prueba', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'delivery_method' => 'CASH', 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()];
}
it('C209 persists the 15 day selection from the actual form and reprints the same amounts without duplicate disbursement', function () {
    [$admin,$user,$type] = loanPageFixture();
    $this->actingAs($user);
    $page = Livewire::test(RegisterLoan::class)->assertSee('Capital disponible')->assertSee('Cliente')->assertSee('Objeto')->assertSee('Fechas y plazo')->fillForm(loanPageData($type));
    $page->call('register')->assertHasNoErrors()->callMountedAction()->assertHasNoErrors()->assertActionMounted('registeredLoan');
    $loan = Loan::sole();
    expect($loan->original_days)->toBe(15)->and($loan->original_interest_cents)->toBe(1500)->and($loan->original_due_date->format('Y-m-d'))->toBe('2026-10-15');
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk()->assertSee('15 días')->assertSee('Bs 15')->assertSee('Bs 215')->assertSee('2026-10-15')->assertSee($user->name);
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk();
    expect(Loan::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(80000);
    Livewire::test(LoanList::class)->assertActionHidden('settings')->assertCanSeeTableRecords([$loan])->mountTableAction('detail', $loan)->assertMountedActionModalSee('15 días')->assertMountedActionModalSee('Bs 215')->assertMountedActionModalSee('Cliente')->assertMountedActionModalSee('Objeto en garantía')->assertMountedActionModalSee('Datos originales del préstamo')->assertMountedActionModalSee('Número de recibo')->assertMountedActionModalSee('Fecha y hora de registro');
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $this->actingAs($other);
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk();
    $this->get(LoanSettings::getUrl())->assertForbidden();
});
it('C209 shows an insufficient capital error and keeps an incomplete form free of records', function () {
    [$admin,$user,$type] = loanPageFixture();
    $this->actingAs($user);
    Livewire::test(RegisterLoan::class)->fillForm(array_replace(loanPageData($type), ['capital' => '1001']))->call('register')->assertHasErrors(['data.capital']);
    Livewire::test(RegisterLoan::class)->fillForm(array_replace(loanPageData($type), ['description' => '']))->call('register')->assertHasErrors(['data.description']);
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});
it('C209 admin configures objects and real funds while cashier cannot change either', function () {
    [$admin,$user,$type] = loanPageFixture();
    $this->actingAs($admin);
    Livewire::test(LoanObjectTypes::class)->callAction('createType', data: ['name' => 'Laptop', 'rate' => '12', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    Livewire::test(LoanSettings::class)->callAction('fund', data: ['kind' => 'IN', 'amount' => '2000', 'confirmed' => true, 'reference' => 'Aporte Yape', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    expect(LoanObjectType::count())->toBe(2)->and(LoanFund::sole()->balance_cents)->toBe(300000);
    $this->actingAs($user);
    Livewire::test(LoanList::class)->assertSee('Capital disponible')->assertActionHidden('settings');
});
it('C209 serves only the configured QR privately and rejects stale account changes', function () {
    [$admin,$user,$type] = loanPageFixture();
    Storage::fake('local');
    $path = 'loan-qr/test.png';
    Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6eAAAAABJRU5ErkJggg=='));
    $data = ['bank' => 'Yape Préstamos', 'number' => 'TEST-ACCOUNT', 'holder' => 'Titular de prueba', 'qr_path' => $path, 'expected_revision' => LoanFund::sole()->revision, 'request_key' => (string) Str::uuid()];
    app(SaveLoanAccountAction::class)->execute($admin, $data);
    $this->actingAs($user);
    $response = $this->get(route('prestamos.qr'))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->get(route('prestamos.qr', ['revision' => 0]))->assertConflict();
    Storage::disk('local')->put($path, 'tampered');
    $this->get(route('prestamos.qr'))->assertNotFound();
    expect(fn () => app(SaveLoanAccountAction::class)->execute($admin, array_replace($data, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
});

it('C210 exposes renewal preview and freezes its receipt after a later extension', function () {
    [$admin,$user,$type] = loanPageFixture();
    $this->actingAs($user);
    $loan = app(RegisterLoanAction::class)->execute($user, loanPageData($type));
    $key = (string) Str::uuid();
    Livewire::test(LoanList::class)->mountTableAction('renew', $loan)->assertMountedActionModalSee('A pagar hoy')->assertMountedActionModalSee('Bs 15')->setTableActionData(['days' => 30, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'reference' => '<script>x</script>', 'request_key' => $key])->callMountedTableAction()->assertHasNoTableActionErrors();
    $event = LoanTermChange::sole();
    Livewire::test(LoanList::class)->mountTableAction('detail', $loan)->assertMountedActionModalSee('Datos originales del préstamo')->assertMountedActionModalSee('2026-10-15')->assertMountedActionModalSee('Situación vigente')->assertMountedActionModalSee('2026-11-14');
    $url = route('prestamos.renovacion-comprobante', ['change' => $event->id]);
    $response = $this->get($url)->assertOk()->assertSee('2026-11-14')->assertSee('Bs 30')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>x</script>', false);
    $html = $response->getContent();
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk()->assertSee('2026-10-15')->assertSee('Bs 215');
    app(ChangeLoanTermAction::class)->execute($user, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => $event->id, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect($this->get($url)->assertOk()->getContent())->toBe($html)->and(Loan::count())->toBe(1);
    Livewire::test(LoanTermHistory::class, ['loan' => $loan->id])->assertCanSeeTableRecords([$event])->assertSee('Interés pendiente');
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $this->actingAs($other);
    $this->get($url)->assertOk();
});
it('C210 extends through the actual action without charging or writing on cancellation', function () {
    [$admin,$user,$type] = loanPageFixture();
    $this->actingAs($user);
    $loan = app(RegisterLoanAction::class)->execute($user, loanPageData($type));
    Livewire::test(LoanList::class)->mountAction([TestAction::make('detail')->table($loan), TestAction::make('extend')])->assertMountedActionModalSee('A pagar hoy')->assertMountedActionModalSee('Bs 15')->unmountTableAction();
    expect(LoanTermChange::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(80000);
    Livewire::test(LoanList::class)->callAction([TestAction::make('detail')->table($loan), TestAction::make('extend')], data: ['expected_revision' => 0, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertHasNoErrors();
    $event = LoanTermChange::sole();
    expect($event->summary['pending_cents'])->toBe(1500)->and($loan->fresh()->due_date->toDateString())->toBe('2026-10-30');
    Livewire::test(LoanList::class)->assertActionHidden([TestAction::make('detail')->table($loan->fresh()), TestAction::make('extend')]);
});
it('C210 pages through older term changes and rejects forged or revoked actions', function () {
    [$admin,$user,$type] = loanPageFixture();
    $this->actingAs($user);
    $loan = app(RegisterLoanAction::class)->execute($user, loanPageData($type));
    $revision = 0;
    $events = [];
    for ($i = 0; $i < 6; $i++) {
        $e = app(ChangeLoanTermAction::class)->execute($user, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => $revision, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
        $revision = $e->id;
        $events[] = $e;
    }
    Livewire::test(LoanTermHistory::class, ['loan' => $loan->id])->assertCanSeeTableRecords(array_slice($events, 1))->assertCanNotSeeTableRecords([$events[0]])->call('gotoPage', 2)->assertCanSeeTableRecords([$events[0]]);
    $user->restrictedPermissions()->attach(Permission::findByName('prestamos.renovar'));
    expect(fn () => app(ChangeLoanTermAction::class)->execute($user, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => $revision, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    $user->is_active = false;
    $user->save();
    $this->get(route('prestamos.renovacion-comprobante', ['change' => $events[0]->id]))->assertForbidden();
});

it('C210 gives Administration the complete paginated fund ledger and keeps it private', function () {
    [$admin,$user,$type] = loanPageFixture();
    for ($i = 0; $i < 6; $i++) {
        app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'IN', 'amount' => '1', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    }
    $this->actingAs($admin);
    $old = LoanMovement::oldest('id')->first();
    $new = LoanMovement::latest('id')->first();
    Livewire::test(LoanCapitalHistory::class)->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old])->call('gotoPage', 2)->assertCanSeeTableRecords([$old]);
    $this->actingAs($user);
    $this->get(LoanCapitalHistory::getUrl())->assertForbidden();
});

it('C213 generates stable identifiers for the other prefilled loan forms without manual hidden values', function () {
    [$admin,$user,$type] = loanPageFixture();
    $this->actingAs($admin);
    Livewire::test(LoanSettings::class)->callAction('account', data: ['bank' => 'Yape prueba', 'number' => 'TEST-NATURAL', 'holder' => 'Titular prueba'])->assertHasNoActionErrors();
    expect(LoanFund::sole()->number)->toBe('TEST-NATURAL');
    $this->actingAs($user);
    $loan = app(RegisterLoanAction::class)->execute($user, loanPageData($type));
    Livewire::test(LoanList::class)->callTableAction('renew', $loan, data: ['days' => 30, 'periods' => 1, 'payment_confirmed' => true])->assertHasNoTableActionErrors();
    expect(LoanTermChange::sole()->summary['paid_cents'])->toBe(1500)->and(Loan::count())->toBe(1);
});

it('C235 allows another cashier to find renew and settle a loan keeping each responsible actor', function () {
    [$admin, $first, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($first, loanPageData($type));
    $second = User::factory()->create();
    $second->assignRole('cajera');
    $this->actingAs($second);
    Livewire::test(LoanList::class)->assertCanSeeTableRecords([$loan])->searchTable('Cliente del formulario')->assertCanSeeTableRecords([$loan]);
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk();
    Livewire::test(LoanList::class)->callTableAction('renew', $loan, data: ['days' => 15, 'periods' => 1, 'payment_confirmed' => true])->assertHasNoTableActionErrors();
    expect(LoanTermChange::sole()->user_id)->toBe($second->id)->and($loan->fresh()->cashier_snapshot['id'])->toBe($first->id);
    Livewire::test(LoanList::class)->callTableAction('settle', $loan->fresh(), data: ['payment_confirmed' => true, 'delivery_confirmed' => true])->assertHasNoTableActionErrors()->assertTableActionHidden('settle', $loan->fresh())->assertTableActionHidden('renew', $loan->fresh());
    expect(LoanSettlement::sole()->user_id)->toBe($second->id)->and(LoanFund::sole()->balance_cents)->toBe(103000);
    $this->get(LoanSettings::getUrl())->assertForbidden();
});

it('C235 presents the collection total after credits and zero after settlement without exposing the Yape account', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $this->actingAs($cashier);
    $calc = app(LoanTermCalculator::class);
    expect($calc->amountToCollect($loan))->toBe(21500);
    $event = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, ['kind' => 'EXTEND', 'days' => 30, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect($calc->amountToCollect($loan->fresh()))->toBe(21500);
    Livewire::test(LoanList::class)->assertSee('Total para recoger')->assertDontSee('Cuenta Yape')->assertSee('Capital disponible')->assertDontSee('Interés pendiente');
    app(SettleLoanAction::class)->execute($cashier, $loan->id, ['expected_revision' => $event->id, 'expected_paid_cents' => 21500, 'payment_confirmed' => true, 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect($calc->amountToCollect($loan->fresh()))->toBe(0);
    $loan2 = app(RegisterLoanAction::class)->execute($cashier, array_replace(loanPageData($type), ['ci' => 'OTRO-235', 'request_key' => (string) Str::uuid()]));
    $this->travelTo(CarbonImmutable::parse('2026-10-16 00:00', 'America/La_Paz'));
    expect($calc->amountToCollect($loan2->fresh()))->toBe(25500);
});

it('C235 still denies revoked loan access and preserves action-specific permissions across cashiers', function () {
    [$admin, $first, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($first, loanPageData($type));
    $second = User::factory()->create();
    $second->assignRole('cajera');
    $second->restrictedPermissions()->attach(Permission::findByName('prestamos.renovar'));
    $this->actingAs($second);
    Livewire::test(LoanList::class)->assertCanSeeTableRecords([$loan])->assertTableActionHidden('renew', $loan);
    expect(fn () => app(ChangeLoanTermAction::class)->execute($second, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    $second->restrictedPermissions()->attach(Permission::findByName('prestamos.ver'));
    $this->actingAs($second->fresh());
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertForbidden();
});

it('C237 limits annulment to Administration and recovers the original capital once', function (string $role) {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $operator = User::factory()->create();
    $operator->assignRole($role);
    $data = ['capital_returned' => true, 'reason' => 'Registro duplicado de prueba', 'request_key' => (string) Str::uuid()];
    $action = app(AnnulLoanAction::class);
    if ($role === 'encargado') {
        $operator->givePermissionTo('prestamos.anular');
        expect(fn () => $action->execute($operator, $loan->id, $data))->toThrow(AuthorizationException::class);
        expect(LoanFund::sole()->balance_cents)->toBe(80000)->and($loan->fresh()->status)->toBe('ACTIVO');

        return;
    }
    $event = $action->execute($operator, $loan->id, $data);
    expect($action->execute($operator, $loan->id, $data)->id)->toBe($event->id);
    expect($loan->fresh()->status)->toBe('ANULADO')->and(LoanFund::sole()->balance_cents)->toBe(100000)->and(LoanMovement::where('kind', 'ANNULMENT')->count())->toBe(1)->and($event->user_id)->toBe($operator->id);
    expect($loan->fresh()->cashier_snapshot['id'])->toBe($cashier->id)->and($loan->fresh()->original_interest_cents)->toBe(1500);
    expect(fn () => $action->execute($operator, $loan->id, array_replace($data, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);
    $this->actingAs($cashier);
    Livewire::test(LoanList::class)->filterTable('status', 'ANULADO')->assertCanSeeTableRecords([$loan])->assertTableActionHidden('annul', $loan)->assertTableActionHidden('settle', $loan);
    $this->get(route('prestamos.recibo-pdf', ['loan' => $loan->id]))->assertConflict();
    $this->get(LoanRecord::getUrl(['loan' => $loan->id]))->assertOk()->assertSee('Anulado por')->assertSee($operator->name)->assertSee('Registro duplicado de prueba');
})->with(['administrador', 'encargado']);

it('C235 forbids cashier annulment even with the permission and requires real capital return', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $action = app(AnnulLoanAction::class);
    $data = ['capital_returned' => true, 'request_key' => (string) Str::uuid()];
    $cashier->givePermissionTo('prestamos.anular');
    expect(fn () => $action->execute($cashier, $loan->id, $data))->toThrow(AuthorizationException::class);
    expect(fn () => $action->execute($admin, $loan->id, array_replace($data, ['capital_returned' => false])))->toThrow(ValidationException::class);
    expect(LoanFund::sole()->balance_cents)->toBe(80000)->and($loan->fresh()->status)->toBe('ACTIVO');
    $this->actingAs($admin);
    Livewire::test(LoanList::class)->callTableAction('annul', $loan, data: $data)->assertHasNoTableActionErrors()->assertTableActionHidden('annul', $loan->fresh())->assertTableActionHidden('settle', $loan->fresh());
    expect(LoanFund::sole()->balance_cents)->toBe(100000);
});

it('C235 refuses simple annulment after prior renewal or full payment and records no fictitious refund', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $action = app(AnnulLoanAction::class);
    expect(fn () => $action->execute($admin, $loan->id, ['capital_returned' => true, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $loan2 = app(RegisterLoanAction::class)->execute($cashier, array_replace(loanPageData($type), ['request_key' => (string) Str::uuid()]));
    app(SettleLoanAction::class)->execute($cashier, $loan2->id, ['payment_confirmed' => true, 'delivery_confirmed' => true, 'expected_revision' => 0, 'expected_paid_cents' => 21500, 'request_key' => (string) Str::uuid()]);
    expect(fn () => $action->execute($admin, $loan2->id, ['capital_returned' => true, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(LoanMovement::where('kind', 'ANNULMENT')->count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(83000);
});

it('C235 rolls back an interrupted annulment then retries only once', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $data = ['capital_returned' => true, 'request_key' => (string) Str::uuid()];
    DB::statement("CREATE TRIGGER fail_annul BEFORE INSERT ON loan_commands WHEN NEW.kind = 'ANNULMENT' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    $action = app(AnnulLoanAction::class);
    expect(fn () => $action->execute($admin, $loan->id, $data))->toThrow(QueryException::class);
    expect($loan->fresh()->status)->toBe('ACTIVO')->and(LoanFund::sole()->balance_cents)->toBe(80000)->and(LoanAnnulment::count())->toBe(0);
    DB::statement('DROP TRIGGER fail_annul');
    $action->execute($admin, $loan->id, $data);
    expect(LoanFund::sole()->balance_cents)->toBe(100000);
});

it('C236 restores the overview and authorized shortcuts while keeping the payment account out of the list', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $this->actingAs($admin);
    Livewire::test(LoanList::class)->assertSee('Capital disponible')->assertSee('Activos')->assertSee('Vencidos')->assertSee('Objetos perdidos')->assertSee('Total registrados')->assertSee('Bs 1000')->assertActionVisible('clients')->assertActionVisible('objects')->assertActionVisible('settings')->assertActionVisible('new')->assertDontSee('pg-loan-account', false);
    $this->actingAs($cashier);
    Livewire::test(LoanList::class)->assertSee('Bs 1000')->assertActionVisible('clients')->assertActionVisible('new')->assertActionHidden('objects')->assertActionHidden('settings')->assertDontSee('pg-loan-account', false);
    expect(LoanMovement::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});

it('C236 counts all registered loans separately from current active late and lost objects', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $action = app(RegisterLoanAction::class);
    $active = $action->execute($cashier, array_replace(loanPageData($type), ['days' => 30]));
    $late = $action->execute($cashier, loanPageData($type));
    $lost = $action->execute($admin, array_replace(loanPageData($type), ['start_date' => '2026-09-20']));
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'America/La_Paz'));
    $summary = app(LoanQuery::class)->summary($cashier);
    expect($summary['active'])->toBe(1)->and($summary['late'])->toBe(1)->and($summary['lost'])->toBe(1)->and($summary['total'])->toBe(3)->and($summary['available_cents'])->toBe(40000);
    app(AnnulLoanAction::class)->execute($admin, $active->id, ['capital_returned' => true, 'request_key' => (string) Str::uuid()]);
    $summary = app(LoanQuery::class)->summary($cashier);
    expect($summary['total'])->toBe(3)->and($summary['lost'])->toBe(1)->and($summary['active'])->toBe(0)->and($summary['late'])->toBe(1)->and($summary['available_cents'])->toBe(60000);
});

it('C237 disables cancellation renewal and ordinary payment after total loss while keeping the detail and receipt', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $this->travelTo(CarbonImmutable::parse('2026-10-23 00:00', 'America/La_Paz'));
    $this->actingAs($admin);
    Livewire::test(LoanList::class)->assertTableActionHidden('renew', $loan)->assertTableActionHidden('settle', $loan)->assertTableActionDisabled('annul', $loan)->mountTableAction('detail', $loan)->assertMountedActionModalSee('Pérdida total')->assertMountedActionModalSee('Imprimir recibo vigente');
    expect(fn () => app(AnnulLoanAction::class)->execute($admin, $loan->id, ['capital_returned' => true, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(LoanFund::sole()->balance_cents)->toBe(80000);
});

it('C237 operates repairs and sales from the shared detail without revealing profit to the cashier', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, array_replace(loanPageData($type), ['days' => 30]));
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00', 'America/La_Paz'));
    $this->actingAs($admin);
    Livewire::test(LoanList::class)->callTableAction('objectExpense', $loan, data: ['amount' => '200', 'description' => 'Reparación de prueba', 'payment_confirmed' => true])->assertHasNoTableActionErrors();
    Livewire::test(LoanList::class)->mountTableAction('sellObject', $loan)->assertMountedActionModalSee('Bs 470')->assertMountedActionModalSee('Ganancia del préstamo')->unmountTableAction();
    expect(LoanFund::sole()->balance_cents)->toBe(60000)->and(LoanObjectSale::count())->toBe(0);
    $this->actingAs($cashier);
    Livewire::test(LoanList::class)->assertTableActionHidden('objectExpense', $loan)->mountTableAction('sellObject', $loan)->assertMountedActionModalDontSee('Ganancia del préstamo')->unmountTableAction();
    Livewire::test(LoanList::class)->callTableAction('sellObject', $loan, data: ['price' => '600', 'buyer' => 'Comprador de prueba', 'sale_notes' => 'Objeto revisado', 'payment_confirmed' => true])->assertHasNoTableActionErrors()->assertTableActionHidden('sellObject', $loan->fresh())->assertTableActionHidden('renew', $loan->fresh());
    expect($loan->fresh()->status)->toBe('VENDIDO')->and(LoanFund::sole()->balance_cents)->toBe(120000);
    $response = $this->get(route('prestamos.recibo-pdf', ['loan' => $loan->id]))->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('content-disposition', 'inline; filename=Venta_Objeto_'.$loan->id.'.pdf');
    if ($directory = getenv('LOAN_C237_QA_DIR')) {
        file_put_contents($directory.'/venta.pdf', $response->getContent());
    }
    $this->get(route('prestamos.recibo-pdf', ['loan' => $loan->id, 'original' => 1]))->assertOk();
});

it('C237 reviews the late interest and penalty in the actual renewal modal and prints the saved event', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00', 'America/La_Paz'));
    $this->actingAs($cashier);
    Livewire::test(LoanList::class)->mountTableAction('renew', $loan)->assertMountedActionModalSee('multa fija de Bs 40')->assertMountedActionModalSee('Bs 55')->callMountedTableAction()->assertHasTableActionErrors(['payment_confirmed'])->setTableActionData(['payment_confirmed' => true])->callMountedTableAction()->assertHasNoTableActionErrors();
    $event = LoanTermChange::sole();
    $response = $this->get(route('prestamos.renovacion-pdf', ['change' => $event->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
    if ($directory = getenv('LOAN_C237_QA_DIR')) {
        file_put_contents($directory.'/renovacion-con-multa.pdf', $response->getContent());
    }
    expect($event->summary['paid_cents'])->toBe(5500)->and($loan->fresh()->due_date->toDateString())->toBe('2026-10-30');
    $loan2 = app(RegisterLoanAction::class)->execute($admin, array_replace(loanPageData($type), ['request_key' => (string) Str::uuid(), 'start_date' => '2026-10-01']));
    $this->actingAs($cashier);
    Livewire::test(LoanList::class)->callTableAction('settle', $loan2, data: ['payment_confirmed' => true, 'delivery_confirmed' => true])->assertHasNoTableActionErrors();
    $response = $this->get(route('prestamos.recibo-pdf', ['loan' => $loan2->id]))->assertOk();
    if ($directory = getenv('LOAN_C237_QA_DIR')) {
        file_put_contents($directory.'/pago-con-multa.pdf', $response->getContent());
    }
});

it('C237 refreshes the confirmed amount when the cashier changes renewal periods', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00', 'America/La_Paz'));
    $this->actingAs($cashier);
    $page = Livewire::test(LoanList::class)->mountTableAction('renew', $loan)->set('mountedActions.0.data.days', 30)->set('mountedActions.0.data.periods', 4)->assertMountedActionModalSee('Bs 145')->set('mountedActions.0.data.payment_confirmed', true)->callMountedTableAction()->assertHasNoTableActionErrors();
    expect(LoanTermChange::sole()->summary['paid_cents'])->toBe(14500)->and(LoanTermChange::sole()->summary['late_fee_cents'])->toBe(4000);
});

it('C238 separates early payment date from the renewed period and keeps each receipt on one loan', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:35', 'America/La_Paz'));
    $first = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $other = User::factory()->create(['name' => 'Cajera del relevo']);
    $other->assignRole('cajera');
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:20', 'America/La_Paz'));
    $second = app(ChangeLoanTermAction::class)->execute($other, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => $first->id, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $history = app(LoanRenewalHistoryData::class)->present($first);
    expect($history['paid_at'])->toBe('13/10/2026 09:35')->and($history['period_from'])->toBe('16/10/2026')->and($history['period_to'])->toBe('30/10/2026')->and($history['late_fee_cents'])->toBe(0)->and($history['interest_paid_cents'])->toBe(1500)->and($history['registered_by'])->toBe($cashier->name);
    $this->actingAs($other);
    Livewire::test(LoanRenewalHistory::class, ['loanId' => $loan->id])->assertCanSeeTableRecords([$first, $second])->assertSee('13/10/2026 09:35')->assertSee('16/10/2026')->assertSee('30/10/2026')->assertSee('Cajera del relevo');
    $this->get(LoanRecord::getUrl(['loan' => $loan->id]))->assertOk()->assertDontSeeLivewire(LoanRenewalHistory::class);
    $this->get(route('prestamos.renovacion-comprobante', ['change' => $first->id]))->assertOk()->assertSee('2026-10-30')->assertDontSee('2026-11-14');
    expect(Loan::count())->toBe(1)->and(LoanTermChange::count())->toBe(2)->and(LoanFund::sole()->balance_cents)->toBe(83000)->and($loan->fresh()->original_due_date->toDateString())->toBe('2026-10-15');
});

it('C238 pages older renewals inside the detail and rechecks read permission', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $events = [];
    $revision = 0;
    for ($i = 0; $i < 6; $i++) {
        $event = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => $revision, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
        $events[] = $event;
        $revision = $event->id;
    }
    $this->actingAs($cashier);
    $page = Livewire::test(LoanRenewalHistory::class, ['loanId' => $loan->id])->assertCanSeeTableRecords(array_slice($events, 1))->assertCanNotSeeTableRecords([$events[0]]);
    $page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName())->assertCanSeeTableRecords([$events[0]]);
    $cashier->restrictedPermissions()->attach(Permission::findByName('prestamos.ver'));
    $page->call('gotoPage', 1, $page->instance()->getTablePaginationPageName())->assertForbidden();
    expect(Loan::count())->toBe(1);
});

it('C238 refreshes the embedded history after a successful renewal without creating another loan', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $this->actingAs($cashier);
    $history = Livewire::test(LoanRenewalHistory::class, ['loanId' => $loan->id])->assertSee('Sin renovaciones registradas');
    Livewire::test(LoanList::class)->callTableAction('renew', $loan, data: ['payment_confirmed' => true])->assertHasNoTableActionErrors()->assertDispatched('loan-term-changed', loanId: $loan->id);
    $event = LoanTermChange::sole();
    $history->dispatch('loan-term-changed', loanId: $loan->id)->assertCanSeeTableRecords([$event]);
    expect(Loan::count())->toBe(1)->and(LoanTermChange::count())->toBe(1);
});

it('C239 checks early and late renewals against saved receipts and retries on one loan', function (int $originalDays, int $newDays, string $when, int $fee, string $oldDue, string $newDue) {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, array_replace(loanPageData($type), ['days' => $originalDays]));
    $this->travelTo(CarbonImmutable::parse($when, 'America/La_Paz'));
    $interestPaid = $originalDays === 15 ? 1500 : 3000;
    $nextInterest = $newDays === 15 ? 1500 : 3000;
    $data = ['kind' => 'RENEW', 'days' => $newDays, 'periods' => 1, 'expected_revision' => 0, 'expected_paid_cents' => $interestPaid + $fee, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()];

    $action = app(ChangeLoanTermAction::class);
    $event = $action->execute($cashier, $loan->id, $data);
    $replayed = $action->execute($cashier, $loan->id, $data);

    expect($event->summary)->toMatchArray(['capital_cents' => 20000, 'rate_bps' => 1500, 'days' => $newDays, 'interest_paid_cents' => $interestPaid, 'late_fee_cents' => $fee, 'paid_cents' => $interestPaid + $fee, 'pending_cents' => $nextInterest, 'previous_due_date' => $oldDue, 'due_date' => $newDue]);
    expect($replayed->id)->toBe($event->id);
    expect($loan->fresh()->due_date->toDateString())->toBe($newDue);
    expect($loan->fresh()->original_days)->toBe($originalDays);
    expect($loan->fresh()->original_due_date->toDateString())->toBe($oldDue);
    expect(LoanFund::sole()->balance_cents)->toBe(80000 + $interestPaid + $fee);
    expect(LoanMovement::where('kind', 'TERM_PAYMENT')->count())->toBe(1);
    $this->assertDatabaseCount('loans', 1);
    $this->assertDatabaseCount('loan_term_changes', 1);
    expect(fn () => $action->execute($cashier, $loan->id, array_replace($data, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('loan_term_changes', 1);

    $this->actingAs($cashier);
    $receiptUrl = route('prestamos.renovacion-comprobante', ['change' => $event->id]);
    $receipt = $this->get($receiptUrl)->assertOk()->assertSee($oldDue)->assertSee($newDue)
        ->assertSee('<strong>Periodo confirmado:</strong> '.$newDays.' d', false)
        ->assertSee('Bs '.Bolivianos::formatCompact($interestPaid + $fee))
        ->assertSee('Bs '.Bolivianos::formatCompact($nextInterest));
    $html = $receipt->getContent();
    $history = app(LoanRenewalHistoryData::class)->present($event);
    expect($history['paid_at'])->toBe(CarbonImmutable::parse($when, 'America/La_Paz')->format('d/m/Y H:i'));
    expect($history['period_to'])->toBe(CarbonImmutable::parse($newDue)->format('d/m/Y'));
    $pdf = $this->get(route('prestamos.renovacion-pdf', ['change' => $event->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 5))->toBe('%PDF-');
    if ($directory = getenv('LOAN_C239_QA_DIR')) {
        $name = $originalDays.'-'.$newDays.'-'.str_replace([' ', ':'], '-', $when);
        file_put_contents($directory.'/'.$name.'.pdf', $pdf->getContent());
        file_put_contents($directory.'/'.$name.'.json', json_encode($event->summary, JSON_THROW_ON_ERROR));
    }

    $later = $action->execute($cashier, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => $event->id, 'expected_paid_cents' => $nextInterest, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect($later->summary['late_fee_cents'])->toBe(0);
    expect($this->get($receiptUrl)->assertOk()->getContent())->toBe($html);
    expect(LoanFund::sole()->balance_cents)->toBe(80000 + $interestPaid + $fee + $nextInterest);
    expect(LoanMovement::where('kind', 'TERM_PAYMENT')->count())->toBe(2);
    $this->assertDatabaseCount('loans', 1);
    $this->assertDatabaseCount('loan_term_changes', 2);
})->with([
    '15-15 early' => [15, 15, '2026-10-13 09:35:00', 0, '2026-10-15', '2026-10-30'],
    '15-30 early' => [15, 30, '2026-10-13 09:35:00', 0, '2026-10-15', '2026-11-14'],
    '30-15 early' => [30, 15, '2026-10-28 09:35:00', 0, '2026-10-30', '2026-11-14'],
    '30-30 early' => [30, 30, '2026-10-28 09:35:00', 0, '2026-10-30', '2026-11-29'],
    '15-15 final second' => [15, 15, '2026-10-15 23:59:59', 0, '2026-10-15', '2026-10-30'],
    '15-30 final second' => [15, 30, '2026-10-15 23:59:59', 0, '2026-10-15', '2026-11-14'],
    '30-15 final second' => [30, 15, '2026-10-30 23:59:59', 0, '2026-10-30', '2026-11-14'],
    '30-30 final second' => [30, 30, '2026-10-30 23:59:59', 0, '2026-10-30', '2026-11-29'],
    '15-15 late day one' => [15, 15, '2026-10-16 00:00:00', 4000, '2026-10-15', '2026-10-30'],
    '15-30 late day one' => [15, 30, '2026-10-16 00:00:00', 4000, '2026-10-15', '2026-11-14'],
    '30-15 late day one' => [30, 15, '2026-10-31 00:00:00', 4000, '2026-10-30', '2026-11-14'],
    '30-30 late day one' => [30, 30, '2026-10-31 00:00:00', 4000, '2026-10-30', '2026-11-29'],
    '15-15 late day seven' => [15, 15, '2026-10-22 23:59:59', 4000, '2026-10-15', '2026-10-30'],
    '15-30 late day seven' => [15, 30, '2026-10-22 23:59:59', 4000, '2026-10-15', '2026-11-14'],
    '30-15 late day seven' => [30, 15, '2026-11-06 23:59:59', 4000, '2026-10-30', '2026-11-14'],
    '30-30 late day seven' => [30, 30, '2026-11-06 23:59:59', 4000, '2026-10-30', '2026-11-29'],
]);

it('C239 refuses both renewal terms from the eighth late day without changing balances or receipts', function (int $originalDays, int $newDays, string $when) {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, array_replace(loanPageData($type), ['days' => $originalDays]));
    $this->travelTo(CarbonImmutable::parse($when, 'America/La_Paz'));
    $beforeDue = $loan->due_date->toDateString();

    expect(fn () => app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, ['kind' => 'RENEW', 'days' => $newDays, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);

    expect(LoanFund::sole()->balance_cents)->toBe(80000);
    expect($loan->fresh()->due_date->toDateString())->toBe($beforeDue);
    expect(LoanMovement::where('kind', 'TERM_PAYMENT')->count())->toBe(0);
    $this->assertDatabaseCount('loans', 1);
    $this->assertDatabaseCount('loan_term_changes', 0);
})->with([[15, 15, '2026-10-23 00:00:00'], [15, 30, '2026-10-23 00:00:00'], [30, 15, '2026-11-07 00:00:00'], [30, 30, '2026-11-07 00:00:00']]);

it('C240 opens one renewal history on demand without pushing down the loan detail', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, ['kind' => 'RENEW', 'days' => 30, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $this->actingAs($cashier);

    $this->get(LoanRecord::getUrl(['loan' => $loan->id]))->assertOk()->assertDontSeeLivewire(LoanRenewalHistory::class);
    $page = Livewire::test(LoanRecord::class, ['loan' => $loan->id])->assertActionVisible('termHistory');
    $page->mountAction('termHistory')->assertMountedActionModalSee('Historial de renovaciones')->assertMountedActionModalSee('14/11/2026');
    $page->unmountAction()->assertActionNotMounted();
    $list = Livewire::test(LoanList::class)->mountTableAction('detail', $loan)->assertMountedActionModalDontSee('Periodo añadido')->assertMountedActionModalDontSee('Pagos y renovaciones');
    $list->mountAction([TestAction::make('detail')->table($loan), TestAction::make('termHistory')])->assertMountedActionModalSee('Historial de renovaciones')->assertMountedActionModalSee('14/11/2026');
    $list->unmountAction()->assertMountedActionModalSee('Situación vigente')->assertMountedActionModalDontSee('Periodo añadido');
    expect(Loan::count())->toBe(1)->and(LoanTermChange::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(81500);
});

it('C241 renders the history accents correctly with and without renewals', function () {
    [$admin, $cashier, $type] = loanPageFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, loanPageData($type));
    $this->actingAs($cashier);
    Livewire::test(LoanRenewalHistory::class, ['loanId' => $loan->id])->assertSee('mismo préstamo #'.$loan->id)->assertSee('Operación')->assertSee('Periodo añadido')->assertSee('Atendió')->assertSee('se conservarán aquí')->assertDontSee('Ã');
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    Livewire::test(LoanRenewalHistory::class, ['loanId' => $loan->id])->assertSee('Renovación')->assertSee('Recibo de esta operación')->assertDontSee('Ã');
    expect(Loan::count())->toBe(1)->and(LoanTermChange::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(81500);
});
