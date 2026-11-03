<?php

namespace App\Filament\Pages\Notificaciones;

use App\Domain\Notificaciones\Actions\SaveTelegramEventAction;
use App\Domain\Notificaciones\Actions\SaveTelegramTemplateAction;
use App\Domain\Notificaciones\Actions\SendTelegramTestMessageAction;
use App\Domain\Notificaciones\Queries\TelegramEventsQuery;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Domain\Notificaciones\Queries\TelegramTemplateQuery;
use App\Domain\Notificaciones\Services\TelegramTemplateRenderer;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class TelegramEvents extends Page
{
    protected string $view = 'notificaciones.telegram-events';

    protected static ?string $title = 'Eventos y mensajes de Telegram';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $module = 'CAJAS';

    public static function canAccess(): bool
    {
        return TelegramSettings::canAccess();
    }

    public function mount(): void
    {
        app(TelegramEventsQuery::class)->forModule(auth()->user(), $this->module);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('settings')->label('Bots y destinos')->icon('heroicon-o-arrow-left')->url(TelegramSettings::getUrl())];
    }

    public function selectModule(string $module): void
    {
        app(TelegramEventsQuery::class)->forModule(auth()->user(), $module);
        $this->module = $module;
    }

    protected function getViewData(): array
    {
        return app(TelegramEventsQuery::class)->forModule(auth()->user(), $this->module) + ['modules' => TelegramSettingsQuery::MODULES];
    }

    public function templateAction(): Action
    {
        return Action::make('template')->label('Plantilla e imagen')->icon('heroicon-o-pencil-square')->color('warning')->size('sm')->modalHeading('Editar plantilla e imagen')->modalWidth(Width::FourExtraLarge)
            ->modalDescription('Personaliza el encabezado y las notas. El bloque {{detalle}} conserva los datos registrados. Guardar solo afecta avisos nuevos; no envía mensajes ni activa el bot.')
            ->fillForm(fn (array $arguments) => app(TelegramTemplateQuery::class)->form(auth()->user(), $arguments['module'], $arguments['event_code']))
            ->schema([
                Hidden::make('module'), Hidden::make('event_code'), Hidden::make('expected_revision'), Hidden::make('request_key'),
                Textarea::make('body')->label('Texto de la plantilla')->rows(7)->required()->maxLength(1200)
                    ->helperText('Marcadores: {{titulo}}, {{detalle}}, {{modulo}} y {{evento}}. Conserva {{detalle}} una sola vez. No se ejecuta código.')
                    ->hintAction(Action::make('restoreText')->label('Restaurar texto original')->action(fn (Set $set) => $set('body', TelegramTemplateRenderer::DEFAULT_BODY))),
                Select::make('image_mode')->label('Imagen del aviso')->options(['ORIGINAL' => 'Original automática del sistema', 'CUSTOM' => 'Usar mi imagen', 'NONE' => 'Sin imagen'])->required()->native()->live(),
                FileUpload::make('image')->label('Nueva imagen')->image()->acceptedFileTypes(['image/png', 'image/jpeg'])->maxSize(256)->storeFiles(false)
                    ->visible(fn (Get $get) => $get('image_mode') === 'CUSTOM')->dehydrated(fn (Get $get) => $get('image_mode') === 'CUSTOM')
                    ->helperText('PNG o JPEG · hasta 256 KB. Si ya guardaste una imagen personalizada, puedes conservarla sin volver a subirla.'),
            ])->modalSubmitActionLabel('Guardar plantilla')->action(function (array $data): void {
                $this->configurationOperation(fn () => app(SaveTelegramTemplateAction::class)->execute(auth()->user(), $data));
                Notification::make()->title('Plantilla guardada · revisa la vista previa')->success()->send();
            });
    }

    public function templateTestAction(): Action
    {
        return Action::make('templateTest')->label('Enviar prueba')->icon('heroicon-o-paper-airplane')->color('gray')->size('sm')->modalHeading('Probar esta notificación')
            ->modalDescription('Se enviará una única prueba con datos ficticios de la plantilla guardada. No registra operaciones ni activa avisos automáticos. Máximo una prueba por bot cada minuto. Si el resultado queda incierto, revisa Telegram antes de repetir.')
            ->fillForm(fn (array $arguments) => app(TelegramTemplateQuery::class)->testForm(auth()->user(), $arguments['module'], $arguments['event_code']))
            ->schema([
                Hidden::make('bot_id'), Hidden::make('event_code'), Hidden::make('expected_revision'), Hidden::make('expected_template_revision'), Hidden::make('destination_revisions'), Hidden::make('request_key'),
                Select::make('destination_id')->label('Destino de este bot')->options(fn (Get $get) => app(TelegramSettingsQuery::class)->stateDestinationOptions(auth()->user(), (int) $get('bot_id')))->required()->native(false),
                Checkbox::make('confirmed')->label('Confirmo enviar esta prueba al destino seleccionado')->accepted()->required(),
            ])->modalSubmitActionLabel('Enviar una prueba')->action(function (array $data): void {
                $result = $this->configurationOperation(fn () => app(SendTelegramTestMessageAction::class)->execute(auth()->user(), $data));
                $notice = Notification::make()->title(SendTelegramTestMessageAction::message($result));
                ($result['status'] === 'SENT' ? $notice->success() : $notice->warning())->send();
            });
    }

    private function configurationOperation(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[$path.'.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public function previewAction(): Action
    {
        return Action::make('preview')->label('Vista previa')->icon('heroicon-o-eye')->color('gray')->modalHeading('Vista previa · ejemplo sin envío')->modalWidth(Width::TwoExtraLarge)->modalSubmitAction(false)->modalCancelActionLabel('Volver')->modalContent(fn (array $arguments) => view('notificaciones.telegram-message-preview', ['preview' => app(TelegramEventsQuery::class)->preview(auth()->user(), $arguments['module'], $arguments['event_code'])]));
    }

    public function eventAction(): Action
    {
        return Action::make('event')->label('Configurar')->icon('heroicon-o-cog-6-tooth')->modalHeading('Preparar evento y destinos')->modalDescription('Preparar no activa un bot pausado. Si está activo, los cambios se aplican a nuevos avisos solo para destinos habilitados y comprobados.')->modalWidth(Width::TwoExtraLarge)
            ->fillForm(fn (array $arguments) => app(TelegramEventsQuery::class)->form(auth()->user(), $arguments['module'], $arguments['event_code']))
            ->schema([
                Hidden::make('module'), Hidden::make('event_code'), Hidden::make('expected_revision'), Hidden::make('bot_revision'), Hidden::make('destination_revisions'), Hidden::make('request_key'),
                Checkbox::make('prepared')->label('Preparar este evento para los destinos elegidos')->helperText('Solo genera avisos si el bot y los destinos están habilitados y comprobados.'),
                CheckboxList::make('destination_ids')->label('Destinos de este bot')->options(fn (Get $get) => app(TelegramEventsQuery::class)->destinationOptions(auth()->user(), (string) $get('module')))->searchable()->columns(1)->helperText('Solo se muestran destinos de este bot con responsable activo de Administración o Encargado. Sin selección automática.'),
            ])->modalContent(fn (array $arguments) => view('notificaciones.telegram-message-preview', ['preview' => app(TelegramEventsQuery::class)->preview(auth()->user(), $arguments['module'], $arguments['event_code'])]))
            ->modalSubmitActionLabel('Guardar evento')->action(function (array $data): void {
                try {
                    app(SaveTelegramEventAction::class)->execute(auth()->user(), $data);
                } catch (ValidationException $exception) {
                    Notification::make()->title(collect($exception->errors())->flatten()->first())->warning()->send();
                    $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                    $errors = [];
                    foreach ($exception->errors() as $key => $messages) {
                        $errors[$path.'.'.$key] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                Notification::make()->title('Evento guardado · conserva el estado del bot')->success()->send();
            });
    }
}
