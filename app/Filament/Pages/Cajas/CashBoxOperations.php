<?php

namespace App\Filament\Pages\Cajas;

use App\Domain\Cajas\Actions\ExplainOpeningDelayAction;
use App\Domain\Cajas\Actions\PrepareCashBoxClosingAccessAction;
use App\Domain\Cajas\Actions\RecordQuickCashBoxCommissionAction;
use App\Domain\Cajas\Actions\RequestCashBoxEarlyCloseAction;
use App\Domain\Cajas\Actions\ResumeOwnCashBoxAccessAction;
use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Calculators\CashBoxCommissionCalculator;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Queries\CashBoxClosingAccessQuery;
use App\Domain\Cajas\Queries\CashBoxSelectionQuery;
use App\Domain\Cajas\Queries\CashBoxWorkspaceQuery;
use App\Domain\Cajas\Queries\LateOpeningNoticeQuery;
use App\Domain\ControlHorario\Actions\AcknowledgeOpeningDelayAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Filament\Resources\Cajas\CashBoxes\Schemas\CashBoxForm;
use App\Filament\Support\Cajas\CashBoxOperatingActions;
use App\Filament\Support\Cajas\CashBoxTemporaryFundActions;
use App\Filament\Support\Cajas\HandlesCashBoxActionErrors;
use App\Filament\Support\Cajas\InteractsWithCashBoxOpening;
use App\Filament\Support\Cajas\InteractsWithOpeningCashCount;
use App\Filament\Support\ControlHorario\TimeReasonForm;
use App\Filament\Support\Envios\InternationalTransferActions;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use SensitiveParameter;

class CashBoxOperations extends Page implements HasTable
{
    use HandlesCashBoxActionErrors;
    use InteractsWithCashBoxOpening;
    use InteractsWithOpeningCashCount;
    use InteractsWithTable;

    protected string $view = 'cajas.operations';

    protected static ?string $title = 'Operación de cajas';

    public function getTitle(): string
    {
        return 'Cajas';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Cajas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    #[Url(as: 'cashBox', except: null)]
    #[Locked]
    public ?int $selectedCashBoxId = null;

    #[Locked]
    public ?int $accessId = null;

    #[Locked]
    public int $operationsPage = 1;

    public array $quickAch = ['amount' => '', 'movement' => 'DEPOSITO', 'manual_fee' => ''];

    public array $quickServices = ['bill_count' => '', 'manual_fee' => ''];

    #[Locked]
    public ?string $quickAchKey = null;

    #[Locked]
    public ?string $quickServicesKey = null;

    private function resetQuickCommissionForms(): void
    {
        $this->quickAch = ['amount' => '', 'movement' => 'DEPOSITO', 'manual_fee' => ''];
        $this->quickServices = ['bill_count' => '', 'manual_fee' => ''];
        $this->quickAchKey = (string) Str::uuid();
        $this->quickServicesKey = (string) Str::uuid();
        $this->resetValidation();
    }

    public function quickCommissionPreview(string $kind): ?string
    {
        if ($kind === 'ACH') {
            $amount = $this->quickAch['amount'] ?? '';
            if (! is_string($amount) || ! preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/', $amount)) {
                return null;
            }
            $fee = app(CashBoxCommissionCalculator::class)->calculate('ACH', Bolivianos::cents($amount), 0);
        } else {
            $bills = $this->quickServices['bill_count'] ?? '';
            if ((! is_string($bills) && ! is_int($bills)) || ! ctype_digit((string) $bills) || (int) $bills > 10000) {
                return null;
            }
            $fee = app(CashBoxCommissionCalculator::class)->calculate('FACTURAS', 0, (int) $bills);
        }

        return Bolivianos::format($fee);
    }

    public function registerQuickCommission(string $kind): void
    {
        abort_unless(in_array($kind, ['ACH', 'FACTURAS'], true), 404);
        $workspace = $this->workspace;
        abort_unless($workspace && $workspace['session_id'], 403);
        $path = $kind === 'ACH' ? 'quickAch' : 'quickServices';
        $key = $kind === 'ACH' ? $this->quickAchKey : $this->quickServicesKey;
        abort_unless($key, 403);
        $data = [...Arr::only($this->{$path}, $kind === 'ACH' ? ['amount', 'movement', 'manual_fee'] : ['bill_count', 'manual_fee']), 'fee_type' => $kind, 'request_key' => $key];
        if ($kind === 'FACTURAS') {
            $data['amount'] = '0';
        }
        try {
            app(RecordQuickCashBoxCommissionAction::class)->execute(auth()->user(), $workspace['session_id'], $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => [$path.'.'.$field => $messages])->all());
        }
        $this->operationsPage = 1;
        unset($this->workspace);
        if ($kind === 'ACH') {
            $this->quickAch['amount'] = '';
            $this->quickAch['manual_fee'] = '';
            $this->quickAchKey = (string) Str::uuid();
        } else {
            $this->quickServices['bill_count'] = '';
            $this->quickServices['manual_fee'] = '';
            $this->quickServicesKey = (string) Str::uuid();
        }
        $this->resetValidation();
        Notification::make()->title('Comisión registrada')->success()->send();
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('cajas.operar') ?? false;
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        $boxId = request()->integer('cashBox') ?: null;
        $access = app(ResumeOwnCashBoxAccessAction::class)->execute(auth()->user(), $boxId, $boxId === null);
        abort_if($boxId && ! $access, 403);
        if ($access) {
            $this->selectAccess($access);
        }
    }

