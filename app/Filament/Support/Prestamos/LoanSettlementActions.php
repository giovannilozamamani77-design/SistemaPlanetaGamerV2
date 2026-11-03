<?php

namespace App\Filament\Support\Prestamos;

use App\Domain\Prestamos\Actions\SettleLoanAction;
use App\Domain\Prestamos\Calculators\LoanSettlementCalculator;
use App\Domain\Prestamos\Models\Loan;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Gate;

class LoanSettlementActions
{
    public static function make(): Action
    {
        return Action::make('settle')->label('Registrar pago y entregar')->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (Loan $record) => Gate::allows('settle', Loan::class) && in_array($record->displayStatus(), ['ACTIVO', 'CON_MULTA'], true))
            ->modalHeading('Pago completo y entrega del objeto')->modalWidth('lg')
            ->fillForm(function (Loan $record): array {
                $quote = app(LoanSettlementCalculator::class)->quote($record);

                return ['expected_revision' => $quote['revision'], 'expected_paid_cents' => $quote['paid_cents']];
            })
            ->schema([LoanForms::key(), Hidden::make('expected_revision'), Hidden::make('expected_paid_cents'),
                View::make('prestamos.settlement-summary')->viewData(fn (Loan $record) => ['summary' => app(LoanSettlementCalculator::class)->quote($record) + ['borrower' => $record->borrower_snapshot, 'object' => $record->object_snapshot]]),
                TextInput::make('reference')->label('Número de operación (opcional)')->maxLength(120),
                Checkbox::make('payment_confirmed')->label('Confirmo el ingreso real del total a Yape Préstamos.')->required()->accepted(),
                Checkbox::make('delivery_confirmed')->label('Confirmo la entrega física del objeto al cliente.')->required()->accepted()])
            ->modalSubmitActionLabel('Confirmar pago y entrega')->action(function (array $data, Loan $record): void {
                app(SettleLoanAction::class)->execute(auth()->user(), $record->id, $data);
                Notification::make()->title('Pago y entrega registrados')->success()->actions([Action::make('receipt')->label('Ver comprobante')->url(route('prestamos.recibo-pdf', ['loan' => $record->id]))->openUrlInNewTab()])->send();
            });
    }
}
