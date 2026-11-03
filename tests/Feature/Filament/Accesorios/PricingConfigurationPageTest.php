<?php

use App\Domain\Accesorios\Actions\SavePricingSettingsAction;
use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PricingChange;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Filament\Pages\Accesorios\PricingConfiguration;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryPricingPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function pricingPageAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryPricingPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    Http::preventStrayRequests();
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);

    return $u;
}
function pricingPageData(int $revision = 0): array
{
    return ['exchange_mode' => 'PHYSICAL', 'physical_rate' => '12.59', 'adjustment' => '0.43', 'auto_refresh' => true, 'accessory_mode' => 'PERCENT', 'accessory_percent' => '50', 'used_phone_mode' => 'MANUAL', 'new_phone_mode' => 'MANUAL', 'expected_revision' => $revision, 'request_key' => (string) Str::uuid()];
}
it('C279 exposes only the global dollar form while sale percentages remain separate', function () {
    pricingPageAdmin();
    $this->get(PricingConfiguration::getUrl())->assertOk()->assertSee('Tipo de cambio global')->assertDontSee('Sin configurar');
    expect(PricingSettings::count())->toBe(0);
    Livewire::test(PricingConfiguration::class)->callAction('configure', data: pricingPageData())->assertHasNoActionErrors()->assertSee('50 % sobre costo');
    expect(PricingChange::count())->toBe(1);
    Http::assertNothingSent();
});
it('C279 cancels price rules without writing and validates the editable difference', function () {
    pricingPageAdmin();
    Livewire::test(PricingConfiguration::class)->mountAction('configure')->setActionData(pricingPageData())->unmountAction();
    expect(PricingChange::count())->toBe(0)->and(PricingSettings::count())->toBe(0);
    Livewire::test(PricingConfiguration::class)->fillForm(['adjustment' => '-1'])->call('saveDollar')->assertHasErrors(['dollar.adjustment']);
    expect(PricingSettings::count())->toBe(0);
});
it('C250 protects the configuration page from cashier and encargado roles', function (string $role) {
    pricingPageAdmin();
    $other = User::factory()->create();
    $other->assignRole($role);
    $this->actingAs($other);
    $this->get(PricingConfiguration::getUrl())->assertForbidden();
})->with(['cajera', 'encargado']);
it('C250 paginates immutable changes and opens the saved before and after values', function () {
    $admin = pricingPageAdmin();
    $events = [];
    for ($i = 0; $i < 6; $i++) {
        $events[] = app(SavePricingSettingsAction::class)->execute($admin, pricingPageData($i));
    }
    $p = Livewire::test(PricingConfiguration::class)->assertCanSeeTableRecords(array_slice($events, 1))->assertCanNotSeeTableRecords([$events[0]]);
    $p->call('gotoPage', 2)->assertCanSeeTableRecords([$events[0]])->mountTableAction('detail', $events[0])->assertMountedActionModalSee('0.43')->assertMountedActionModalSee('50 % sobre costo');
});
it('C250 can refresh explicitly and retains its stored rate on failure without touching rules', function () {
    pricingPageAdmin();
    Http::fake(['paralelo.bo/*' => Http::sequence()->push(['buy' => 12.16, 'timestamp' => now()->toIso8601String()])->push([], 500)]);
    $p = Livewire::test(PricingConfiguration::class)->callAction('refreshReference')->assertHasNoActionErrors()->assertSee('Bs 12.16');
    $p->callAction('refreshReference')->assertHasNoActionErrors()->assertSee('Bs 12.16');
    expect(ExchangeQuote::count())->toBe(1)->and(PricingChange::count())->toBe(0);
});

it('C277 saves the automatic price switch and allows pausing without changing the percentage', function () {
    pricingPageAdmin();
    $data = pricingPageData() + ['new_phone_auto_update' => true];
    $data['new_phone_mode'] = 'PERCENT';
    $data['new_phone_percent'] = '10';
    $data['adjustment'] = '0.70';
    Livewire::test(PricingConfiguration::class)->callAction('configure', data: $data)->assertHasNoActionErrors()->assertSee('activada');
    $data['new_phone_auto_update'] = false;
    $data['expected_revision'] = 1;
    $data['request_key'] = (string) Str::uuid();
    Livewire::test(PricingConfiguration::class)->callAction('configure', data: $data)->assertHasNoActionErrors()->assertSee('pausada');
    expect(PricingSettings::first()->rules['NEW_PHONE']['markup_bps'])->toBe(1000)->and(PricingSettings::first()->adjustment_micros)->toBe(700000);
});

