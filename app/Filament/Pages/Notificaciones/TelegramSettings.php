<?php

namespace App\Filament\Pages\Notificaciones;

use App\Domain\Notificaciones\Actions\CheckTelegramConnectionAction;
use App\Domain\Notificaciones\Actions\SaveTelegramBotAction;
use App\Domain\Notificaciones\Actions\SaveTelegramDestinationAction;
use App\Domain\Notificaciones\Actions\SendTelegramTestMessageAction;
use App\Domain\Notificaciones\Actions\SetTelegramBotStateAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

class TelegramSettings extends Page
{
    use WithPagination;

    protected string $view = 'notificaciones.telegram-settings';

    protected static ?string $title = 'Telegram';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuraciones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?int $navigationSort = 31;

    public static function canAccess(): bool
    {
        $u = auth()->user()?->fresh();

        return $u && $u->is_active && $u->hasRole('administrador') && $u->can('panel.acceder') && $u->can('notificaciones.telegram.configurar');
    }

    public function mount(): void
    {
        app(TelegramSettingsQuery::class)->authorize(auth()->user());
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getViewData(): array
    {
        $q = app(TelegramSettingsQuery::class);

        $bots = $q->bots(auth()->user());
        $destinations = $q->destinations(auth()->user());

        return ['transportEnabled' => (bool) config('telegram.sending_enabled', false), 'testStatuses' => $q->testStatuses(auth()->user(), $bots), 'modules' => TelegramSettingsQuery::MODULES, 'bots' => $bots, 'destinations' => $destinations, 'changes' => $q->history(auth()->user()), 'connectionStatuses' => $q->connectionStatuses(auth()->user(), $bots, $destinations)];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('deliveryHistory')->label('Historial de Telegram')->icon('heroicon-o-clock')->url(TelegramDeliveryHistory::getUrl())];
    }

