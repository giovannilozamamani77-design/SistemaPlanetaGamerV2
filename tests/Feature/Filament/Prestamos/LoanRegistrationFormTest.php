<?php

use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Filament\Pages\Prestamos\LoanList;
use App\Filament\Pages\Prestamos\LoanRecord;
use App\Filament\Pages\Prestamos\LoanSettings;
use App\Filament\Pages\Prestamos\RegisterLoan;
use App\Infrastructure\Prestamos\LoanReceiptPdf;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
function registrationFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'America/La_Paz'));
    test()->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    test()->actingAs($cashier);

    return [$admin, $cashier, $type];
}
function naturalRegistration($type, int $days = 15)
{
    return Livewire::test(RegisterLoan::class)->set('data.ci', 'PRUEBA-214')->set('data.name', 'Cliente de prueba')->set('data.type_id', $type->id)->set('data.description', 'Garrafa azul sin accesorios')->set('data.capital', '200')->set('data.days', $days);
}
it('C214 previews and saves the selected term from visible fields without injecting hidden values', function (int $days, string $due, int $interest, int $total) {
    [$admin,$cashier,$type] = registrationFixture();
    $page = naturalRegistration($type, $days);
    $page->assertSee('Capital prestado')->assertSee('Interés · '.$days.' días')->assertSee('Total a pagar')->assertSee('Tasa base del objeto')->assertSee('15 %');
    expect($page->get('data.type_revision'))->toBe(1);
    $page->set('data.delivery_confirmed', true)->call('register')->assertHasNoErrors()->callMountedAction()->assertHasNoErrors();
    $loan = Loan::sole();
    expect($loan->original_days)->toBe($days)->and($loan->original_interest_cents)->toBe($interest)->and($loan->original_total_cents)->toBe($total)->and($loan->original_due_date->toDateString())->toBe($due)->and($loan->user_id)->toBe($cashier->id);
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk()->assertSee($days.' días')->assertSee('Bs '.($interest / 100))->assertSee('Bs '.($total / 100))->assertSee($due);
    $page->assertSet('data.ci', null)->assertSet('data.capital', null);
    expect(Loan::count())->toBe(1)->and(LoanMovement::where('kind', 'DISBURSEMENT')->count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(80000);
})->with([[15, '2026-10-15', 1500, 21500], [30, '2026-10-30', 3000, 23000]]);
it('C214 recalculates when switching term and object while preview remains read only', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $phone = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Teléfono', 'rate' => '20', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $page = naturalRegistration($type)->assertSee('Bs 215');
    $page->set('data.days', 30)->assertSee('Bs 230');
    $page->set('data.type_id', $phone->id)->assertSee('Bs 240')->assertSee('20 %')->set('data.days', 15)->assertSee('Bs 220');
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});
it('C214 refuses stale or inactive object selection without creating clients or disbursing capital', function (bool $active) {
    [$admin,$cashier,$type] = registrationFixture();
    $page = naturalRegistration($type);
    app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '20', 'is_active' => $active, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()], $type);
    $page->set('data.delivery_confirmed', true)->call('register')->assertHasErrors(['data.type_id']);
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
})->with([true, false]);

it('C218 blocks missing or insufficient capital in the natural form without orphan records', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $page = naturalRegistration($type);
    $page->set('data.capital', '')->call('register')->assertHasErrors(['data.capital']);
    $page->set('data.delivery_confirmed', true)->set('data.capital', '1001')->call('register')->assertHasErrors(['data.capital']);
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanMovement::where('kind', 'DISBURSEMENT')->count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});