    public function getHeading(): ?string
    {
        return $this->workspace && $this->workspace['session_id'] ? null : 'Cajas';
    }

    private function selectAccess(CashBoxAccess $access): void
    {
        $this->accessId = $access->id;
        $this->selectedCashBoxId = $access->cash_box_session_id ? $access->cash_box_id : null;
        $this->operationsPage = 1;
        $this->resetQuickCommissionForms();
        unset($this->workspace);
        $this->initializeOpening();
        if ($access->cash_box_session_id && app(LateOpeningNoticeQuery::class)->execute(auth()->user(), $access->cash_box_session_id)) {
            $this->mountAction('lateOpeningNotice');
        } elseif ($access->cash_box_session_id) {
            $this->showScheduleSetupNotice();
        }

    }

    public function resumeBox(int $boxId): void
    {
        $access = app(ResumeOwnCashBoxAccessAction::class)->execute(auth()->user(), $boxId);
        abort_unless($access, 403);
        $this->selectAccess($access);
    }

    public function detalleAperturaAction(): Action
    {
        return Action::make('detalleApertura')->label('Ver detalle de apertura')->icon('heroicon-o-eye')->iconButton()->tooltip('Ver saldos de apertura y actuales')
            ->modalHeading('Detalle de apertura y saldos')->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
            ->modalContent(fn () => view('cajas.opening-details', ['workspace' => $this->workspace]));
    }

    #[Computed]
    public function workspace(): ?array
    {
        return $this->accessId ? app(CashBoxWorkspaceQuery::class)->execute(auth()->user(), $this->accessId, $this->operationsPage) : null;
    }

    public function olderOperations(): void
    {
        abort_unless($this->accessId, 403);
        if (! $this->workspace['operations_page']['has_next']) {
            return;
        }
        $this->operationsPage = min(1000000, $this->operationsPage + 1);
        unset($this->workspace);
    }

    public function newerOperations(): void
    {
        abort_unless($this->accessId, 403);
        $this->operationsPage = max(1, $this->operationsPage - 1);
        unset($this->workspace);
    }

    #[Computed]
    public function selection(): array
    {
        $records = $this->getTableRecords();

        return ['cardStore' => Gate::forUser(auth()->user()->fresh())->allows('sell', CardProduct::class) ? app(CardStoreSessionQuery::class)->status(auth()->user()) : null, 'groups' => app(CashBoxSelectionQuery::class)->groups($records),
            'summary' => ['total' => $records->count(), 'open' => $records->where('has_open_session', true)->count(),
                'pending' => $records->where('has_pending_close', true)->count(),
                'closed' => $records->filter(fn (CashBox $box): bool => ! $box->has_open_session && ! $box->has_pending_close)->count()]];
    }

