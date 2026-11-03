<?php

use App\Domain\Envios\Actions\ConfigureUsdRatesAction;
use App\Domain\Envios\Actions\SyncUsdReferenceAction;
use App\Domain\Envios\Calculators\UsdRateCalculator;
use App\Domain\Envios\Models\UsdRateSetting;
use App\Domain\Envios\Queries\UsdRatesQuery;
use App\Filament\Pages\Envios\UsdExchangeRates;
use App\Models\User;
use Carbon\CarbonImmutable;
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
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'America/La_Paz'));
    $this->seed([UsuariosPermissionsSeeder::class, EnviosPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
});
function usdAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('administrador');

    return $user;
}
function usdData(array $overrides = []): array
{
    return [...['buy' => '11.20', 'sell' => '12.60', 'reason' => 'Comparación revisada por Administración', 'expected_id' => 0, 'request_key' => (string) Str::uuid()], ...$overrides];
}
function usdResponse(array $overrides = []): array
{
    return [...['result' => 'success', 'base_code' => 'USD', 'rates' => ['BOB' => 11.716552], 'time_last_update_unix' => now()->subHour()->timestamp, 'time_next_update_unix' => now()->addDay()->timestamp], ...$overrides];
}
it('previews both directions with exact cents and rejects inverted prices', function () {
    $calc = app(UsdRateCalculator::class);
    expect($calc->preview('11.20', '12.60', '100')['buy_total'])->toBe(112000)
        ->and($calc->preview('11.20', '12.60', '100')['sell_total'])->toBe(126000);
    expect($calc->preview('11.123456', '12.123456', '1.01')['buy_total'])->toBe(1123)
        ->and($calc->preview('11.123456', '12.123456', '1.01')['sell_total'])->toBe(1225);
    expect(fn () => $calc->preview('13', '12', '100'))->toThrow(ValidationException::class);
    expect(fn () => $calc->preview('0', '12', '100'))->toThrow(ValidationException::class);
});
it('versions settings once and rejects altered retries and stale edits', function () {
    $admin = usdAdmin();
    $data = usdData();
    $action = app(ConfigureUsdRatesAction::class);
    $first = $action->execute($admin, $data);
    expect($action->execute($admin, $data)->id)->toBe($first->id);
    expect(fn () => $action->execute($admin, [...$data, 'sell' => '13']))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($admin, usdData()))->toThrow(ValidationException::class);
    $second = $action->execute($admin, usdData(['expected_id' => $first->id, 'sell' => '12.70']));
    expect($second->id)->toBeGreaterThan($first->id)->and($first->fresh()->sell_rate)->toBe(12600000)
        ->and(UsdRateSetting::count())->toBe(2)->and(DB::table('exchange_policies')->count())->toBe(0)
        ->and(DB::table('cash_box_operations')->count())->toBe(0);
});
it('denies non administrators and revoked or inactive administrators', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $cashier->givePermissionTo('envios.administrar');
    expect(fn () => app(ConfigureUsdRatesAction::class)->execute($cashier, usdData()))->toThrow(AuthorizationException::class);
    $admin = usdAdmin();
    $admin->forceFill(['is_active' => false])->save();
    expect(fn () => app(ConfigureUsdRatesAction::class)->execute($admin, usdData()))->toThrow(AuthorizationException::class);
    $this->actingAs($cashier)->get(UsdExchangeRates::getUrl())->assertForbidden();
});
it('fetches only a due USD reference without overwriting configured prices', function () {
    $admin = usdAdmin();
    app(ConfigureUsdRatesAction::class)->execute($admin, usdData());
    Http::fake(['open.er-api.com/*' => Http::response(usdResponse())]);
    expect(app(SyncUsdReferenceAction::class)->execute($admin))->toBeTrue();
    expect(app(SyncUsdReferenceAction::class)->execute($admin))->toBeFalse();
    Http::assertSentCount(1);
    expect(DB::table('usd_rate_references')->value('rate'))->toBe(11716552)
        ->and(UsdRateSetting::first()->sell_rate)->toBe(12600000)
        ->and(DB::table('exchange_references')->count())->toBe(0);
});
it('preserves previous reference on invalid API data and throttles repeated failures', function () {
    $admin = usdAdmin();
    Http::fake(['open.er-api.com/*' => Http::response(usdResponse())]);
    app(SyncUsdReferenceAction::class)->execute($admin);
    $this->travel(2)->days();
    Http::fake(['open.er-api.com/*' => Http::response(usdResponse(['base_code' => 'EUR']))]);
    expect(fn () => app(SyncUsdReferenceAction::class)->execute($admin))->toThrow(ValidationException::class);
    expect(app(SyncUsdReferenceAction::class)->execute($admin))->toBeFalse();
    expect(DB::table('usd_rate_references')->count())->toBe(1)->and(DB::table('usd_rate_references')->value('rate'))->toBe(11716552);
});
it('renders the styled screen, previews a draft and saves a single audited version', function () {
    $admin = usdAdmin();
    Http::fake(['open.er-api.com/*' => Http::response(usdResponse())]);
    $this->actingAs($admin);
    $page = Livewire::test(UsdExchangeRates::class)->assertSee('Referencia USD / BOB')->assertSee('Vista previa')
        ->assertSee('Rates by ExchangeRate-API')->fillForm(['buy' => '11.20', 'sell' => '12.60', 'amount' => '100', 'reason' => 'Precios revisados para prueba']);
    expect($page->instance()->preview['buy_total'])->toBe(112000);
    $page->call('save')->assertHasNoErrors()->assertNotified('Tasas del dólar guardadas');
    expect(UsdRateSetting::count())->toBe(1)->and(UsdRateSetting::first()->created_by)->toBe($admin->id);
    $page->set('data.sell', '10')->call('save')->assertHasErrors();
    expect(UsdRateSetting::count())->toBe(1);
});
it('rejects stale permission at save and refresh without an external request', function () {
    $admin = usdAdmin();
    Http::fake(['open.er-api.com/*' => Http::response(usdResponse())]);
    $this->actingAs($admin);
    $page = Livewire::test(UsdExchangeRates::class)->fillForm(['buy' => '11', 'sell' => '12', 'amount' => '100', 'reason' => 'Revisión de permisos']);
    $admin->removeRole('administrador');
    $page->call('save')->assertForbidden();
    expect(fn () => app(SyncUsdReferenceAction::class)->execute($admin))->toThrow(AuthorizationException::class);
    expect(UsdRateSetting::count())->toBe(0);
    Http::assertSentCount(1);
});

