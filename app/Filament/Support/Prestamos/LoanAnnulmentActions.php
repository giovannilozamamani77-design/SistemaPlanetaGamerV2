<?php

namespace App\Filament\Support\Prestamos;

use App\Domain\Prestamos\Actions\AnnulLoanAction;
use App\Domain\Prestamos\Models\Loan;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

class LoanAnnulmentActions
{
    public static function make(): Action
    {
        return Action::make('annul')->label('Anular préstamo')->icon('heroicon-o-no-symbol')->color('danger')
            ->visible(fn (Loan $record) => Gate::allows('annul', Loan::class) && $record->status === 'ACTIVO')
            ->disabled(fn (Loan $record) => $record->latestTermChange !== null || $record->displayStatus() === 'PERDIDA_TOTAL')
            ->modalHeading('Anular préstamo y restablecer capital')->modalWidth('lg')
            ->modalDescription(fn (Loan $record) => 'Se restablecerán Bs '.Bolivianos::formatCompact($record->capital_cents).' al fondo Yape Préstamos. El registro se conservará como anulado, con tu nombre y la fecha. No se cobran intereses.')
            ->schema([LoanForms::key(), Textarea::make('reason')->label('Motivo (opcional)')->maxLength(500)->rows(2), Checkbox::make('capital_returned')->label('Confirmo que el capital ya fue devuelto realmente a Yape Préstamos.')->required()->accepted()])
            ->modalSubmitActionLabel('Anular y restablecer capital')->action(function (array $data, Loan $record): void {
                app(AnnulLoanAction::class)->execute(auth()->user(), $record->id, $data);
                Notification::make()->title('Préstamo anulado; capital restablecido')->success()->send();
            });
    }
}
