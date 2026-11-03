<?php

namespace App\Filament\Pages\Notificaciones;

use App\Domain\Notificaciones\Actions\RetryTelegramDeliveryAction;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Queries\TelegramDeliveryQuery;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

class TelegramDeliveryHistory extends Page
{
    use WithPagination;

    protected string $view = 'notificaciones.telegram-delivery-history';

    protected static ?string $title = 'Historial de Telegram';

    protected static bool $shouldRegisterNavigation = false;

    public string $type = 'AUTOMATIC';

    public string $module = '';

    public string $status = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $destinationId = '';

    public static function canAccess(): bool
    {
        return TelegramSettings::canAccess();
    }

    public function mount(): void
    {
        app(TelegramSettingsQuery::class)->authorize(auth()->user());
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function updatedType(): void
    {
        $this->status = '';
        $this->destinationId = '';
        $this->resetPage();
    }

    public function updatedModule(): void
    {
        $this->destinationId = '';
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedDestinationId(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        app(TelegramSettingsQuery::class)->authorize(auth()->user());
        $this->module = $this->status = $this->dateFrom = $this->dateTo = $this->destinationId = '';
        $this->resetValidation();
        $this->resetPage();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('settings')->label('Bots y destinos')->icon('heroicon-o-arrow-left')->url(TelegramSettings::getUrl())];
    }

    protected function getViewData(): array
    {
        app(TelegramSettingsQuery::class)->authorize(auth()->user());
        if (! in_array($this->type, ['AUTOMATIC', 'TEST'], true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de historial inválido.']);
        }
        $query = app(TelegramDeliveryQuery::class);

        $filter = ['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo, 'destinationId' => $this->destinationId];
        $options = $query->filterOptions(auth()->user(), $this->module, $this->type);

        $this->resetErrorBag(['dateFrom', 'dateTo', 'destinationId']);
        $filterError = false;
        try {
            $history = $this->type === 'TEST'
                ? $query->testHistory(auth()->user(), $this->module, $this->status, $filter)
                : $query->history(auth()->user(), $this->module, $this->status, $filter);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                if (! in_array($field, ['dateFrom', 'dateTo', 'destinationId'], true)) {
                    throw $exception;
                }
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
            $filterError = true;
            $history = new LengthAwarePaginator([], 0, 5, 1, ['path' => request()->url()]);
        }

        return ['filterOptions' => $options, 'filterError' => $filterError, 'history' => $history,
            'modules' => TelegramSettingsQuery::MODULES, 'states' => $this->type === 'TEST' ? TelegramDeliveryQuery::TEST_STATES : TelegramDelivery::LABELS];
    }

    public function detailAction(): Action
    {
        return Action::make('detail')->label('Detalle')->icon('heroicon-o-eye')->modalHeading('Aviso automático · detalle')->modalCancelActionLabel('Volver al historial')->modalSubmitAction(false)->modalWidth(Width::FourExtraLarge)->modalContent(fn (array $arguments) => view('notificaciones.telegram-delivery-detail', app(TelegramDeliveryQuery::class)->detail(auth()->user(), (int) $arguments['id']) + ['states' => TelegramDelivery::LABELS]));
    }

    public function testDetailAction(): Action
    {
        return Action::make('testDetail')->label('Detalle')->icon('heroicon-o-eye')->modalHeading('Mensaje de prueba · detalle')->modalSubmitAction(false)->modalCancelActionLabel('Volver al historial')->modalWidth(Width::ThreeExtraLarge)
            ->modalContent(fn (array $arguments) => view('notificaciones.telegram-test-detail', app(TelegramDeliveryQuery::class)->testDetail(auth()->user(), (int) $arguments['id']) + ['states' => TelegramDeliveryQuery::TEST_STATES]));
    }

    public function retryAction(): Action
    {
        return Action::make('retry')->label('Reintentar')->icon('heroicon-o-arrow-path')->color('warning')->disabled(fn () => ! config('telegram.sending_enabled', false))->tooltip('Mientras esté pausado no se permiten reintentos')->modalHeading('Reintento manual de la misma entrega')->modalDescription('Si quedó incierta, Telegram pudo haber recibido el aviso. Revisa el chat antes de repetir. Un reintento manual no reinicia los tres intentos automáticos.')->fillForm(fn (array $arguments) => app(TelegramDeliveryQuery::class)->retryForm(auth()->user(), (int) $arguments['id']))->schema([
            Hidden::make('delivery_id'), Hidden::make('expected_attempt_count'), Hidden::make('request_key'), TextInput::make('reason')->label('Motivo del reintento')->required()->maxLength(300), Checkbox::make('confirm_uncertain')->label('Si el resultado era incierto, revisé el destino y acepto el posible aviso duplicado.'),
        ])->action(function (array $data): void {
            try {
                app(RetryTelegramDeliveryAction::class)->execute(auth()->user(), $data);
            } catch (ValidationException $e) {
                Notification::make()->title(collect($e->errors())->flatten()->first())->warning()->send();
                $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($v, $k) => [$path.'.'.$k => $v])->all());
            }
            Notification::make()->title('Reintento solicitado para la misma entrega')->success()->send();
        });
    }
}
