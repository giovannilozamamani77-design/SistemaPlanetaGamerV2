<?php

use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Queries\TelegramDeliveryQuery;
use App\Filament\Pages\Notificaciones\TelegramDeliveryHistory;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function filterFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);
    $bots = [];
    $dest = [];
    foreach (['CAJAS', 'PRESTAMOS'] as $module) {
        $b = TelegramBotSetting::create(['module' => $module, 'name' => 'Bot '.$module, 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', $module), 'revision' => 1, 'updated_by' => $u->id]);
        $bots[$module] = $b;
        for ($i = 0; $i < 2; $i++) {
            $dest[$module][$i] = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => $module.' destino '.$i, 'chat_id' => (string) (-100123 - $i), 'kind' => 'GROUP', 'revision' => 1, 'updated_by' => $u->id]);
        }
    }

    return [$u, $bots, $dest];
}
function filterRecord($u, $b, $d, string $type, string $time = '2026-10-30 16:00:00', string $status = 'ENVIADA')
{
    if ($type === 'TEST') {
        $r = TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'BOT_TEST_REQUEST', 'source_id' => $b->id, 'snapshot' => ['status' => 'PENDING', 'bot_id' => $b->id, 'module' => $b->module, 'bot_name' => $b->name, 'destination_id' => $d->id, 'destination_name' => $d->name], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'created_at' => $time]);
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'BOT_TEST_RESULT', 'source_id' => $r->id, 'snapshot' => ['status' => $status === 'ENVIADA' ? 'SENT' : 'REJECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'result'), 'created_at' => '2026-11-02 16:00:00']);

        return $r;
    }
    $e = TelegramEventSetting::firstOrCreate(['bot_id' => $b->id, 'event_code' => $b->module === 'CAJAS' ? 'CJ_CIERRE' : 'PR_ALTA'], ['prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $u->id]);
    $r = TelegramDispatch::create(['event_key' => hash('sha256', (string) Str::uuid()), 'module' => $b->module, 'event_code' => $e->event_code, 'source_reference' => 'record:'.Str::uuid(), 'payload_hash' => hash('sha256', 'payload'), 'status' => 'EN_COLA', 'created_at' => $time]);
    TelegramDelivery::create(['dispatch_id' => $r->id, 'bot_id' => $b->id, 'destination_id' => $d->id, 'event_setting_id' => $e->id, 'destination_name' => $d->name, 'chat_id' => $d->chat_id, 'chat_fingerprint' => hash('sha256', $d->chat_id), 'snapshot' => ['bot_name' => $b->name], 'status' => $status]);

    return $r;
}
function filteredHistory($u, string $type, array $filters = [], $module = 'CAJAS', $status = '')
{
    $q = app(TelegramDeliveryQuery::class);

    return $type === 'TEST' ? $q->testHistory($u, $module, $status, $filters) : $q->history($u, $module, $status, $filters);
}
it('filters full inclusive Bolivia dates using UTC boundaries and request date for tests', function ($type) {
    [$u,$bots,$dest] = filterFixture();
    $ids = [];
    foreach (['2026-10-30 03:59:59', '2026-10-30 04:00:00', '2026-10-31 03:59:59', '2026-10-31 04:00:00'] as $time) {
        $ids[] = filterRecord($u, $bots['CAJAS'], $dest['CAJAS'][0], $type, $time)->id;
    }
    $page = filteredHistory($u, $type, ['dateFrom' => '2026-10-30', 'dateTo' => '2026-10-30']);
    expect($page->getCollection()->pluck('id')->all())->toBe([$ids[2], $ids[1]])->and($page->total())->toBe(2);
    Http::assertNothingSent();
})->with(['TEST', 'AUTOMATIC']);
it('combines bot destination result and dates before paginating without duplicates', function ($type) {
    [$u,$bots,$dest] = filterFixture();
    $ids = [];
    for ($i = 0; $i < 7; $i++) {
        $ids[] = filterRecord($u, $bots['CAJAS'], $dest['CAJAS'][0], $type)->id;
    }
    filterRecord($u, $bots['CAJAS'], $dest['CAJAS'][1], $type);
    filterRecord($u, $bots['PRESTAMOS'], $dest['PRESTAMOS'][0], $type);
    $page = filteredHistory($u, $type, ['dateFrom' => '2026-10-30', 'dateTo' => '2026-10-30', 'destinationId' => $dest['CAJAS'][0]->id], 'CAJAS', $type === 'TEST' ? 'SENT' : 'ENVIADA');
    expect($page->total())->toBe(7)->and($page->count())->toBe(5)->and($page->perPage())->toBe(5);
    $view = Livewire::test(TelegramDeliveryHistory::class)->set('type', $type)->set('module', 'CAJAS')->set('destinationId', (string) $dest['CAJAS'][0]->id)->set('dateFrom', '2026-10-30')->set('dateTo', '2026-10-30')->call('setPage', 2);
    expect($view->get('paginators.page'))->toBe(2);
    $view->set('module', 'PRESTAMOS')->assertSet('destinationId', '');
    expect($view->get('paginators.page'))->toBe(1);
    $view->call('clearFilters')->assertSet('dateFrom', '')->assertSet('dateTo', '')->assertSet('module', '')->assertSet('destinationId', '')->assertSet('status', '');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['TEST', 'AUTOMATIC']);
it('retains historic destination choices after it moves to another bot', function ($type) {
    [$u,$bots,$dest] = filterFixture();
    $d = $dest['CAJAS'][0];
    filterRecord($u, $bots['CAJAS'], $d, $type);
    $d->update(['bot_id' => $bots['PRESTAMOS']->id, 'chat_id' => '-100999', 'name' => 'Nombre actual cambiado']);
    $q = app(TelegramDeliveryQuery::class);
    expect($q->filterOptions($u, 'CAJAS', $type)['destinations'])->toHaveKey($d->id);
    expect(filteredHistory($u, $type, ['destinationId' => $d->id])->total())->toBe(1);
    Http::assertNothingSent();
})->with(['TEST', 'AUTOMATIC']);
it('matches destination and result on the same automatic delivery', function () {
    [$u,$bots,$dest] = filterFixture();
    $r = filterRecord($u, $bots['CAJAS'], $dest['CAJAS'][0], 'AUTOMATIC', '2026-10-30 16:00:00', 'ERROR_DEFINITIVO');
    $first = TelegramDelivery::sole();
    $other = $first->replicate();
    $other->destination_id = $dest['CAJAS'][1]->id;
    $other->destination_name = $dest['CAJAS'][1]->name;
    $other->chat_fingerprint = hash('sha256', 'other');
    $other->status = 'ENVIADA';
    $other->save();
    expect(filteredHistory($u, 'AUTOMATIC', ['destinationId' => $first->destination_id], 'CAJAS', 'ENVIADA')->total())->toBe(0);
    $page = filteredHistory($u, 'AUTOMATIC', ['destinationId' => $first->destination_id], 'CAJAS', 'ERROR_DEFINITIVO');
    expect($page->total())->toBe(1)->and($page->items()[0]->deliveries->count())->toBe(1);
    Http::assertNothingSent();
});
it('rejects malformed or reversed filter ranges on both history sources', function ($filters) {
    [$u] = filterFixture();
    foreach (['TEST', 'AUTOMATIC'] as $type) {
        expect(fn () => filteredHistory($u, $type, $filters))->toThrow(ValidationException::class);
    }Http::assertNothingSent();
})->with([[['dateFrom' => 'bad']], [['dateFrom' => '2026-10-31', 'dateTo' => '2026-10-30']], [['dateTo' => '2026-02-30']], [['destinationId' => '1 OR 1=1']], [['destinationId' => -2]]]);

it('supports one sided date ranges and allows clearing a reversed range in the UI', function () {
    [$u,$bots,$dest] = filterFixture();
    filterRecord($u, $bots['CAJAS'], $dest['CAJAS'][0], 'TEST', '2026-10-29 16:00:00');
    filterRecord($u, $bots['CAJAS'], $dest['CAJAS'][0], 'TEST', '2026-10-30 16:00:00');
    expect(filteredHistory($u, 'TEST', ['dateFrom' => '2026-10-30'])->total())->toBe(1)->and(filteredHistory($u, 'TEST', ['dateTo' => '2026-10-29'])->total())->toBe(1);
    $page = Livewire::test(TelegramDeliveryHistory::class)->set('type', 'TEST')->set('dateFrom', '2026-10-30')->set('dateTo', '2026-10-29')->assertHasErrors(['dateTo']);
    $page->set('dateTo', '2026-10-30')->assertHasNoErrors()->set('dateTo', '2026-10-29')->assertHasErrors(['dateTo']);
    $page->call('clearFilters')->assertSet('dateFrom', '')->assertSet('dateTo', '')->assertHasNoErrors();
    Http::assertNothingSent();
});
