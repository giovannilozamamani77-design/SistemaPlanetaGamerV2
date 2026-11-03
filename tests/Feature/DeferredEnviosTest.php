<?php

use App\Domain\Envios\Actions\ConfigureExchangePolicyAction;
use App\Domain\Envios\Actions\ConfigureUsdRatesAction;
use App\Domain\Envios\Actions\SyncExchangeReferenceAction;
use App\Domain\Envios\Actions\SyncUsdReferenceAction;
use App\Domain\Envios\Queries\InternationalCustody;
use App\Filament\Pages\Envios\BrazilExchangeRates;
use App\Filament\Pages\Envios\InternationalTransfers;
use App\Filament\Pages\Envios\UsdExchangeRates;
use App\Models\User;
use Database\Seeders\Envios\EnviosPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['envios.enabled' => false]);
    $this->seed([UsuariosPermissionsSeeder::class, EnviosPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    Http::preventStrayRequests();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrador');
    $this->actingAs($this->admin);
});
it('hides all deferred screens and denies direct URLs even for administrators', function () {
    foreach ([InternationalTransfers::class, UsdExchangeRates::class, BrazilExchangeRates::class] as $page) {
        expect($page::canAccess())->toBeFalse();
        $this->get($page::getUrl())->assertForbidden();
    }
    $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getLabel())->all();
    expect($labels)->not->toContain('Envíos Brasil', 'Tasas de cambio', 'Tasas de Brasil');
    $this->get(route('envios.comprobante', ['event' => 999]))->assertForbidden();
});
it('blocks rate writes and external refreshes without removing stored configuration', function () {
    config(['envios.enabled' => true]);
    $data = ['buy' => '11.20', 'sell' => '12.60', 'reason' => 'Configuración anterior conservada', 'expected_id' => 0, 'request_key' => (string) Str::uuid()];
    $saved = app(ConfigureUsdRatesAction::class)->execute($this->admin, $data);
    config(['envios.enabled' => false]);
    expect(fn () => app(ConfigureUsdRatesAction::class)->execute($this->admin, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect(fn () => app(ConfigureExchangePolicyAction::class)->execute($this->admin, ['base' => '2.30', 'sell' => '2.40', 'buy' => '2.20', 'cost_source' => 'Costo anterior', 'cost_confirmed' => true, 'valid_minutes' => 10, 'enabled' => true, 'reference_enabled' => true, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect(app(SyncUsdReferenceAction::class)->execute($this->admin))->toBeFalse();
    expect(app(SyncExchangeReferenceAction::class)->execute($this->admin))->toBeFalse();
    expect(app(SyncExchangeReferenceAction::class)->execute())->toBeFalse();
    Http::assertNothingSent();
    expect($saved->fresh()->sell_rate)->toBe(12600000)->and(DB::table('usd_rate_settings')->count())->toBe(1);
    expect(app(InternationalCustody::class)->forBox(999))->toBe([]);
});