it('C217 separates registered loans and registration in navigation and presents the form as detail cards', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $items = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems());
    $registered = $items->first(fn ($item) => $item->getLabel() === 'Préstamos registrados');
    $create = $items->first(fn ($item) => $item->getLabel() === 'Realizar préstamo');
    expect($registered)->not->toBeNull()->and($create)->not->toBeNull();
    expect($registered->getUrl())->toBe(LoanList::getUrl())->and($create->getUrl())->toBe(RegisterLoan::getUrl());
    $this->get($registered->getUrl())->assertOk()->assertSee('Préstamos registrados');
    $this->get($create->getUrl())->assertOk()->assertSee('Realizar préstamo')->assertSee('wire:submit="register"', false);
    Livewire::test(RegisterLoan::class)->assertSee('Cliente')->assertSee('Objeto en garantía')->assertSee('Fechas y plazo')->assertSee('Importes del préstamo')->assertSee('Confirmar préstamo')->assertDontSee('fi-sc-wizard', false)->assertActionDoesNotExist('history')->assertActionDoesNotExist('objects')->assertActionDoesNotExist('settings');
    expect(Loan::count())->toBe(0);
});
it('C215 preserves a read only history entry if registration permission is restricted', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $cashier->restrictedPermissions()->attach(Permission::findByName('prestamos.registrar'));
    $this->actingAs($cashier->fresh());
    $items = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems());
    expect($items->contains(fn ($item) => $item->getUrl() === RegisterLoan::getUrl()))->toBeFalse();
    expect($items->contains(fn ($item) => $item->getUrl() === LoanList::getUrl()))->toBeTrue();
    $this->get(RegisterLoan::getUrl())->assertForbidden();
    $this->get(LoanList::getUrl())->assertOk();
});
it('C215 links several loans to the same client and the correct type while preserving historical data', function () {
    [$admin,$cashier,$type] = registrationFixture();
    naturalRegistration($type)->set('data.delivery_confirmed', true)->call('register')->assertHasNoErrors()->callMountedAction()->assertHasNoErrors();
    $phone = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Teléfono', 'rate' => '20', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    naturalRegistration($phone, 30)->set('data.delivery_confirmed', true)->call('register')->assertHasNoErrors()->callMountedAction()->assertHasNoErrors();
    $loans = Loan::orderBy('id')->get();
    expect(Borrower::count())->toBe(1)->and($loans[0]->borrower_id)->toBe($loans[1]->borrower_id)->and($loans[0]->object_type_id)->toBe($type->id)->and($loans[1]->object_type_id)->toBe($phone->id);
    expect($loans[0]->original_interest_cents)->toBe(1500)->and($loans[1]->original_interest_cents)->toBe(4000)->and(LoanFund::sole()->balance_cents)->toBe(60000);
    expect(LoanMovement::where('kind', 'DISBURSEMENT')->orderBy('id')->pluck('loan_id')->all())->toBe($loans->pluck('id')->all());
    app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Otro nombre', 'rate' => '25', 'is_active' => false, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()], $type);
    expect($loans[0]->fresh()->object_snapshot['type_name'])->toBe('Garrafa')->and($loans[0]->fresh()->original_rate_bps)->toBe(1500);
});

it('C218 registers and prints the exact term without delivery selectors or extra confirmations', function (int $days, int $interest, int $total, string $due) {
    [$admin,$cashier,$type] = registrationFixture();
    $page = naturalRegistration($type, $days)->assertDontSee('Forma de entrega')->assertDontSee('Confirmo actualizar')->assertDontSee('Confirmo que entregué');
    $page->call('register')->assertHasNoErrors()->callMountedAction()->assertHasNoErrors();
    $loan = Loan::sole();
    expect($loan->original_days)->toBe($days)->and($loan->original_interest_cents)->toBe($interest)->and($loan->original_total_cents)->toBe($total)->and($loan->original_due_date->toDateString())->toBe($due);
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk()->assertSee($days.' días')->assertSee('Bs '.($interest / 100))->assertSee('Bs '.($total / 100))->assertSee($due);
    $page->assertSet('data.ci', null)->assertSet('data.capital', null);
    expect(Loan::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(80000);
})->with([[15, 1500, 21500, '2026-10-15'], [30, 3000, 23000, '2026-10-30']]);
it('C218 recognizes an existing CI and updates its contact automatically only when the loan saves', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $original = Borrower::create(['ci' => 'PRUEBA-214', 'name' => 'Nombre anterior', 'phone' => '70001111']);
    $page = naturalRegistration($type)->assertSet('data.phone', '70001111')->assertSee('Cliente reconocido')->set('data.name', 'Nombre actualizado')->set('data.phone', '70002222');
    expect($original->fresh()->name)->toBe('Nombre anterior');
    $page->call('register')->assertHasNoErrors()->callMountedAction()->assertHasNoErrors();
    expect(Borrower::count())->toBe(1)->and($original->fresh()->name)->toBe('Nombre actualizado')->and($original->fresh()->phone)->toBe('70002222')->and(Loan::sole()->borrower_id)->toBe($original->id);
});
it('C218 clears the previous recognized contact when the CI is replaced by a new one', function () {
    [$admin,$cashier,$type] = registrationFixture();
    Borrower::create(['ci' => 'EXISTS', 'name' => 'Cliente anterior', 'phone' => '70001111']);
    $page = Livewire::test(RegisterLoan::class)->set('data.ci', 'exists')->assertSet('data.name', 'Cliente anterior')->set('data.ci', 'NEW-CI')->assertSet('data.name', '')->assertSet('data.phone', '');
    expect(Borrower::count())->toBe(1)->and(Loan::count())->toBe(0);
});
it('C218 puts registration first and exposes the Yape capital section only to Administration', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $group = collect(Filament::getNavigation())->first(fn ($g) => $g->getLabel() === 'Préstamos');
    expect(collect($group->getItems())->first()->getLabel())->toBe('Realizar préstamo');
    $this->get(LoanSettings::getUrl())->assertForbidden();
});
it('C218 shows the Yape capital destination in the Administration menu', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $this->actingAs($admin);
    $items = collect(Filament::getNavigation())->flatMap(fn ($g) => $g->getItems());
    expect($items->contains(fn ($item) => $item->getLabel() === 'Capital en Yape'))->toBeTrue();
    $this->get(LoanSettings::getUrl())->assertOk()->assertSee('Capital en Yape de Préstamos');
});

