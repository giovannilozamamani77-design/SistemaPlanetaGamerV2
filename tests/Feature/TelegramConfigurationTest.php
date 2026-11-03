<?php

use App\Domain\Notificaciones\Actions\SaveTelegramBotAction;
use App\Domain\Notificaciones\Actions\SaveTelegramDestinationAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Filament\Pages\Notificaciones\TelegramSettings;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function telegramAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);

    return $u;
}
function telegramBotInput(array $changes = []): array
{
    return array_replace(['module' => 'CAJAS', 'name' => 'Bot de prueba', 'token' => '123456:'.str_repeat('A', 35), 'expected_revision' => 0, 'request_key' => (string) Str::uuid()], $changes);
}
function telegramDestinationInput(User $u, TelegramBotSetting $bot, array $changes = []): array
{
    return array_replace(['bot_id' => $bot->id, 'responsible_id' => $u->id, 'name' => 'Administración de prueba', 'kind' => 'GROUP', 'chat_id' => '-100123456789', 'private_confirmed' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()], $changes);
}
it('C318 encrypts credentials and keeps bots paused with an immutable secret-free audit', function () {
    $u = telegramAdmin();
    $input = telegramBotInput();
    $bot = app(SaveTelegramBotAction::class)->execute($u, $input);
    expect($bot->token)->toBe($input['token'])->and($bot->is_enabled)->toBeFalse();
    expect(DB::table('telegram_bot_settings')->value('token'))->not->toBe($input['token']);
    expect($bot->toArray())->not->toHaveKeys(['token', 'token_fingerprint']);
    expect(json_encode(TelegramConfigurationChange::sole()->toArray()))->not->toContain($input['token']);
    expect(fn () => TelegramConfigurationChange::sole()->update(['actor_name' => 'Changed']))->toThrow(LogicException::class);
    Http::assertNothingSent();
});
it('C318 preserves blank tokens replaces explicitly and rejects stale revisions and shared bots', function () {
    $u = telegramAdmin();
    $bot = app(SaveTelegramBotAction::class)->execute($u, telegramBotInput());
    $update = telegramBotInput(['token' => '', 'expected_revision' => 1, 'name' => 'Nuevo nombre']);
    app(SaveTelegramBotAction::class)->execute($u, $update);
    expect($bot->fresh()->token)->toBe('123456:'.str_repeat('A', 35));
    app(SaveTelegramBotAction::class)->execute($u, $update);
    expect(TelegramConfigurationChange::count())->toBe(2);
    expect(fn () => app(SaveTelegramBotAction::class)->execute($u, telegramBotInput(['expected_revision' => 1])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveTelegramBotAction::class)->execute($u, telegramBotInput(['module' => 'TARJETAS'])))->toThrow(ValidationException::class);
    $new = telegramBotInput(['token' => '654321:'.str_repeat('B', 35), 'expected_revision' => 2]);
    app(SaveTelegramBotAction::class)->execute($u, $new);
    expect($bot->fresh()->token)->toBe($new['token'])->and($bot->fresh()->revision)->toBe(3)->and($bot->fresh()->is_enabled)->toBeFalse();
});
it('C318 rejects activation and malformed missing credentials without persisting changes', function (array $changes) {
    $u = telegramAdmin();
    expect(fn () => app(SaveTelegramBotAction::class)->execute($u, telegramBotInput($changes)))->toThrow(ValidationException::class);
    expect(TelegramBotSetting::count())->toBe(0)->and(TelegramConfigurationChange::count())->toBe(0);
    Http::assertNothingSent();
})->with([[['token' => '']], [['token' => 'bad-token']], [['module' => 'HORARIO']], [['is_enabled' => true]]]);
it('C318 keeps private destinations paused and deduplicates requests and bot chat pairs', function () {
    $u = telegramAdmin();
    $bot = app(SaveTelegramBotAction::class)->execute($u, telegramBotInput());
    $data = telegramDestinationInput($u, $bot);
    $d = app(SaveTelegramDestinationAction::class)->execute($u, $data);
    app(SaveTelegramDestinationAction::class)->execute($u, $data);
    expect(TelegramDestination::count())->toBe(1)->and($d->is_enabled)->toBeFalse();
    expect(fn () => app(SaveTelegramDestinationAction::class)->execute($u, telegramDestinationInput($u, $bot)))->toThrow(ValidationException::class);
    $edit = telegramDestinationInput($u, $bot, ['id' => $d->id, 'expected_revision' => 1, 'name' => 'Nombre nuevo']);
    app(SaveTelegramDestinationAction::class)->execute($u, $edit);
    expect($d->fresh()->revision)->toBe(2)->and($d->fresh()->name)->toBe('Nombre nuevo');
    expect(fn () => app(SaveTelegramDestinationAction::class)->execute($u, array_replace($edit, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
it('C318 rejects unapproved destinations or cashiers as recipients', function (array $changes) {
    $u = telegramAdmin();
    $bot = app(SaveTelegramBotAction::class)->execute($u, telegramBotInput());
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    if (isset($changes['cashier'])) {
        $changes = ['responsible_id' => $cashier->id];
    }
    expect(fn () => app(SaveTelegramDestinationAction::class)->execute($u, telegramDestinationInput($u, $bot, $changes)))->toThrow(ValidationException::class);
    expect(TelegramDestination::count())->toBe(0);
})->with([[['private_confirmed' => false]], [['chat_id' => '@public']], [['kind' => 'PRIVATE']], [['cashier' => true]], [['is_enabled' => true]]]);
it('C318 denies nonadministrators and revoked permissions even through domain actions', function (string $role) {
    $admin = telegramAdmin();
    $u = User::factory()->create();
    $u->assignRole($role);
    $u->givePermissionTo('notificaciones.telegram.configurar');
    test()->actingAs($u);
    test()->get(TelegramSettings::getUrl())->assertForbidden();
    expect(fn () => app(SaveTelegramBotAction::class)->execute($u, telegramBotInput()))->toThrow(AuthorizationException::class);
    expect(TelegramBotSetting::count())->toBe(0);
})->with(['cajera', 'encargado']);
it('C318 renders and edits safely without exposing saved token or activating messages', function () {
    $u = telegramAdmin();
    $data = telegramBotInput();
    $bot = app(SaveTelegramBotAction::class)->execute($u, $data);
    test()->get(TelegramSettings::getUrl())->assertOk()->assertSee('Envíos de Telegram pausados')->assertDontSee($data['token']);
    $page = Livewire::test(TelegramSettings::class)->mountAction('bot', ['module' => 'CAJAS'])->assertActionDataSet(['token' => '', 'expected_revision' => 1]);
    $page->setActionData(['name' => 'Desde pantalla', 'token' => ''])->callMountedAction()->assertHasNoActionErrors();
    expect($bot->fresh()->name)->toBe('Desde pantalla')->and($bot->fresh()->token)->toBe($data['token']);
    $page->mountAction('destination')->setActionData(telegramDestinationInput($u, $bot))->callMountedAction()->assertHasNoActionErrors();
    expect(TelegramDestination::sole()->is_enabled)->toBeFalse();
    Http::assertNothingSent();
});
it('C318 rolls back configuration when audit fails without exposing or partially saving a token', function () {
    $u = telegramAdmin();
    DB::statement("CREATE TRIGGER fail_telegram_audit BEFORE INSERT ON telegram_configuration_changes BEGIN SELECT RAISE(ABORT,'audit failure'); END");
    expect(fn () => app(SaveTelegramBotAction::class)->execute($u, telegramBotInput()))->toThrow(QueryException::class);
    expect(TelegramBotSetting::count())->toBe(0);
    Http::assertNothingSent();
});

it('C318 rechecks deactivation and individual restrictions and rejects another admins request key', function () {
    $u = telegramAdmin();
    $data = telegramBotInput();
    app(SaveTelegramBotAction::class)->execute($u, $data);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => app(SaveTelegramBotAction::class)->execute($other, $data))->toThrow(AuthorizationException::class);
    $permission = Permission::findByName('notificaciones.telegram.configurar', 'web');
    $u->restrictedPermissions()->attach($permission->id);
    expect(fn () => app(TelegramSettingsQuery::class)->bots($u))->toThrow(AuthorizationException::class);
    $other->forceFill(['is_active' => false])->save();
    expect(fn () => app(SaveTelegramBotAction::class)->execute($other, telegramBotInput(['module' => 'TARJETAS'])))->toThrow(AuthorizationException::class);
    expect(TelegramBotSetting::count())->toBe(1);
});
it('C318 accepts an active manager as destination owner but rejects an inactive owner', function () {
    $u = telegramAdmin();
    $bot = app(SaveTelegramBotAction::class)->execute($u, telegramBotInput());
    $manager = User::factory()->create();
    $manager->assignRole('encargado');
    $data = telegramDestinationInput($manager, $bot, ['kind' => 'PRIVATE', 'chat_id' => '123456789']);
    $d = app(SaveTelegramDestinationAction::class)->execute($u, $data);
    expect($d->responsible_id)->toBe($manager->id)->and($d->is_enabled)->toBeFalse();
    $manager->forceFill(['is_active' => false])->save();
    expect(fn () => app(SaveTelegramDestinationAction::class)->execute($u, array_replace($data, ['id' => $d->id, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    expect($d->fresh()->revision)->toBe(1);
});

it('C318 opens an empty configuration without registering bots or contacting Telegram', function () {
    telegramAdmin();
    test()->get(TelegramSettings::getUrl())->assertOk()->assertSee('Bot sin configurar')->assertSee('Sin destinos');
    expect(TelegramBotSetting::count())->toBe(0)->and(TelegramDestination::count())->toBe(0);
    Http::assertNothingSent();
});
