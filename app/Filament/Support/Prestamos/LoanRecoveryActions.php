<?php

namespace App\Filament\Support\Prestamos;

use App\Domain\Prestamos\Actions\RegisterLoanObjectExpenseAction;
use App\Domain\Prestamos\Actions\SellLoanObjectAction;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Queries\LoanQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Gate;

class LoanRecoveryActions
{
    public static function expense(): Action
    {
        return Action::make('objectExpense')->label('Registrar gasto del objeto')->icon('heroicon-o-wrench-screwdriver')->color('warning')
            ->visible(fn (Loan $record) => Gate::allows('manageLost', Loan::class) && $record->displayStatus() === 'PERDIDA_TOTAL')
            ->modalHeading('Gasto de reparación o preparación')->modalWidth('lg')->modalDescription('Se descuenta realmente de Yape Préstamos y aumenta el mínimo de venta. El capital original del préstamo se conserva.')
            ->schema([LoanForms::key(), View::make('prestamos.expense-context')->viewData(fn () => ['available' => app(LoanQuery::class)->recoveryCash(auth()->user())]), TextInput::make('amount')->label('Gasto pagado')->prefix('Bs')->required(), Textarea::make('description')->label('Reparación o gasto realizado')->required()->maxLength(500)->rows(2), Checkbox::make('payment_confirmed')->label('Confirmo que este gasto ya se pagó desde Yape Préstamos.')->required()->accepted()])
            ->modalSubmitActionLabel('Registrar gasto')->action(function (array $data, Loan $record): void {
                app(RegisterLoanObjectExpenseAction::class)->execute(auth()->user(), $record->id, $data);
                Notification::make()->title('Gasto registrado; mínimo de venta actualizado')->success()->send();
            });
    }

    public static function sale(): Action
    {
        return Action::make('sellObject')->label('Registrar como vendido')->icon('heroicon-o-shopping-bag')->color('success')
            ->visible(fn (Loan $record) => Gate::allows('sell', Loan::class) && $record->displayStatus() === 'PERDIDA_TOTAL')
            ->modalHeading('Venta de objeto perdido')->modalWidth('2xl')->modalDescription('El precio debe cubrir capital, interés, multa y gastos registrados. Confirma únicamente cuando el importe completo ya ingresó a Yape Préstamos.')
            ->fillForm(fn (Loan $record) => ['expected_expense_cents' => app(LoanQuery::class)->recoveryPreview(auth()->user(), $record->id)['expense_cents'] ?? 0])
            ->schema([LoanForms::key(), Hidden::make('expected_expense_cents'), TextInput::make('price')->label('Precio de venta')->prefix('Bs')->required()->live(onBlur: true), View::make('prestamos.recovery-preview')->viewData(fn (Get $get, Loan $record) => ['quote' => app(LoanQuery::class)->recoveryPreview(auth()->user(), $record->id, $get('price'))]), TextInput::make('buyer')->label('Comprador (opcional)')->maxLength(160), Textarea::make('sale_notes')->label('Estado del objeto y observaciones (opcional)')->rows(2)->maxLength(500), TextInput::make('reference')->label('Número de operación (opcional)')->maxLength(120), Checkbox::make('payment_confirmed')->label('Confirmo el ingreso real del precio completo a Yape Préstamos.')->required()->accepted()])
            ->modalSubmitActionLabel('Confirmar venta')->action(function (array $data, Loan $record): void {
                app(SellLoanObjectAction::class)->execute(auth()->user(), $record->id, $data);
                Notification::make()->title('Venta registrada; objeto vendido')->success()->actions([Action::make('receipt')->label('Ver comprobante')->url(route('prestamos.recibo-pdf', ['loan' => $record->id]))->openUrlInNewTab()])->send();
            });
    }
}