it('C219 opens only the compact registration form without heading shortcuts or fund banners', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $page = Livewire::test(RegisterLoan::class);
    expect($page->instance()->getHeading())->toBeNull();
    $page->assertDontSee('fi-header-heading', false)->assertSee('Realizar préstamo')->assertSee('Responsable:')->assertSee(auth()->user()->name)->assertDontSee('Si el CI es nuevo')->assertSee('Capital disponible')->assertSee('Bs 1000')->assertDontSee('Falta saldo inicial')->assertDontSee('Opciones')->assertActionDoesNotExist('history')->assertActionDoesNotExist('settings')->assertSee('Cliente')->assertSee('Objeto en garantía')->assertSee('Fechas y plazo')->assertSee('Importes del préstamo')->assertSee('Confirmar préstamo');
});

it('C220 edits optional details in a modal and cancellation keeps the original draft', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $page = naturalRegistration($type);
    $page->callAction('objectDetails', data: ['serial' => 'IMEI-TEST', 'condition' => 'Pantalla intacta', 'accessories' => 'Cargador'])->assertHasNoActionErrors()->assertSet('data.serial', 'IMEI-TEST');
    $page->mountAction('objectDetails')->setActionData(['serial' => 'NO-GUARDAR'])->unmountAction()->assertSet('data.serial', 'IMEI-TEST');
    $page->set('data.phone', '70001234')->call('register')->assertActionMounted('confirmLoan')->assertMountedActionModalSee('IMEI-TEST')->assertMountedActionModalSee('Pantalla intacta')->assertMountedActionModalSee('Cargador')->assertMountedActionModalSee('+591 70001234');
    expect(Loan::count())->toBe(0);
});
it('C220 reviews the chosen object before saving and resets the form after successful registration', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $page = naturalRegistration($type)->call('register')->assertActionMounted('confirmLoan')->assertMountedActionModalSee('Garrafa')->assertMountedActionModalSee('revisar')->assertMountedActionModalSee('Cliente de prueba')->assertMountedActionModalSee('PRUEBA-214')->assertMountedActionModalSee('01/10/2026')->assertMountedActionModalSee('15/10/2026')->assertMountedActionModalSee('Capital prestado')->assertMountedActionModalSee('Interés del periodo')->assertMountedActionModalSee('Total a pagar')->assertMountedActionModalSee('Bs 200')->assertMountedActionModalSee('Bs 15')->assertMountedActionModalSee('Bs 215');
    expect(Loan::count())->toBe(0);
    $page->unmountAction();
    expect(Loan::count())->toBe(0);
    $page->call('register')->callMountedAction()->assertHasNoErrors()->assertActionMounted('registeredLoan')->assertSet('data.ci', null)->assertSet('data.capital', null)->assertSet('data.type_id', null);
    $loan = Loan::sole();
    expect(LoanFund::sole()->balance_cents)->toBe(80000);
    $page->unmountAction()->assertSee('Bs 0')->assertSee('Bs 800');
    $this->get(LoanRecord::getUrl(['loan' => $loan->id]))->assertOk()->assertSee('Garrafa');
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id, 'print' => 1]))->assertOk()->assertSee('window.print()', false);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $this->actingAs($other);
    $this->get(LoanRecord::getUrl(['loan' => $loan->id]))->assertOk();
    $other->restrictedPermissions()->attach(Permission::findByName('prestamos.ver'));
    $this->actingAs($other->fresh())->get(LoanRecord::getUrl(['loan' => $loan->id]))->assertForbidden();
});
it('C220 allows authorized historical dates and manual interest while still deducting capital once', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $this->actingAs($admin);
    $page = naturalRegistration($type)->set('data.days', 'MANUAL')->set('data.start_date', '2026-08-01')->set('data.due_date', '2026-09-10')->set('data.interest_mode', 'MANUAL')->set('data.manual_interest', '27.50');
    $page->call('register')->assertHasNoErrors()->assertActionMounted('confirmLoan')->assertMountedActionModalSee('01/08/2026')->assertMountedActionModalSee('10/09/2026')->assertMountedActionModalSee('41 días')->assertMountedActionModalSee('Fechas manuales')->assertMountedActionModalSee('Interés manual')->assertMountedActionModalSee('Bs 27.50')->assertMountedActionModalSee('Bs 227.50');
    expect(Loan::count())->toBe(0);
    $page->callMountedAction()->assertHasNoErrors()->assertActionMounted('registeredLoan');
    $loan = Loan::sole();
    expect($loan->original_days)->toBe(41)->and($loan->original_interest_cents)->toBe(2750)->and($loan->original_total_cents)->toBe(22750)->and($loan->original_due_date->toDateString())->toBe('2026-09-10')->and($loan->terms_snapshot['interest_mode'])->toBe('MANUAL')->and(LoanFund::sole()->balance_cents)->toBe(80000);
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk()->assertSee('41 días')->assertSee('Bs 27,50')->assertSee('2026-09-10');
});

it('C220 refuses changed data after the review and keeps the draft for correction', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $page = naturalRegistration($type)->call('register')->assertActionMounted('confirmLoan');
    $page->set('data.capital', '250')->callMountedAction()->assertHasErrors(['data.capital']);
    expect(Loan::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});
