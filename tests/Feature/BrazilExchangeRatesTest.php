<?php

use App\Domain\Envios\Actions\ConfigureBrazilRatesAction;
use App\Domain\Envios\Actions\SyncExchangeReferenceAction;
use App\Domain\Envios\Calculators\BrazilRateCalculator;
use App\Domain\Envios\Models\ExchangePolicy;
use App\Filament\Pages\Envios\BrazilExchangeRates;
use App\Models\User;
use Database\Seeders\Envios\EnviosPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
beforeEach(function () {
    // Archived-module contracts explicitly opt in; the application remains disabled.
    config(['envios.enabled' => true]);
    $this->seed([UsuariosPermissionsSeeder::class, EnviosPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    Http::preventStrayRequests();
});
function brlAdmin(): User
{
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
function brlData(array $v = []): array
{
    return [...['base' => '2.30', 'buy_margin' => '0.10', 'sell_margin' => '0.10', 'amount' => '1000', 'cost_source' => 'Costo real revisado por Administración', 'cost_confirmed' => true, 'valid_minutes' => 10, 'enabled' => true, 'reference_enabled' => true, 'expected_id' => 0, 'request_key' => (string) Str::uuid()], ...$v];
}
function brlApi(): array
{
    return ['result' => 'success', 'base_code' => 'BRL', 'rates' => ['BOB' => 2.31], 'time_last_update_unix' => now()->subHour()->timestamp, 'time_next_update_unix' => now()->addDay()->timestamp];
}
it('previews both margins with exact cents without creating commissions', function () {
    $p = app(BrazilRateCalculator::class)->preview('2.30', '0.10', '0.10', '1000');
    expect($p['buy'])->toBe(2200000)->and($p['sell'])->toBe(2400000)
        ->and($p['out']['bob_cents'])->toBe(240000)->and($p['in']['bob_cents'])->toBe(220000)
        ->and($p['out']['margin_cents'])->toBe(10000)->and($p['in']['margin_cents'])->toBe(10000);
    expect(DB::table('cash_box_operations')->count())->toBe(0);
    expect(fn () => app(BrazilRateCalculator::class)->rates('2.30', '2.30', '0.10'))->toThrow(ValidationException::class);
    expect(fn () => app(BrazilRateCalculator::class)->rates('999', '0', '1'))->toThrow(ValidationException::class);
    expect(fn () => app(BrazilRateCalculator::class)->rates('2', '-0.1', '0.1'))->toThrow(ValidationException::class);
});
it('stores derived prices once and refuses stale or altered retries', function () {
    $u = brlAdmin();
    $d = brlData();
    $action = app(ConfigureBrazilRatesAction::class);
    $p = $action->execute($u, $d);
    expect($p->buy_rate)->toBe(2200000)->and($p->sell_rate)->toBe(2400000)->and($action->execute($u, $d)->id)->toBe($p->id);
    expect(fn () => $action->execute($u, [...$d, 'sell_margin' => '0.20']))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($u, brlData()))->toThrow(ValidationException::class);
    $action->execute($u, brlData(['expected_id' => $p->id, 'sell_margin' => '0.20']));
    expect($p->fresh()->sell_rate)->toBe(2400000)->and(ExchangePolicy::count())->toBe(2)->and(DB::table('usd_rate_settings')->count())->toBe(0);
});
it('requires confirmed funds and denies non admin writes', function () {
    $u = brlAdmin();
    expect(fn () => app(ConfigureBrazilRatesAction::class)->execute($u, brlData(['cost_confirmed' => false])))->toThrow(ValidationException::class);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $cashier->givePermissionTo('envios.administrar');
    expect(fn () => app(ConfigureBrazilRatesAction::class)->execute($cashier, brlData()))->toThrow(AuthorizationException::class);
    $this->actingAs($cashier)->get(BrazilExchangeRates::getUrl())->assertForbidden();
    expect(fn () => app(SyncExchangeReferenceAction::class)->execute($cashier))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});
it('fetches the initial BRL reference without configuring or overwriting any prices', function () {
    $u = brlAdmin();
    Http::fake(['open.er-api.com/*' => Http::response(brlApi())]);
    expect(app(SyncExchangeReferenceAction::class)->execute($u))->toBeTrue();
    app(ConfigureBrazilRatesAction::class)->execute($u, brlData());
    expect(app(SyncExchangeReferenceAction::class)->execute($u))->toBeFalse();
    Http::assertSentCount(1);
    expect(ExchangePolicy::latest('id')->first()->base_rate)->toBe(2300000)->and(DB::table('exchange_references')->value('rate'))->toBe(2310000);
});
it('renders and saves margins through the native form and never auto confirms the API cost', function () {
    $u = brlAdmin();
    $this->actingAs($u);
    Http::fake(['open.er-api.com/*' => Http::response(brlApi())]);
    $page = Livewire::test(BrazilExchangeRates::class)->assertSee('Referencia BRL / BOB')->assertSee('Vista previa')->assertSee('Rates by ExchangeRate-API');
    $page->call('useReference')->assertSet('data.base', '2.310000')->assertSet('data.cost_confirmed', false);
    $page->fillForm(['base' => '2.30', 'buy_margin' => '0.10', 'sell_margin' => '0.10', 'amount' => '1000', 'cost_source' => 'Costo de fondos verificado', 'cost_confirmed' => true, 'valid_minutes' => 10, 'enabled' => true, 'reference_enabled' => true])->call('save')->assertHasNoErrors()->assertNotified('Tasas de Brasil guardadas');
    expect(ExchangePolicy::first()->sell_rate)->toBe(2400000);
});
it('preserves the last reference on API failure and renders five historical policies', function () {
    $u = brlAdmin();
    $expected = 0;
    for ($i = 0; $i < 6; $i++) {
        $p = app(ConfigureBrazilRatesAction::class)->execute($u, brlData(['expected_id' => $expected, 'cost_source' => 'Fuente de costo '.$i]));
        $expected = $p->id;
    }
    Http::fake(['open.er-api.com/*' => Http::response([], 503)]);
    $this->actingAs($u);
    Livewire::test(BrazilExchangeRates::class)->assertSee('No se pudo actualizar')->assertSee('Fuente de costo 5')->assertDontSee('Fuente de costo 0');
    expect(ExchangePolicy::count())->toBe(6);
});

it('denies a revoked administrator and refuses a stale reference draft', function () {
    $u = brlAdmin();
    $this->actingAs($u);
    Http::fake(['open.er-api.com/*' => Http::response(brlApi())]);
    $page = Livewire::test(BrazilExchangeRates::class);
    $this->travel(2)->days();
    $page->call('useReference')->assertSet('data.base', null)->assertNotified('Actualiza la referencia antes de usarla');
    $u->removeRole('administrador');
    $page->call('save')->assertForbidden();
    expect(ExchangePolicy::count())->toBe(0);
});
