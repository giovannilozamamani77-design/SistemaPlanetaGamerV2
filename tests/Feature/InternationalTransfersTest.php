<?php

use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\DecideCashBoxCorrectionAction;
use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Actions\RecordCashBoxOperationAction;
use App\Domain\Cajas\Actions\RecordCashBoxTemporaryFundAction;
use App\Domain\Cajas\Actions\RequestCashBoxCorrectionAction;
use App\Domain\Cajas\Actions\SetCashBoxCommissionAccountAction;
use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Envios\Actions\ConfigureBrazilRatesAction;
use App\Domain\Envios\Actions\ConfigureExchangePolicyAction;
use App\Domain\Envios\Actions\CreateInternationalTransferAction;
use App\Domain\Envios\Actions\ManageInternationalTransferAction;
use App\Domain\Envios\Actions\SyncExchangeReferenceAction;
use App\Domain\Envios\Calculators\TransferQuoteCalculator;
use App\Domain\Envios\Models\ExchangePolicy;
use App\Domain\Envios\Models\InternationalTransfer;
use App\Domain\Envios\Queries\InternationalCustody;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Filament\Pages\Envios\BrazilExchangeRates;
use App\Filament\Pages\Envios\InternationalTransfers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Envios\EnviosPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
beforeEach(function () {
    // Archived-module contracts explicitly opt in; the application remains disabled.
    config(['envios.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 19:50', 'America/La_Paz'));
    $this->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, CajasPermissionsSeeder::class, EnviosPermissionsSeeder::class]);
    config(['envios.cash_box_integration_ready' => method_exists(CashBoxClose::class, 'operatingTotal')]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    Http::preventStrayRequests();
});
function pixUser(string $role): User
{
    $u = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $u->assignRole($role);

    return $u;
}
function pixData(array $values = []): array
{
    return [...['request_key' => (string) Str::uuid(), 'confirmed' => true, 'receipt' => 'Entrega real comprobada en la prueba'], ...$values];
}
function pixFixture(): array
{
    $admin = pixUser('administrador');
    $owner = pixUser('cajera');
    $box = CashBox::factory()->create();
    app(ConfigureCashBoxAction::class)->execute($admin, $box, ['accounts' => ['efectivo' => ['name' => 'Efectivo', 'initial' => '2000'], 'bcp' => ['name' => 'BCP', 'initial' => '15000'], 'yape' => ['name' => 'Yape', 'initial' => '3000']], 'base_capital' => '20000', 'shifts' => CashBoxComponents::defaultShifts(), 'expected_revision' => $box->revision, 'reason' => 'Configuración de prueba', 'request_key' => (string) Str::uuid()]);
    $access = app(StartCashBoxAccessAction::class)->execute($owner, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($owner, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    app(ConfigureExchangePolicyAction::class)->execute($admin, ['base' => '1.50', 'sell' => '1.60', 'buy' => '1.40', 'cost_source' => 'Costo de fondos confirmado', 'cost_confirmed' => true, 'valid_minutes' => 10, 'enabled' => true, 'reference_enabled' => false, 'request_key' => (string) Str::uuid()]);
    app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['destination' => 'YAPE-COMISIONES', 'reason' => 'Cuenta de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);

    return [$admin, $owner, $box, $session, $access];
}
function pixCreate(array $fixture, array $replace = []): InternationalTransfer
{
    [, $owner,, $session,$access] = $fixture;

    return app(CreateInternationalTransferAction::class)->execute($owner, pixData([...['session_id' => $session->id, 'access_id' => $access->id, 'component' => 'efectivo', 'direction' => 'BO_BR', 'currency' => 'BOB', 'amount' => '1000', 'sender_name' => 'Cliente de prueba', 'sender_whatsapp' => '59177712345', 'recipient_name' => 'Beneficiario de prueba', 'pix_key' => 'beneficiario@example.test'], ...$replace]));
}
function pixStep(User $actor, InternationalTransfer $transfer, $session, $access, string $kind, array $data = []): InternationalTransfer
{
    return app(ManageInternationalTransferAction::class)->execute($actor, $transfer, $kind, pixData(['session_id' => $session->id, 'access_id' => $access->id, ...$data]));
}
it('quotes both directions with an included margin and integer rounding', function () {
    $calculator = app(TransferQuoteCalculator::class);
    $out = $calculator->calculate('BO_BR', 'BOB', '1000', 1500000, 1600000, 1400000);
    expect($out)->toMatchArray(['bob_cents' => 100000, 'brl_cents' => 62500, 'principal_cents' => 93750, 'margin_cents' => 6250]);
    $in = $calculator->calculate('BR_BO', 'BRL', '625', 1500000, 1600000, 1400000);
    expect($in)->toMatchArray(['bob_cents' => 87500, 'brl_cents' => 62500, 'principal_cents' => 93750, 'margin_cents' => 6250]);
});

it('returns administrator funds before refunding a failed outbound request without phantom cash', function () {
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f);
    pixStep($owner, $t, $session, $access, 'collect');
    pixStep($owner, $t, $session, $access, 'handover');
    pixStep($admin, $t, $session, $access, 'ack_admin');
    pixStep($admin, $t, $session, $access, 'cancel');
    expect(fn () => pixStep($owner, $t, $session, $access, 'refund'))->toThrow(ValidationException::class);
    pixStep($admin, $t, $session, $access, 'return_funds');
    expect(array_sum($session->fresh()->current_balances))->toBe(2006250);
    pixStep($owner, $t, $session, $access, 'receive_return');
    pixStep($owner, $t, $session, $access, 'refund');
    expect(array_sum($session->fresh()->current_balances))->toBe(2000000)->and($t->fresh()->local_held_cents)->toBe(0)->and($t->fresh()->status)->toBe('CANCELADO');
    $this->assertDatabaseCount('cash_box_operations', 0);
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Integración financiera pendiente de autorización específica; contrato probado en copia aislada.');
it('returns unused reverse funds before administrator confirms a reais refund', function () {
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f, ['direction' => 'BR_BO', 'currency' => 'BRL', 'amount' => '625', 'pix_key' => null]);
    pixStep($admin, $t, $session, $access, 'receive_brl', ['reference' => 'IN-REFUND']);
    pixStep($admin, $t, $session, $access, 'offer_funds');
    pixStep($owner, $t, $session, $access, 'receive_funds');
    expect(fn () => pixStep($admin, $t, $session, $access, 'cancel', ['reference' => 'BRL-REFUND']))->toThrow(ValidationException::class);
    pixStep($owner, $t, $session, $access, 'return_local_funds');
    pixStep($admin, $t, $session, $access, 'ack_local_return');
    pixStep($admin, $t, $session, $access, 'cancel', ['reference' => 'BRL-REFUND']);
    expect(array_sum($session->fresh()->current_balances))->toBe(2000000)->and($t->fresh()->status)->toBe('CANCELADO');
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Integración financiera pendiente de autorización específica; contrato probado en copia aislada.');
it('rejects stale quotes and foreign ownership without writes', function () {
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f);
    expect(fn () => pixStep($admin, $t, $session, $access, 'collect'))->toThrow(AuthorizationException::class);
    expect(fn () => pixStep(pixUser('cajera'), $t, $session, $access, 'collect'))->toThrow(AuthorizationException::class);
    expect(fn () => pixStep($owner, $t, $session, $access, 'ack_admin'))->toThrow(AuthorizationException::class);
    $this->travel(11)->minutes();
    expect(fn () => pixStep($owner, $t, $session, $access, 'collect'))->toThrow(ValidationException::class);
    expect(array_sum($session->fresh()->current_balances))->toBe(2000000);
    $this->assertDatabaseCount('international_transfer_events', 0);
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Integración financiera pendiente de autorización específica; contrato probado en copia aislada.');
it('protects reserved money from temporary withdrawals and generic transfers', function () {
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f);
    pixStep($owner, $t, $session, $access, 'collect');
    expect(fn () => app(RecordCashBoxTemporaryFundAction::class)->execute($owner, $session->id, ['kind' => 'RETIRO', 'component' => 'efectivo', 'amount' => '2500', 'reason' => 'No usar fondos cliente', 'occurred' => true, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(fn () => app(RecordCashBoxOperationAction::class)->execute($owner, $session->id, ['amount' => '2500', 'source' => 'efectivo', 'destination' => 'bcp', 'fee_type' => 'NINGUNA', 'fee_component' => 'efectivo', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect($session->fresh()->current_balances['efectivo'])->toBe(300000);
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Integración financiera pendiente de autorización específica; contrato probado en copia aislada.');
it('updates only the cached reference and never replaces commercial rates', function () {
    [$admin] = pixFixture();
    app(ConfigureExchangePolicyAction::class)->execute($admin, ['base' => '1.50', 'sell' => '1.60', 'buy' => '1.40', 'cost_source' => 'Compra real', 'cost_confirmed' => true, 'valid_minutes' => 10, 'enabled' => true, 'reference_enabled' => true, 'request_key' => (string) Str::uuid()]);
    Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'base_code' => 'BRL', 'rates' => ['BOB' => 1.48], 'time_last_update_unix' => now()->timestamp, 'time_next_update_unix' => now()->addDay()->timestamp])]);
    expect(app(SyncExchangeReferenceAction::class)->execute())->toBeTrue()->and(app(SyncExchangeReferenceAction::class)->execute())->toBeFalse();
    Http::assertSentCount(1);
    expect(DB::table('exchange_references')->value('rate'))->toBe(1480000)->and(ExchangePolicy::latest('id')->first()->base_rate)->toBe(1500000);
});
it('renders the private panel and resolves through native modals', function () {
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f);
    $this->actingAs($owner);
    Livewire::test(InternationalTransfers::class)->callTableAction('collect', $t, data: pixData(['session_id' => $session->id, 'access_id' => $access->id]))->assertHasActionErrors(['receipt']);
    $this->assertDatabaseCount('international_transfer_events', 0);
    Livewire::test(InternationalTransfers::class)->assertSee('Envíos Brasil')->assertCanSeeTableRecords([$t])->callAction('validarCaja', data: ['box_id' => $f[2]->id, 'pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors()->callTableAction('collect', $t, data: pixData(['session_id' => $session->id, 'access_id' => $access->id]))->assertHasNoActionErrors();
    $this->actingAs(pixUser('cajera'));
    Livewire::test(InternationalTransfers::class)->assertCanNotSeeTableRecords([$t]);
    $this->actingAs($admin);
    Livewire::test(InternationalTransfers::class)->assertCanSeeTableRecords([$t])->mountTableAction('detalle', $t)->assertSee('Cliente de prueba');
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Integración financiera pendiente de autorización específica; contrato probado en copia aislada.');

it('rejects an altered retry and a second administrator taking an already claimed execution', function () {
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f);
    $data = pixData(['session_id' => $session->id, 'access_id' => $access->id]);
    app(ManageInternationalTransferAction::class)->execute($owner, $t, 'collect', $data);
    expect(fn () => app(ManageInternationalTransferAction::class)->execute($owner, $t, 'collect', [...$data, 'receipt' => 'Otro contenido']))->toThrow(ValidationException::class);
    pixStep($owner, $t, $session, $access, 'handover');
    pixStep($admin, $t, $session, $access, 'ack_admin');
    pixStep($admin, $t, $session, $access, 'claim');
    $other = pixUser('administrador');
    expect(fn () => pixStep($other, $t, $session, $access, 'claim'))->toThrow(ValidationException::class);
    expect(fn () => pixStep($other, $t, $session, $access, 'confirm_pix', ['reference' => 'SECOND-ADMIN']))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('cash_box_operations', 0);
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Integración financiera pendiente de autorización específica; contrato probado en copia aislada.');
it('requires a banking reference and refuses invalid API data without replacing the last value', function () {
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f, ['direction' => 'BR_BO', 'currency' => 'BRL', 'amount' => '625', 'pix_key' => null]);
    expect(fn () => pixStep($admin, $t, $session, $access, 'receive_brl'))->toThrow(ValidationException::class);
    app(ConfigureExchangePolicyAction::class)->execute($admin, ['base' => '1.50', 'sell' => '1.60', 'buy' => '1.40', 'cost_source' => 'Compra real', 'cost_confirmed' => true, 'valid_minutes' => 10, 'enabled' => true, 'reference_enabled' => true, 'request_key' => (string) Str::uuid()]);
    Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'base_code' => 'BRL', 'rates' => ['BOB' => -1], 'time_last_update_unix' => now()->timestamp, 'time_next_update_unix' => now()->addDay()->timestamp])]);
    expect(fn () => app(SyncExchangeReferenceAction::class)->execute())->toThrow(ValidationException::class);
    $this->assertDatabaseCount('exchange_references', 0);
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Integración financiera pendiente de autorización específica; contrato probado en copia aislada.');
it('keeps quoted rates immutable after a configuration change and restricts rate administration', function () {
    $f = pixFixture();
    [$admin,$owner] = $f;
    $t = pixCreate($f);
    $snapshot = $t->quote;
    $data = ['base' => '1.60', 'sell' => '1.80', 'buy' => '1.40', 'cost_source' => 'Nuevo costo real', 'cost_confirmed' => true, 'valid_minutes' => 10, 'enabled' => true, 'reference_enabled' => false, 'request_key' => (string) Str::uuid()];
    expect(fn () => app(ConfigureExchangePolicyAction::class)->execute($owner, $data))->toThrow(AuthorizationException::class);
    app(ConfigureExchangePolicyAction::class)->execute($admin, $data);
    expect($t->fresh()->quote)->toBe($snapshot)->and(pixCreate($f)->brl_cents)->not->toBe($t->brl_cents);
});
it('downloads private proof only for an authorized request viewer', function () {
    Storage::fake('local');
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f);
    pixStep($owner, $t, $session, $access, 'collect');
    $path = 'envios/comprobantes/'.$owner->id.'/test.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nPrueba\n%%EOF");
    pixStep($owner, $t, $session, $access, 'handover', ['receipt_file_path' => $path]);
    $id = DB::table('international_transfer_events')->where('kind', 'handover')->value('id');
    $this->actingAs($owner)->get(route('envios.comprobante', ['event' => $id]))->assertOk();
    $this->actingAs(pixUser('cajera'))->get(route('envios.comprobante', ['event' => $id]))->assertNotFound();
    $this->actingAs($admin)->get(route('envios.comprobante', ['event' => $id]))->assertOk();
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Integración financiera pendiente de autorización específica; contrato probado en copia aislada.');

it('guards integration activation and extreme rates before any monetary write or overflow', function () {
    expect(fn () => app(TransferQuoteCalculator::class)->calculate('BR_BO', 'BOB', '9999999', 1500000, 1600000, 1))->toThrow(ValidationException::class);
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f);
    config(['envios.cash_box_integration_ready' => false]);
    expect(fn () => pixStep($owner, $t, $session, $access, 'collect'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('international_transfer_events', 0);
    expect(array_sum($session->fresh()->current_balances))->toBe(2000000);
});

it('renders the independent panel and quote configuration while financial integration is disabled', function () {
    $f = pixFixture();
    [$admin,$owner] = $f;
    config(['envios.cash_box_integration_ready' => false]);
    $this->actingAs($admin);
    Livewire::test(InternationalTransfers::class)->assertSee('Envíos Brasil')->assertSee('Integración financiera pendiente de verificación')->assertSee(BrazilExchangeRates::getUrl());
    $this->actingAs($owner);
    Livewire::test(InternationalTransfers::class)->assertActionHidden('configurarTasas');
});

it('creates a quote from the cash box button using its PIN-verified locked context without taking money', function () {
    $f = pixFixture();
    [$admin,$owner,$box,$session,$access] = $f;
    $this->actingAs($owner);
    Livewire::test(CashBoxOperations::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors()
        ->callAction('enviarBrasil', data: ['direction' => 'BO_BR', 'currency' => 'BOB', 'amount' => '1000', 'component' => 'efectivo', 'sender_name' => 'Cliente UI', 'sender_whatsapp' => '59177712345', 'recipient_name' => 'Beneficiario UI', 'pix_key' => 'clave@example.test', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors();
    expect(InternationalTransfer::sole()->status)->toBe('COTIZADO')->and(InternationalTransfer::sole()->margin_cents)->toBe(6250);
    expect(array_sum($session->fresh()->current_balances))->toBe(2000000);
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Botón de Cajas pendiente de aprobación de la integración; probado en copia aislada.');

it('does not consume custody when an administrator approves reversal of another commission', function () {
    $f = pixFixture();
    [$admin,$owner,,$session,$access] = $f;
    $t = pixCreate($f);
    pixStep($owner, $t, $session, $access, 'collect');
    $op = app(RecordCashBoxOperationAction::class)->execute($owner, $session->id, ['amount' => '0', 'fee_type' => 'NINGUNA', 'manual_fee' => '50', 'fee_component' => 'efectivo', 'reason' => 'Comisión a revisar', 'request_key' => (string) Str::uuid()]);
    app(RecordCashBoxTemporaryFundAction::class)->execute($owner, $session->id, ['kind' => 'RETIRO', 'component' => 'efectivo', 'amount' => '2050', 'reason' => 'Retiro real de fondos propios', 'occurred' => true, 'request_key' => (string) Str::uuid()]);
    $correction = app(RequestCashBoxCorrectionAction::class)->execute($owner, $op, ['reason' => 'Revisar comisión equivocada', 'request_key' => (string) Str::uuid()]);
    expect(fn () => app(DecideCashBoxCorrectionAction::class)->execute($admin, $correction, ['decision' => 'APROBADA', 'reason' => 'Revisión administrativa', 'pin' => '1234', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect($session->fresh()->current_balances['efectivo'])->toBe(100000)->and($op->fresh()->status)->toBe('VIGENTE');
})->skip(fn () => ! method_exists(CashBoxClose::class, 'operatingTotal'), 'Protección de custodia pendiente de aprobación; contrato probado en copia aislada.');

it('applies adjusted Brazil margins only to new quotes without moving cash or custody', function () {
    $f = pixFixture();
    [$admin,$owner,,$session] = $f;
    $old = pixCreate($f);
    $balances = $session->fresh()->current_balances;
    $policy = ExchangePolicy::latest('id')->first();
    app(ConfigureBrazilRatesAction::class)->execute($admin, [
        'base' => '1.50', 'buy_margin' => '0.20', 'sell_margin' => '0.20', 'cost_source' => 'Fondeo real revisado para nuevas cotizaciones',
        'cost_confirmed' => true, 'valid_minutes' => 10, 'enabled' => true, 'reference_enabled' => false, 'expected_id' => $policy->id, 'request_key' => (string) Str::uuid(),
    ]);
    $new = pixCreate($f);
    expect($old->fresh()->quote['commercial_rate'])->toBe(1600000)->and($new->quote['commercial_rate'])->toBe(1700000)
        ->and($session->fresh()->current_balances)->toBe($balances)->and(CashBoxOperation::count())->toBe(0)
        ->and((int) $old->fresh()->local_held_cents)->toBe(0)->and((int) $new->local_held_cents)->toBe(0);
});

it('removes the deferred transfer action from an open cash box without changing its balances', function () {
    $f = pixFixture();
    [, $owner,$box,$session,$access] = $f;
    $transfer = pixCreate($f);
    pixStep($owner, $transfer, $session, $access, 'collect');
    $balances = $session->fresh()->current_balances;
    config(['envios.enabled' => false]);
    $this->actingAs($owner);
    Livewire::test(CashBoxOperations::class)->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors()->assertActionHidden('enviarBrasil')->assertDontSee('Envíos a Brasil / Bolivia');
    expect($session->fresh()->current_balances)->toBe($balances);
    expect(app(InternationalCustody::class)->forBox($box->id))->toBe(['efectivo' => 100000]);
});