it('C220 replays two reviewed submissions with the same identifier without a second loan or debit', function () {
    [$admin,$cashier,$type] = registrationFixture();
    $first = naturalRegistration($type);
    $data = $first->get('data');
    $second = Livewire::test(RegisterLoan::class)->fillForm($data);
    $first->call('register')->assertActionMounted('confirmLoan');
    $second->call('register')->assertActionMounted('confirmLoan');
    $first->callMountedAction()->assertHasNoErrors();
    $second->callMountedAction()->assertHasNoErrors();
    expect(Loan::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(80000)->and(LoanMovement::where('kind', 'DISBURSEMENT')->count())->toBe(1);
});

it('C221 prints the saved original contract with separate amounts and exact dates', function (array $conditions, string $interest, string $total, string $due, string $schedule, string $mode) {
    [$admin, $cashier, $type] = registrationFixture();
    $loan = app(RegisterLoanAction::class)->execute($admin, array_replace([
        'ci' => 'RECIBO-221', 'name' => 'Cliente original', 'phone' => '70000001',
        'type_id' => $type->id, 'type_revision' => 1, 'description' => '<script>alert(1)</script> Garrafa azul',
        'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'request_key' => (string) Str::uuid(),
    ], $conditions));
    Borrower::findOrFail($loan->borrower_id)->update(['name' => 'Contacto modificado']);
    $type->update(['name' => 'Tipo modificado', 'rate_bps' => 2500]);
    Loan::whereKey($loan->id)->update(['due_date' => '2027-01-01']);
    $balance = LoanFund::sole()->balance_cents;
    $movements = LoanMovement::count();
    $this->actingAs($admin);
    $url = route('prestamos.comprobante', $loan->id);
    $response = $this->get($url)->assertOk()->assertSee('RECIBO DE PRÉSTAMO')->assertSee('declaro haber recibido de')->assertSee('Monto total en literal')->assertSee('Firma del prestatario')->assertSee('Responsable de la operación')->assertSee('logoReciboPrestamo.png')->assertSee('77712823')->assertSee('75885195')
        ->assertSee('Monto prestado')->assertSee('Interés del periodo')->assertSee('Monto total a pagar')
        ->assertSee('Bs '.str_replace('.', ',', $interest))->assertSee('Bs '.str_replace('.', ',', $total))->assertSee($due)
        ->assertSee('Cliente original')->assertSee('Garrafa azul')->assertDontSee('Contacto modificado')->assertDontSee('Tipo modificado')
        ->assertDontSee('2027-01-01')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('window.print()', false);
    expect(substr_count($response->getContent(), 'declaro haber recibido de'))->toBe(2);
    expect($loan->terms_snapshot['schedule_mode'])->toBe($schedule === 'Fechas manuales' ? 'MANUAL' : 'AUTO');
    expect($loan->terms_snapshot['interest_mode'])->toBe($mode === 'Interés manual' ? 'MANUAL' : 'AUTO');
    expect(substr_count($response->getContent(), 'Firma del prestatario'))->toBe(2);
    expect(substr_count($response->getContent(), 'Responsable de la operación'))->toBe(2);
    $literal = match ($total) {
        '215' => 'Doscientos quince bolivianos', '230' => 'Doscientos treinta bolivianos', '227.50' => 'Doscientos veintisiete bolivianos con cincuenta centavos', '200' => 'Doscientos bolivianos', '1' => 'Un boliviano', '1.01' => 'Un boliviano con un centavo'
    };
    $response->assertSee($literal);
    $this->get($url.'?print=1')->assertOk()->assertSee('window.print()', false);
    expect(LoanFund::sole()->balance_cents)->toBe($balance)->and(LoanMovement::count())->toBe($movements)->and(Loan::count())->toBe(1);
    $this->actingAs($cashier)->get($url)->assertOk();
    $cashier->restrictedPermissions()->attach(Permission::findByName('prestamos.ver'));
    $this->actingAs($cashier->fresh())->get($url)->assertForbidden();
})->with([
    'automatic 15' => [[], '15', '215', '2026-10-15', 'Fechas automáticas', 'Interés automático'],
    'automatic 30' => [['days' => 30], '30', '230', '2026-10-30', 'Fechas automáticas', 'Interés automático'],
    'manual decimal' => [['schedule_mode' => 'MANUAL', 'due_date' => '2026-11-10', 'interest_mode' => 'MANUAL', 'manual_interest' => '27.50'], '27.50', '227.50', '2026-11-10', 'Fechas manuales', 'Interés manual'],
    'manual zero' => [['schedule_mode' => 'MANUAL', 'due_date' => '2026-10-05', 'interest_mode' => 'MANUAL', 'manual_interest' => '0'], '0', '200', '2026-10-05', 'Fechas manuales', 'Interés manual'],
    'manual singular currency' => [['capital' => '1', 'interest_mode' => 'MANUAL', 'manual_interest' => '0'], '0', '1', '2026-10-15', 'Fechas automáticas', 'Interés manual'],
    'manual singular cent' => [['capital' => '1', 'interest_mode' => 'MANUAL', 'manual_interest' => '0.01'], '0.01', '1.01', '2026-10-15', 'Fechas automáticas', 'Interés manual'],
]);

it('C221 reprints a legacy original receipt after a real renewal without moving money', function () {
    [$admin, $cashier, $type] = registrationFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, [
        'ci' => 'LEGACY-221', 'name' => 'Cliente de archivo', 'type_id' => $type->id, 'type_revision' => 1,
        'description' => 'Garrafa de archivo', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'request_key' => (string) Str::uuid(),
    ]);
    Loan::whereKey($loan->id)->update(['terms_snapshot' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00', 'America/La_Paz'));
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, [
        'kind' => 'RENEW', 'days' => 30, 'periods' => 1, 'expected_revision' => 0, 'payment_confirmed' => true, 'reference' => '', 'request_key' => (string) Str::uuid(),
    ]);
    expect($loan->fresh()->due_date->toDateString())->toBe('2026-11-14');
    $balance = LoanFund::sole()->balance_cents;
    $count = LoanMovement::count();
    $this->get(route('prestamos.comprobante', $loan->id))->assertOk()->assertSee('2026-10-15')->assertSee('15 días')
        ->assertSee('Bs 15')->assertSee('Bs 215')->assertSee('Duración')->assertSee('Fecha de pago')->assertDontSee('2026-11-14');
    expect(LoanFund::sole()->balance_cents)->toBe($balance)->and(LoanMovement::count())->toBe($count);
});

it('C222 shows a fixed Bolivia prefix and separate automatic due date field', function () {
    registrationFixture();
    Livewire::test(RegisterLoan::class)->assertSee('Teléfono o WhatsApp')->assertSee('+591')->assertSee('Vencimiento')
        ->assertSee('2026-10-30')->assertDontSee('30 días · Inicio');
});

it('C222 rejects an invalid national phone before saving a loan', function (string $phone) {
    [$admin, $cashier, $type] = registrationFixture();
    $data = ['ci' => 'PHONE-222', 'name' => 'Cliente teléfono', 'phone' => $phone, 'type_id' => $type->id, 'type_revision' => 1,
        'description' => 'Garrafa', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'request_key' => (string) Str::uuid()];
    try {
        app(RegisterLoanAction::class)->execute($cashier, $data);
        $this->fail('Invalid phone accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('phone');
    }
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
})->with(['7000123', '700012345', '7000abcd', '+59170001234']);

it('C222 keeps automatic dates reactive and starts manual editing from the selected due date', function () {
    [$admin, $cashier, $type] = registrationFixture();
    $this->actingAs($admin);
    $page = naturalRegistration($type, 15)->assertSee('2026-10-15')->set('data.days', 30)->assertSee('2026-10-30')
        ->set('data.days', 'MANUAL')->assertSet('data.schedule_mode', 'MANUAL')->assertSet('data.due_date', '2026-10-30')
        ->set('data.due_date', '2026-10-20')->set('data.phone', '70001234')->call('register')->assertHasNoErrors();
    $page->callMountedAction()->assertHasNoErrors();
    expect(Loan::sole()->original_due_date->toDateString())->toBe('2026-10-20')->and(Loan::sole()->borrower_snapshot['phone'])->toBe('70001234');
});

it('C226 warns before confirmation when requested capital exceeds the fund', function (string $capital, bool $insufficient) {
    [$admin, $cashier, $type] = registrationFixture();
    $page = naturalRegistration($type)->set('data.capital', $capital);
    if ($insufficient) {
        $page->assertSee('Capital insuficiente')->assertSee('Faltan Bs 0.01')->call('register')->assertHasErrors(['data.capital'])->assertActionNotMounted('confirmLoan');
        $page->set('data.capital', '')->assertDontSee('Capital insuficiente')->set('data.capital', '1000')->assertDontSee('Capital insuficiente')->call('register')->assertHasNoErrors()->assertActionMounted('confirmLoan');
    } else {
        $page->assertDontSee('Capital insuficiente')->call('register')->assertHasNoErrors()->assertActionMounted('confirmLoan');
    }
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
})->with([['1000.01', true], ['1000', false], ['999.99', false]]);

it('C226 rechecks the fund after review when available capital changes', function () {
    [$admin, $cashier, $type] = registrationFixture();
    $page = naturalRegistration($type)->call('register')->assertActionMounted('confirmLoan');
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'OUT', 'amount' => '900', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $page->callMountedAction()->assertHasErrors(['data.capital'])->assertSee('Capital insuficiente')->assertSee('Faltan Bs 100');
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(10000);
});

it('C228 generates two signed copies in a single Letter PDF from saved terms', function (string $sample, array $conditions) {
    [$admin, $cashier, $type] = registrationFixture();
    $admin->update(['name' => 'Responsable de registro con dos apellidos compuestos de prueba']);
    $this->actingAs($admin);
    $loan = app(RegisterLoanAction::class)->execute($admin, array_replace([
        'ci' => 'PDF-PRUEBA', 'name' => 'Cliente de prueba PDF', 'phone' => '70001234', 'type_id' => $type->id, 'type_revision' => 1,
        'description' => 'Garrafa azul revisada', 'condition' => 'Buen estado', 'accessories' => 'Sin accesorios',
        'capital' => '350', 'days' => 30, 'start_date' => '2026-10-01', 'request_key' => (string) Str::uuid(),
    ], $conditions));
    $detail = app(LoanQuery::class)->detail($admin, $loan->id);
    expect($detail['receipt_number'])->toBe((string) $loan->id);
    $pdf = app(LoanReceiptPdf::class)->render($detail);
    $bytes = $pdf->output();
    if ($qaDirectory = getenv('LOAN_PDF_QA_DIR')) {
        file_put_contents($qaDirectory.'/prestamo-'.$sample.'.pdf', $bytes);
    }
    expect(str_starts_with($bytes, '%PDF-'))->toBeTrue()->and($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1);
    expect($pdf->getDomPDF()->getPaperSize())->toBe([0.0, 0.0, 612.0, 792.0]);
    expect($pdf->getDomPDF()->getOptions()->getIsRemoteEnabled())->toBeFalse()->and($pdf->getDomPDF()->getOptions()->getIsPhpEnabled())->toBeFalse()->and($pdf->getDomPDF()->getOptions()->getIsJavascriptEnabled())->toBeFalse();
    $balance = LoanFund::sole()->balance_cents;
    $count = LoanMovement::count();
    $response = $this->get(route('prestamos.recibo-pdf', $loan->id))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'inline; filename=Prestamo_'.$loan->id.'.pdf');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->actingAs($cashier)->get(route('prestamos.recibo-pdf', $loan->id))->assertOk();
    $cashier->restrictedPermissions()->attach(Permission::findByName('prestamos.ver'));
    $this->actingAs($cashier->fresh())->get(route('prestamos.recibo-pdf', $loan->id))->assertForbidden();
    auth()->logout();
    $this->get(route('prestamos.recibo-pdf', $loan->id))->assertRedirect();
    expect(LoanFund::sole()->balance_cents)->toBe($balance)->and(LoanMovement::count())->toBe($count);
})->with([
    'automatic' => ['automatico', []],
    'manual decimal' => ['manual', ['schedule_mode' => 'MANUAL', 'due_date' => '2026-11-10', 'interest_mode' => 'MANUAL', 'manual_interest' => '27.50']],
    'manual zero' => ['cero', ['schedule_mode' => 'MANUAL', 'due_date' => '2026-10-05', 'interest_mode' => 'MANUAL', 'manual_interest' => '0']],
]);

it('C228 retains long object details in two annexes instead of clipping signatures or text', function () {
    [$admin, $cashier, $type] = registrationFixture();
    $description = 'INICIO-ANEXO '.str_repeat('detalle del objeto ', 90).'FIN-ANEXO';
    $loan = app(RegisterLoanAction::class)->execute($cashier, [
        'ci' => 'ANEXO-228', 'name' => 'Cliente de prueba anexo', 'type_id' => $type->id, 'type_revision' => 1,
        'description' => $description, 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'request_key' => (string) Str::uuid(),
    ]);
    $detail = app(LoanQuery::class)->detail($cashier, $loan->id);
    expect($detail['receipt_has_annex'])->toBeTrue();
    $response = $this->get(route('prestamos.comprobante', $loan->id))->assertOk()->assertSee('INICIO-ANEXO')->assertSee('FIN-ANEXO');
    expect(substr_count($response->getContent(), 'Anexo del préstamo'))->toBe(2);
    $pdf = app(LoanReceiptPdf::class)->render($detail);
    $pdf->output();
    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBeGreaterThanOrEqual(3)->and(LoanFund::sole()->balance_cents)->toBe(80000);
});

it('C229 prints the approved contractual wording and freezes its receipt terms for later reprints', function () {
    [$admin, $cashier, $type] = registrationFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, [
        'ci' => 'CONTRATO-229', 'name' => 'Cliente del contrato', 'type_id' => $type->id, 'type_revision' => 1,
        'description' => 'Garrafa azul', 'capital' => '350', 'days' => 30, 'start_date' => '2026-10-01', 'request_key' => (string) Str::uuid(),
    ]);
    config(['prestamos.receipt.lender_name' => 'Otro titular posterior', 'prestamos.receipt.late_fee_cents' => 5000]);
    $response = $this->get(route('prestamos.comprobante', $loan->id))->assertOk();
    $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($response->getContent()), ENT_QUOTES, 'UTF-8'));
    expect($text)->toContain('Yo, Cliente del contrato, con CI CONTRATO-229, en calidad de prestatario, declaro haber recibido de Cristian Loza Mamani')
        ->toContain('la cantidad de Bs 350,00 en calidad de préstamo')
        ->toContain('Fecha de pago: 30 de octubre de 2026')->toContain('Bs 402,50')
        ->toContain('tendrá 7 días adicionales para hacerlo y se aplicará una multa de Bs 40,00')
        ->toContain('el objeto en garantía quedará a favor del prestamista como compensación por el préstamo')
        ->toContain('El prestatario declara haber revisado la información del presente recibo y acepta las condiciones')
        ->not->toContain('Otro titular posterior');
    expect($loan->fresh()->terms_snapshot['receipt_terms']['late_fee_cents'])->toBe(4000)->and(Loan::count())->toBe(1)->and(LoanMovement::where('kind', 'DISBURSEMENT')->count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(65000);
});