    public function table(Table $table): Table
    {
        return $table->query(app(CashBoxSelectionQuery::class)->builder(auth()->user()))
            ->heading(null)->description(null)->searchable(false)->paginated(false)->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('Caja'),
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn (string $state): string => CashBoxForm::types()[$state] ?? $state),
            ])->recordActions([
                Action::make('ingresar')->label(fn (CashBox $record): string => $record->has_own_open_session ? 'Continuar' : 'Abrir con PIN')->icon('heroicon-o-key')->modalWidth('lg')
                    ->disabled(fn (CashBox $record): bool => (bool) $record->has_pending_close)
                    ->modalHidden(fn (CashBox $record): bool => (bool) $record->has_own_open_session)
                    ->modalHeading('Ingresar a la caja')->modalSubmitActionLabel('Validar PIN')->modalCancelActionLabel('Cancelar')
                    ->schema(fn (CashBox $record): array => $record->has_own_open_session ? [] : [TextInput::make('pin')->label('PIN personal')->password()->required()->length(4)->autocomplete('off'),
                        Hidden::make('request_key')->default(fn (): string => (string) Str::uuid())])
                    ->action(function (CashBox $record, #[SensitiveParameter] array $data): void {
                        if ($record->has_own_open_session) {
                            $this->resumeBox($record->id);

                            return;
                        }
                        try {
                            $access = app(StartCashBoxAccessAction::class)->execute(auth()->user(), $record, $data['pin'], $data['request_key']);
                        } catch (AuthorizationException $exception) {
                            throw ValidationException::withMessages(['pin' => $exception->getMessage() ?: 'No tienes acceso a esta caja.']);
                        }
                        $this->selectAccess($access);
                        Notification::make()->title($access->cash_box_session_id ? 'Tu sesión fue recuperada' : 'PIN validado: verifica los saldos para abrir')->success()->send();
                    }),
            ]);
    }

    private function showScheduleSetupNotice(): void
    {
        if (! $this->workspace || ! $this->workspace['session_id'] || ! in_array($this->workspace['session_timing']['status'], ['NO_SCHEDULE', 'PENDING'], true)) {
            return;
        }
        $message = $this->workspace['session_timing']['status'] === 'NO_SCHEDULE'
            ? 'Esta caja tiene el control activo, pero tu apertura no está vinculada a una jornada. Administración debe revisar tu turno asignado y el permiso de asistencia. Activar los horarios no asigna esos turnos al personal.'
            : 'La evaluación de horario está pendiente por una incidencia técnica. Administración debe revisarla; no significa que estés a tiempo ni que se haya registrado un retraso.';
        Notification::make()->title('Control de horario: revisión necesaria')->body($message.' Puedes continuar operando y cerrar tu caja. Este aviso no registra multas ni inventa minutos de retraso.')
            ->warning()->persistent()->send();
    }

    public function lateOpeningNoticeAction(): Action
    {
        return Action::make('lateOpeningNotice')->modalHeading('Apertura con retraso')->color('warning')
            ->modalDescription('Revisa el tiempo registrado. Puedes continuar trabajando o enviar una explicación.')
            ->modalContent(fn () => view('control_horario.opening-delay-notice', ['notice' => app(LateOpeningNoticeQuery::class)->execute(auth()->user(), $this->workspace['session_id'])]))
            ->extraModalFooterActions([Action::make('explainDelay')->label('Apelar o explicar retraso')->icon('heroicon-o-chat-bubble-left-right')->color('warning')
                ->visible(fn (): bool => auth()->user()->can('controlhorario.solicitar'))
                ->action(fn () => $this->replaceMountedAction('explainOpeningDelay'))])
            ->modalSubmitActionLabel('Entendido, continuar')->modalCancelAction(false)
            ->action(function (): void {
                $notice = app(LateOpeningNoticeQuery::class)->execute(auth()->user(), $this->workspace['session_id']);
                if ($notice) {
                    app(AcknowledgeOpeningDelayAction::class)->execute(auth()->user(), $notice['id']);
                }
            });
    }

    public function explainOpeningDelayAction(): Action
    {
        return Action::make('explainOpeningDelay')->label('Apelar o explicar retraso')->modalHeading('Explicar el retraso de apertura')
            ->modalDescription('Puedes seguir operando. Administración revisará tu explicación y responderá en tu perfil.')
            ->schema(TimeReasonForm::fields())
            ->modalSubmitActionLabel('Enviar explicación')->modalCancelActionLabel('Volver sin enviar')
            ->action(function (array $data): void {
                app(ExplainOpeningDelayAction::class)->execute(auth()->user(), $this->workspace['session_id'], $data['code'], $data['reason']);
                $this->dispatch('own-time-balance-updated');
                Notification::make()->title('Explicación enviada a Administración')->success()->send();
            });
    }

    #[Computed]
    public function closingAccess(): array
    {
        return app(CashBoxClosingAccessQuery::class)->execute(auth()->user(), $this->workspace['session_id']);
    }

    public function iniciarCierreAction(): Action
    {
        return Action::make('iniciarCierre')->label('Cerrar caja')->icon('heroicon-o-lock-closed')->color('danger')
            ->action(function (): void {
                $gate = app(PrepareCashBoxClosingAccessAction::class)->execute(auth()->user(), $this->workspace['session_id']);
                unset($this->closingAccess, $this->workspace);
                if ($gate['allowed']) {
                    $this->redirect(CashBoxClosingStart::getUrl(['cashBoxSession' => $this->workspace['session_id']]), navigate: false);

                    return;
                }
                $this->replaceMountedAction('solicitarCierreAnticipado');
            });
    }

    public function solicitarCierreAnticipadoAction(): Action
    {
        $gate = fn (): array => $this->closingAccess;

        return Action::make('solicitarCierreAnticipado')->label('Solicitar cierre anticipado')->color('warning')
            ->modalHeading('Cierre anticipado')
            ->modalDescription(fn (): string => $gate()['message'] ?? 'El cierre ya está permitido. Vuelve y pulsa Cerrar caja.')
            ->schema([Textarea::make('reason')->label('Motivo del cierre anticipado')->required()->minLength(3)->maxLength(2000)
                ->visible(fn (): bool => $gate()['early'] && ! $gate()['request']),
                Hidden::make('request_key')->default(fn (): string => (string) Str::uuid())])
            ->modalSubmitAction(fn (Action $action): Action|bool => $gate()['early'] && ! $gate()['request'] ? $action->label('Solicitar cierre anticipado') : false)
            ->modalCancelActionLabel('Seguir trabajando')
            ->modalContent(fn () => view('cajas.early-close-status', ['request' => $gate()['request'], 'departureMinutes' => $gate()['departure_minutes']]))
            ->action(function (array $data): void {
                app(RequestCashBoxEarlyCloseAction::class)->execute(auth()->user(), $this->workspace['session_id'], $data);
                Notification::make()->title('Solicitud enviada a Administración')->body('Tu caja sigue abierta. Puedes continuar registrando comisiones.')->success()->send();
            });
    }

    public function depositoTemporalAction(): Action
    {
        return CashBoxTemporaryFundActions::forOwner($this, 'DEPOSITO');
    }

    public function retiroTemporalAction(): Action
    {
        return CashBoxTemporaryFundActions::forOwner($this, 'RETIRO');
    }

    public function enviarBrasilAction(): Action
    {
        return InternationalTransferActions::create($this->workspace['session_id'], $this->accessId)
            ->visible(fn (): bool => config('envios.enabled', false) && auth()->user()->can('envios.registrar'));
    }

    public function registrarAchAction(): Action
    {
        return CashBoxOperatingActions::movement($this, 'ACH');
    }

    public function registrarServiciosAction(): Action
    {
        return CashBoxOperatingActions::movement($this, 'FACTURAS');
    }

    public function registrarOperacionAction(): Action
    {
        return CashBoxOperatingActions::movement($this);
    }

    public function solicitarCorreccionAction(): Action
    {
        return CashBoxOperatingActions::correction($this);
    }

    public function historialSolicitudesAction(): Action
    {
        return CashBoxOperatingActions::feedback($this);
    }

    public function respuestaCorreccionAction(): Action
    {
        return CashBoxOperatingActions::response($this);
    }

    public function salirDeVistaAction(): Action
    {
        return Action::make('salirDeVista')->label('Volver al listado')->color('gray')
            ->action(function (): void {
                $this->resetCashCount();
                $this->openingBalances = [];
                $this->openingKey = null;
                $this->selectedCashBoxId = null;
                $this->accessId = null;
                unset($this->workspace, $this->selection);
            });
    }
}