    public function testMessageAction(): Action
    {
        return Action::make('testMessage')->label('Mensaje de prueba')->icon('heroicon-o-paper-airplane')->color('gray')
            ->modalHeading('Enviar un mensaje de prueba')->modalDescription('Envía PRUEBA DE CONEXIÓN · Planeta Gamer a un solo destino comprobado. No incluye datos financieros ni cambia los avisos automáticos. Un mensaje real se enviará únicamente al confirmar. Si no se confirma el resultado, revisa Telegram antes de repetir.')
            ->fillForm(fn (array $arguments) => app(TelegramSettingsQuery::class)->testForm(auth()->user(), (int) $arguments['id']))
            ->schema([
                Hidden::make('bot_id'), Hidden::make('expected_revision'), Hidden::make('destination_revisions'), Hidden::make('request_key'),
                Select::make('destination_id')->label('Destino de este bot')->options(fn (Get $get) => app(TelegramSettingsQuery::class)->stateDestinationOptions(auth()->user(), (int) $get('bot_id')))->required()->native(false),
                Checkbox::make('confirmed')->label('Confirmo enviar un único mensaje de prueba a este destino privado autorizado.')->accepted()->required(),
            ])->modalSubmitActionLabel('Enviar prueba')->action(function (array $data): void {
                try {
                    $result = app(SendTelegramTestMessageAction::class)->execute(auth()->user(), $data);
                } catch (ValidationException $exception) {
                    $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                    $errors = [];
                    foreach ($exception->errors() as $key => $messages) {
                        $errors[$path.'.'.$key] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                $notice = Notification::make()->title(SendTelegramTestMessageAction::message($result));
                ($result['status'] === 'SENT' ? $notice->success() : $notice->warning())->send();
            });
    }

    public function stateAction(): Action
    {
        return Action::make('state')->label('Activar o pausar')->icon('heroicon-o-power')->modalHeading('Activar o pausar este bot')
            ->modalDescription('Activar permite enviar nuevos avisos a los destinos elegidos y comprobados, según sus eventos preparados. Pausar omite sus entregas pendientes. No recupera avisos antiguos ni detiene un mensaje que Telegram ya esté recibiendo. Requiere cola y programador operativos.')
            ->fillForm(fn (array $arguments) => app(TelegramSettingsQuery::class)->stateForm(auth()->user(), (int) $arguments['id']))
            ->schema([
                Hidden::make('bot_id'), Hidden::make('expected_revision'), Hidden::make('expected_state_version'), Hidden::make('destination_revisions'), Hidden::make('request_key'),
                Checkbox::make('enabled')->label('Activar envíos de este bot')->live(),
                CheckboxList::make('destination_ids')->label('Destinos autorizados para enviar')->options(fn (Get $get) => app(TelegramSettingsQuery::class)->stateDestinationOptions(auth()->user(), (int) $get('bot_id')))
                    ->visible(fn (Get $get) => (bool) $get('enabled'))->dehydrated()->helperText('Sin selección automática. Antes de activar, comprueba bot y destinos y prepara sus eventos. Guardar no envía un mensaje de prueba.'),
            ])->modalSubmitActionLabel('Guardar estado')->action(function (array $data): void {
                $data['destination_ids'] ??= [];
                try {
                    $bot = app(SetTelegramBotStateAction::class)->execute(auth()->user(), $data);
                } catch (ValidationException $exception) {
                    $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                    $errors = [];
                    foreach ($exception->errors() as $key => $messages) {
                        $errors[$path.'.'.$key] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                Notification::make()->title($bot->is_enabled ? 'Bot activo · solo avisos nuevos' : 'Bot pausado')->success()->send();
            });
    }

    public function checkAction(): Action
    {
        return Action::make('check')->label('Comprobar conexión')->icon('heroicon-o-signal')->modalHeading('Comprobar acceso · sin enviar mensajes')->modalDescription('Solo consultaremos a Telegram. Esta comprobación no cambia el estado de los envíos. Esta consulta no certifica quiénes integran el grupo; Administración debe revisar sus integrantes.')->fillForm(function (array $arguments): array {
            app(TelegramSettingsQuery::class)->authorize(auth()->user());
            $kind = $arguments['kind'] ?? 'BOT';
            abort_unless(in_array($kind, ['BOT', 'DESTINATION'], true), 404);
            $target = $kind === 'BOT' ? TelegramBotSetting::findOrFail($arguments['id']) : TelegramDestination::findOrFail($arguments['id']);
            $bot = $kind === 'BOT' ? $target : $target->bot;

            return ['kind' => $kind, 'id' => $target->id, 'expected_revision' => $target->revision, 'bot_revision' => $bot->revision, 'request_key' => (string) Str::uuid()];
        })->schema([Hidden::make('kind'), Hidden::make('id'), Hidden::make('expected_revision'), Hidden::make('bot_revision'), Hidden::make('request_key')])->action(function (array $data): void {
            $result = app(CheckTelegramConnectionAction::class)->execute(auth()->user(), $data);
            $notice = Notification::make()->title(CheckTelegramConnectionAction::message($result));
            if ($result['status'] === 'CONNECTED') {
                $notice->success();
            } else {
                $notice->warning();
            }
            $notice->send();
        });
    }

    public function botAction(): Action
    {
        return Action::make('bot')->label('Configurar bot')->icon('heroicon-o-key')->modalHeading('Configurar bot · envíos pausados')->modalWidth(Width::TwoExtraLarge)->fillForm(function (array $arguments): array {
            app(TelegramSettingsQuery::class)->authorize(auth()->user());
            $module = $arguments['module'] ?? 'CAJAS';
            abort_unless(array_key_exists($module, TelegramSettingsQuery::MODULES), 404);
            $bot = TelegramBotSetting::select(['id', 'module', 'name', 'revision'])->where('module', $module)->first();

            return ['module' => $module, 'name' => $bot?->name ?? 'Planeta Gamer · '.TelegramSettingsQuery::MODULES[$module], 'token' => '', 'expected_revision' => $bot?->revision ?? 0, 'request_key' => (string) Str::uuid()];
        })->schema([
            Hidden::make('module'), Hidden::make('expected_revision'), Hidden::make('request_key'),
            TextInput::make('name')->label('Nombre para identificar el bot')->required()->maxLength(100),
            TextInput::make('token')->label('Token de BotFather')->password()->revealable(false)->autocomplete('new-password')->maxLength(180)->helperText('Al crear, ingresa el token. Al editar, déjalo vacío para conservarlo. Se cifra y nunca vuelve a mostrarse. Guardarlo no comprueba la conexión ni envía mensajes.'),
        ])->action(function (array $data): void {
            app(SaveTelegramBotAction::class)->execute(auth()->user(), $data);
            Notification::make()->title('Bot guardado · envíos pausados')->success()->send();
        });
    }

    public function destinationAction(): Action
    {
        return Action::make('destination')->label('Agregar destino')->icon('heroicon-o-user-group')->modalHeading('Destino privado autorizado')->modalWidth(Width::TwoExtraLarge)->fillForm(function (array $arguments): array {
            app(TelegramSettingsQuery::class)->authorize(auth()->user());
            $d = isset($arguments['id']) ? TelegramDestination::findOrFail($arguments['id']) : null;

            return ['id' => $d?->id, 'bot_id' => $d?->bot_id, 'responsible_id' => $d?->responsible_id, 'name' => $d?->name, 'chat_id' => $d?->chat_id, 'kind' => $d?->kind ?? 'PRIVATE', 'private_confirmed' => false, 'expected_revision' => $d?->revision ?? 0, 'request_key' => (string) Str::uuid()];
        })->schema([
            Hidden::make('id'), Hidden::make('expected_revision'), Hidden::make('request_key'),
            Select::make('bot_id')->label('Bot del módulo')->options(fn () => app(TelegramSettingsQuery::class)->bots(auth()->user())->pluck('name', 'id'))->required()->native(false),
            TextInput::make('name')->label('Nombre del destino')->placeholder('Administración · Cajas')->required()->maxLength(100),
            Select::make('responsible_id')->label('Persona responsable del destino')->options(fn () => app(TelegramSettingsQuery::class)->recipients(auth()->user()))->required()->searchable()->native(false),
            Select::make('kind')->label('Tipo')->options(['PRIVATE' => 'Chat personal', 'GROUP' => 'Grupo privado de Administración'])->required()->native(false),
            TextInput::make('chat_id')->label('Chat ID de Telegram')->required()->maxLength(32)->helperText('Es el identificador de Telegram, no un teléfono ni un enlace. Grupo: ID negativo. Se comprobará con Telegram antes de activar los envíos.'),
            Checkbox::make('private_confirmed')->label('Confirmo que es un destino privado autorizado, accesible solo a Administración o Encargados.')->accepted()->required(),
        ])->action(function (array $data): void {
            app(SaveTelegramDestinationAction::class)->execute(auth()->user(), $data);
            Notification::make()->title('Destino guardado · envíos pausados')->success()->send();
        });
    }
}