it('C232 prints the term actually saved after switching the visible selection', function (int $first, int $final, string $due, string $interest) {
    [$admin, $cashier, $type] = registrationFixture();
    $page = naturalRegistration($type, $first)->set('data.days', $final)->call('register')->assertHasNoErrors()->callMountedAction()->assertHasNoErrors();
    $loan = Loan::sole();
    $page->unmountAction()->set('data.days', $first)->set('data.capital', '999');
    expect($loan->original_days)->toBe($final);
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id, 'days' => $first, 'capital' => '999']))->assertOk()->assertSee($final.' días')->assertSee($due)->assertSee('Bs '.$interest)->assertDontSee('Bs 999');
    $this->get(route('prestamos.recibo-pdf', $loan->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(Loan::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(80000);
})->with([[30, 15, '2026-10-15', '15,00'], [15, 30, '2026-10-30', '30,00']]);

it('C232 opens the latest committed receipt while retaining previous operation receipts', function () {
    [$admin, $cashier, $type] = registrationFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, ['ci' => 'RECIBO-232', 'name' => 'Cliente de prueba recibos', 'type_id' => $type->id, 'type_revision' => 1, 'description' => 'Garrafa azul', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'request_key' => (string) Str::uuid()]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00', 'America/La_Paz'));
    $action = app(ChangeLoanTermAction::class);
    $data = fn (string $kind, int $days, int $revision) => ['kind' => $kind, 'days' => $days, 'periods' => 1, 'expected_revision' => $revision, 'payment_confirmed' => true, 'reference' => '', 'request_key' => (string) Str::uuid()];
    $first = $action->execute($cashier, $loan->id, $data('RENEW', 30, 0));
    $this->get(route('prestamos.recibo-pdf', $loan->id))->assertRedirect(route('prestamos.renovacion-pdf', $first->id));
    $originalHtml = $this->get(route('prestamos.renovacion-comprobante', $first->id))->assertOk()->assertSee('30 días')->assertSee('2026-11-14')->getContent();
    $second = $action->execute($cashier, $loan->id, $data('RENEW', 15, $first->id));
    $this->get(route('prestamos.recibo-pdf', $loan->id))->assertRedirect(route('prestamos.renovacion-pdf', $second->id));
    $this->get(route('prestamos.renovacion-comprobante', ['change' => $second->id, 'days' => 30]))->assertOk()->assertSee('15 días')->assertSee('2026-11-29');
    $third = $action->execute($cashier, $loan->id, $data('EXTEND', 30, $second->id));
    $this->get(route('prestamos.recibo-pdf', $loan->id))->assertRedirect(route('prestamos.renovacion-pdf', $third->id));
    $this->get(route('prestamos.renovacion-comprobante', $third->id))->assertOk()->assertSee('30 días')->assertSee('2026-12-14');
    expect($this->get(route('prestamos.renovacion-comprobante', $first->id))->getContent())->toBe($originalHtml);
    $this->get(route('prestamos.recibo-pdf', ['loan' => $loan->id, 'original' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('prestamos.renovacion-pdf', $third->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    foreach ([$first, $second, $third] as $event) {
        $pdf = app(LoanReceiptPdf::class)->renderTerm($event->fresh());
        $bytes = $pdf->output();
        if ($qaDirectory = getenv('LOAN_PDF_QA_DIR')) {
            file_put_contents($qaDirectory.'/operacion-'.$event->id.'.pdf', $bytes);
        }
        expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1)->and($pdf->getDomPDF()->getPaperSize())->toBe([0.0, 0.0, 612.0, 792.0]);
    }
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $this->actingAs($other)->get(route('prestamos.renovacion-pdf', $third->id))->assertOk();
    $this->get(route('prestamos.recibo-pdf', $loan->id))->assertRedirect();
    $other->restrictedPermissions()->attach(Permission::findByName('prestamos.ver'));
    $this->actingAs($other->fresh())->get(route('prestamos.renovacion-pdf', $third->id))->assertForbidden();
    $this->get(route('prestamos.recibo-pdf', $loan->id))->assertForbidden();
    expect(Loan::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(86000)->and(LoanMovement::where('kind', 'TERM_PAYMENT')->count())->toBe(3);
});

it('C234 lets the cashier confirm payment and delivery from the record and hides it afterwards', function () {
    [$admin,$cashier,$type] = registrationFixture();
    naturalRegistration($type)->call('register')->callMountedAction()->assertHasNoErrors();
    $loan = Loan::sole();
    $page = Livewire::test(LoanRecord::class, ['loan' => $loan->id]);
    $page->mountAction('settle')->assertActionMounted('settle');
    $page->callMountedAction()->assertHasErrors();
    expect(LoanFund::sole()->balance_cents)->toBe(80000);
    $page->setActionData(['payment_confirmed' => true, 'delivery_confirmed' => true])->callMountedAction()->assertHasNoErrors()->assertSee('Recogido y pagado');
    expect(LoanFund::sole()->balance_cents)->toBe(101500)->and($loan->fresh()->status)->toBe('RECOGIDO_PAGADO');
    $page->assertActionHidden('settle');
});
it('C234 confirms settlement from the list', function () {
    [$admin, $cashier, $type] = registrationFixture();
    naturalRegistration($type)->call('register')->callMountedAction()->assertHasNoErrors();
    $loan = Loan::sole();
    Livewire::test(LoanList::class)->callTableAction('settle', $loan, data: ['payment_confirmed' => true, 'delivery_confirmed' => true])->assertHasNoTableActionErrors();
    expect($loan->fresh()->status)->toBe('RECOGIDO_PAGADO')->and(LoanFund::sole()->balance_cents)->toBe(101500);
    $this->get(route('prestamos.recibo-pdf', ['loan' => $loan->id]))->assertOk();
});

it('C237 opens the same saved loan detail from the registration result and the whole table row', function () {
    [$admin, $cashier, $type] = registrationFixture();
    $page = naturalRegistration($type)->call('register')->callMountedAction()->assertHasNoErrors();
    $loan = Loan::sole();
    $page->set('data.name', 'BORRADOR NO GUARDADO')->mountAction('viewRecord')->assertMountedActionModalSee('Cliente de prueba')->assertMountedActionModalSee('Garrafa azul sin accesorios')->assertMountedActionModalSee('Bs 215')->assertMountedActionModalDontSee('BORRADOR NO GUARDADO');
    $list = Livewire::test(LoanList::class);
    expect($list->instance()->getTable()->getRecordAction($loan))->toBe('detail');
    $list->mountTableAction('detail', $loan)->assertMountedActionModalSee('Cliente de prueba')->assertMountedActionModalSee('Bs 215');
    expect(Loan::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(80000)->and(LoanMovement::count())->toBe(2);
});

it('C242 lets the cashier register manual dates and interest with every object detail and its saved receipt', function () {
    [$admin, $cashier, $type] = registrationFixture();
    $page = naturalRegistration($type)->set('data.days', 'MANUAL')->set('data.start_date', '2026-10-01')->set('data.due_date', '2026-11-11')->set('data.interest_mode', 'MANUAL')->set('data.manual_interest', '27.50')->set('data.phone', '76543210')->set('data.serial', 'SERIE-242')->set('data.condition', 'Revisado')->set('data.accessories', 'Cargador');
    $page->call('register')->assertHasNoErrors()->assertActionMounted('confirmLoan')->assertMountedActionModalSee('Bs 227.50')->callMountedAction()->assertHasNoErrors();
    $loan = Loan::sole();
    expect($loan->original_days)->toBe(42)->and($loan->original_interest_cents)->toBe(2750)->and($loan->user_id)->toBe($cashier->id);
    expect($loan->object_snapshot)->toMatchArray(['serial' => 'SERIE-242', 'condition' => 'Revisado', 'accessories' => 'Cargador']);
    $this->get(route('prestamos.comprobante', ['loan' => $loan->id]))->assertOk()->assertSee('2026-11-11')->assertSee('Bs 27,50')->assertSee('Bs 227,50')->assertSee('42');
    expect(LoanFund::sole()->balance_cents)->toBe(80000);
    expect(LoanMovement::where('kind', 'DISBURSEMENT')->count())->toBe(1);
});

it('C242 applies the manual permission to existing cashier roles idempotently without changing financial records', function () {
    [$admin, $cashier, $type] = registrationFixture();
    $role = Role::findByName('cajera');
    $role->revokePermissionTo('prestamos.condiciones.manuales');
    $this->seed(LoanPermissionsSeeder::class);
    $this->seed(LoanPermissionsSeeder::class);
    expect($cashier->fresh()->can('prestamos.condiciones.manuales'))->toBeTrue();
    expect(Loan::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
    $cashier->restrictedPermissions()->attach(Permission::findByName('prestamos.condiciones.manuales'));
    expect(Gate::forUser($cashier->fresh())->allows('manualTerms', Loan::class))->toBeFalse();
});

it('offers the complete active object catalog beyond fifty choices and selects the final type', function () {
    [$admin, $cashier] = registrationFixture();
    $last = null;
    for ($index = 1; $index <= 60; $index++) {
        $last = app(SaveLoanTypeAction::class)->execute($admin, ['name' => sprintf('Objeto %02d', $index), 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    }
    $inactive = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Objeto inactivo', 'rate' => '15', 'is_active' => false, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $page = Livewire::test(RegisterLoan::class);
    $page->assertFormFieldExists('type_id', function (Select $field) use ($last, $inactive): bool {
        expect($field->getOptions())->toHaveCount(61)->toHaveKey($last->id)->not->toHaveKey($inactive->id);
        expect($field->getOptionsLimit())->toBe(0);
        expect($field->getExtraFieldWrapperAttributes()['class'])->toContain('fi-fixed-positioning-context');

        return true;
    });
    $page->set('data.type_id', $last->id)->assertSet('data.type_revision', $last->revision);
    expect(Loan::count())->toBe(0);
});