it('shows only five versions and keeps old prices unchanged', function () {
    $admin = usdAdmin();
    $expected = 0;
    for ($i = 0; $i < 6; $i++) {
        $record = app(ConfigureUsdRatesAction::class)->execute($admin, usdData(['expected_id' => $expected, 'reason' => 'Versión administrativa '.$i]));
        $expected = $record->id;
    }
    $overview = app(UsdRatesQuery::class)->overview($admin);
    expect($overview['history']->count())->toBe(5)->and($overview['current']->id)->toBe($expected)
        ->and($overview['history']->pluck('reason')->all())->not->toContain('Versión administrativa 0');
});
it('keeps the screen usable when the API is unavailable', function () {
    $admin = usdAdmin();
    $this->actingAs($admin);
    Http::fake(['open.er-api.com/*' => Http::response([], 503)]);
    Livewire::test(UsdExchangeRates::class)->assertSee('No se pudo actualizar la referencia')->assertSee('Sin referencia')
        ->fillForm(['buy' => '11.20', 'sell' => '12.60', 'amount' => '100', 'reason' => 'Precios revisados sin referencia API'])
        ->call('save')->assertHasNoErrors()->assertNotified('Tasas del dólar guardadas');
    expect(UsdRateSetting::first()->reference_rate)->toBeNull();
});
it('rejects unsafe API responses without creating any reference', function (array $overrides) {
    $admin = usdAdmin();
    Http::fake(['open.er-api.com/*' => Http::response(usdResponse($overrides))]);
    expect(fn () => app(SyncUsdReferenceAction::class)->execute($admin))->toThrow(ValidationException::class);
    expect(DB::table('usd_rate_references')->count())->toBe(0);
})->with([
    'zero rate' => [['rates' => ['BOB' => 0]]],
    'missing rate' => [['rates' => []]],
    'future date' => [['time_last_update_unix' => 1792500000]],
    'failed response' => [['result' => 'error']],
]);