it('C278 loads the API value for display without changing settings or automatic prices and reuses the quote', function () {
    pricingPageAdmin();
    Http::fake(['paralelo.bo/*' => Http::response(['buy' => 11, 'timestamp' => now()->toIso8601String()])]);
    $page = Livewire::test(PricingConfiguration::class)->call('loadReference')->assertSee('Valor de la API')->assertSee('Bs 11')->assertSet('dollar.adjustment', '0.7');
    $page->call('loadReference');
    Http::assertSentCount(1);
    expect(ExchangeQuote::count())->toBe(1)->and(PricingSettings::count())->toBe(0)->and(PricingChange::count())->toBe(0);
});

it('C278 keeps the page usable when initial API lookup fails and avoids immediate repeated requests', function () {
    pricingPageAdmin();
    Http::fake(['paralelo.bo/*' => Http::response([], 503)]);
    $page = Livewire::test(PricingConfiguration::class)->call('loadReference')->assertSee('No se pudo consultar el dólar');
    $page->call('loadReference');
    Http::assertSentCount(1);
    expect(ExchangeQuote::count())->toBe(0)->and(PricingSettings::count())->toBe(0);
});

it('C279 always displays API plus global difference without selecting a manual exchange mode', function () {
    pricingPageAdmin();
    Http::fake(['paralelo.bo/*' => Http::response(['buy' => 12.17, 'timestamp' => now()->toIso8601String()])]);
    Livewire::test(PricingConfiguration::class)->call('loadReference')->assertSee('Bs 12.87')->assertDontSee('Sin configurar')->assertDontSee('Calculadora de precio');
    expect(PricingSettings::count())->toBe(0);
});

it('C279 saves only the global difference and preserves family pricing rules without duplicate audits', function () {
    pricingPageAdmin();
    $page = Livewire::test(PricingConfiguration::class)->fillForm(['adjustment' => '0.50'])->call('saveDollar')->assertHasNoErrors();
    expect(PricingSettings::first()->adjustment_micros)->toBe(500000)->and(PricingSettings::first()->exchange_mode)->toBe('REFERENCE')->and(PricingChange::count())->toBe(1);
});

it('C280 renders the shared action modal outside collapsed history so the first click can show it', function () {
    pricingPageAdmin();
    $page = Livewire::test(PricingConfiguration::class);
    foreach ([$page->html(), $page->mountAction('configure')->html()] as $html) {
        $dom = new DOMDocument;
        $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $portals = $xpath->query('//*[@*[name()="wire:partial" and .="action-modals"]]');
        expect($portals->length)->toBe(1);
        foreach ($portals as $portal) {
            expect($xpath->query('ancestor::details', $portal)->length)->toBe(0);
        }
    }
    $page->assertMountedActionModalSee('Precios de venta');
});

it('C280 shows domain validation beside the actual modal field without saving', function (string $field) {
    pricingPageAdmin();
    $data = pricingPageData();
    if ($field === 'new_phone_auto_update') {
        $data[$field] = true;
    } else {
        $data[str_replace('_percent', '_mode', $field)] = 'PERCENT';
        $data[$field] = '-1';
    }
    Livewire::test(PricingConfiguration::class)->callAction('configure', data: $data)->assertHasActionErrors([$field]);
    expect(PricingSettings::count())->toBe(0)->and(PricingChange::count())->toBe(0);
})->with(['accessory_percent', 'used_phone_percent', 'new_phone_percent', 'new_phone_auto_update']);

it('C280 distinguishes an invalid difference from a missing API reading', function () {
    pricingPageAdmin();
    Http::fake(['paralelo.bo/*' => Http::response(['buy' => 12.17, 'timestamp' => now()->toIso8601String()])]);
    Livewire::test(PricingConfiguration::class)->call('loadReference')->fillForm(['adjustment' => '-1'])->assertSee('Corrige la diferencia')->assertDontSee('Esperando una lectura válida');
    expect(PricingSettings::count())->toBe(0);
});

it('C280 closes and reopens without stacking an earlier mounted action', function () {
    pricingPageAdmin();
    $page = Livewire::test(PricingConfiguration::class)->mountAction('configure')->assertSet('mountedActions.0.name', 'configure');
    expect($page->get('mountedActions'))->toHaveCount(1);
    $page->unmountAction()->assertSet('mountedActions', []);
    $page->mountAction('configure')->assertSet('mountedActions.0.name', 'configure');
    expect($page->get('mountedActions'))->toHaveCount(1);
});
