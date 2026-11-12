<?php

namespace App\Filament\Widgets\Comisiones;

use App\Domain\Comisiones\Actions\VoidCommissionDeductionAction;
use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Domain\Comisiones\Queries\CommissionDeductionQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class CommissionDeductionsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    #[Locked]
    public int $userId;

    public function mount(?int $userId = null): void
    {
        $this->userId = $userId ?? auth()->id();
        app(CommissionPaymentQuery::class)->authorize(auth()->user(), $this->userId);
    }

    public function table(Table $table): Table
    {
        return $table->heading('Historial de descuentos de comisiones')
            ->query(app(CommissionDeductionQuery::class)->history(auth()->user(), $this->userId))
            ->queryStringIdentifier('commissionDeductions')->defaultSort('id', 'desc')->paginated([5, 15, 25])
            ->columns([
                TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i:s')->timezone('America/La_Paz'),
                TextColumn::make('time_incident_id')->label('Incidencia')->formatStateUsing(fn ($state): string => '#'.$state)
                    ->description(fn ($record): string => ($record->snapshot['incident_kind'] ?? '').' · '.$record->incident?->scheduledWorkday?->work_date),
                TextColumn::make('status')->label('Estado')->state(fn ($record): string => $record->reversal ? 'Anulado' : 'Aplicado')->badge()->color(fn ($record): string => $record->reversal ? 'gray' : 'danger'),
                TextColumn::make('reversal.reason')->label('Motivo de anulación')->placeholder('—')->wrap(),
                TextColumn::make('reversal.created_at')->label('Anulado el')->dateTime('d/m/Y H:i:s')->timezone('America/La_Paz')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reversal.voided_by_name')->label('Anulado por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reversal.after_pending_cents')->label('Pendiente tras anular')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::format((int) $state))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount_cents')->label('Descontado')->color('danger')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::format((int) $state)),
                TextColumn::make('before_pending_cents')->label('Pendiente anterior')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::format((int) $state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('after_pending_cents')->label('Pendiente posterior')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::format((int) $state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reason')->label('Motivo')->wrap(),
                TextColumn::make('registered_by_name')->label('Confirmado por')->wrap(),
            ])->recordActions([
                Action::make('voidDeduction')->label('Anular descuento')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                    ->visible(fn ($record): bool => app(AdminCommissionsQuery::class)->canView(auth()->user()) && auth()->user()->can('controlhorario.revisar') && ! $record->reversal)
                    ->modalHeading('Anular descuento de comisiones')->modalDescription(fn ($record): string => 'Se devolverán Bs '.Bolivianos::format($record->amount_cents).' a las comisiones pendientes. El descuento original y el motivo de anulación quedarán en el historial. No cambia cajas ni minutos.')
                    ->modalSubmitActionLabel('Confirmar anulación')->schema([
                        Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
                        Textarea::make('reason')->label('Motivo de anulación')->required()->maxLength(500),
                        Toggle::make('confirmed')->label('Confirmo la anulación y devolución del importe')->required()->accepted(),
                    ])->action(function ($record, array $data): void {
                        $data['deduction_id'] = $record->id;
                        try {
                            app(VoidCommissionDeductionAction::class)->execute(auth()->user(), $data);
                        } catch (ValidationException $exception) {
                            $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                            $errors = [];
                            foreach ($exception->errors() as $field => $messages) {
                                $errors[$path.'.'.$field] = $messages;
                            }
                            throw ValidationException::withMessages($errors);
                        } catch (QueryException $exception) {
                            report($exception);
                            $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                            throw ValidationException::withMessages([$path.'.reason' => 'No se completó la anulación. Revisa el historial y reintenta desde esta ventana.']);
                        }
                        $this->flushCachedTableRecords();
                        $this->dispatch('commission-payments-updated');
                        Notification::make()->title('Descuento anulado; importe devuelto al pendiente')->success()->send();
                    }),
            ])->emptyStateHeading('Sin descuentos registrados');
    }
}
