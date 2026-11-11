<?php

namespace App\Filament\Support\Comisiones;

use App\Domain\Comisiones\Actions\RegisterCommissionDeductionAction;
use App\Domain\Comisiones\Queries\CommissionDeductionQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Models\User;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionDeductionActions
{
    public static function register(): Action
    {
        return Action::make('deductCommission')->label('Descontar por falta o atraso')->icon('heroicon-o-minus-circle')->color('danger')
            ->disabled(fn (User $record): bool => (int) $record->pending_cents <= 0)
            ->modalHeading(fn (User $record): string => 'Descontar comisiones · '.$record->name)
            ->modalDescription('Reduce las comisiones pendientes por la incidencia elegida. No representa un pago, no mueve dinero de cajas y no elimina ni compensa minutos. Se conserva el motivo y quién lo confirmó. Una incidencia admite un único descuento.')
            ->modalSubmitActionLabel('Confirmar descuento')->modalWidth('2xl')
            ->fillForm(function (User $record): array {
                $balance = app(CommissionPaymentQuery::class)->balance(auth()->user(), $record->id);

                return ['pending_label' => 'Bs '.Bolivianos::format($balance['pending_cents']), 'expected_pending_cents' => $balance['pending_cents'], 'request_key' => (string) Str::uuid(), 'confirmed' => false];
            })
            ->schema([
                TextInput::make('pending_label')->label('Comisiones disponibles')->readOnly()->dehydrated(false),
                Select::make('time_incident_id')->label('Falta o atraso confirmado')->searchable()->required()
                    ->options(fn (User $record): array => app(CommissionDeductionQuery::class)->incidents(auth()->user(), $record->id)->with('scheduledWorkday')->latest('id')->get()->mapWithKeys(fn ($incident): array => [$incident->id => '#'.$incident->id.' · '.$incident->kind.' · '.$incident->scheduledWorkday?->work_date.' · '.$incident->pending_minutes.' min pendientes'])->all())
                    ->helperText('Solo incidencias confirmadas de esta persona, con tiempo pendiente y sin otro descuento.'),
                TextInput::make('amount')->label('Monto del descuento')->prefix('Bs')->inputMode('decimal')->required(),
                Textarea::make('reason')->label('Motivo del descuento')->required()->maxLength(500)->rows(3),
                Hidden::make('expected_pending_cents'), Hidden::make('request_key'),
                Toggle::make('confirmed')->label('Confirmo el monto, el motivo y la reducción de comisiones')->accepted()->required(),
            ])->action(function (User $record, array $data, $livewire): void {
                $data['user_id'] = $record->id;
                try {
                    $deduction = app(RegisterCommissionDeductionAction::class)->execute(auth()->user(), $data);
                } catch (ValidationException $exception) {
                    $path = $livewire->getSchema($livewire->getMountedActionSchemaName())?->getStatePath() ?? 'mountedActions.0.data';
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$path.'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                } catch (QueryException $exception) {
                    report($exception);
                    $path = $livewire->getSchema($livewire->getMountedActionSchemaName())?->getStatePath() ?? 'mountedActions.0.data';
                    throw ValidationException::withMessages([$path.'.amount' => 'No se completó el descuento. Revisa el historial y reintenta desde esta ventana.']);
                }
                $livewire->flushCachedTableRecords();
                $livewire->dispatch('commission-payments-updated');
                Notification::make()->title('Descuento registrado · Bs '.Bolivianos::format($deduction->amount_cents))->success()->send();
            });
    }
}
