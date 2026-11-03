<?php

namespace App\Filament\Support\Prestamos;

use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Queries\LoanQuery;
use Filament\Actions\Action;

class LoanDetailActions
{
    public static function make(string $name = 'detail'): Action
    {
        return Action::make($name)->label('Ver préstamo')->modalHeading(fn (Loan $record) => 'Préstamo '.$record->id)->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
            ->extraModalFooterActions(fn (Loan $record) => [
                LoanSettlementActions::make(),
                LoanRecoveryActions::expense(),
                LoanRecoveryActions::sale(),
                LoanAnnulmentActions::make(),
                LoanTermActions::make('renewDetail', 'RENEW'),
                LoanTermActions::make('extend', 'EXTEND'),
                self::history(),
                Action::make('printDetail')->label('Imprimir recibo vigente')->visible(fn (Loan $record) => $record->status !== 'ANULADO')->icon('heroicon-o-printer')->color('gray')->url(route('prestamos.recibo-pdf', ['loan' => $record->id, 'print' => 1]))->openUrlInNewTab(),
                Action::make('printOriginalDetail')->label('Recibo original')->color('gray')->url(route('prestamos.recibo-pdf', ['loan' => $record->id, 'original' => 1]))->openUrlInNewTab(),
            ])->modalContent(fn (Loan $record) => view('prestamos.loan-detail', ['detail' => app(LoanQuery::class)->detail(auth()->user(), $record->id)]));
    }

    public static function history(): Action
    {
        return Action::make('termHistory')->label('Historial de renovaciones')->icon('heroicon-o-clock')->iconButton()->tooltip('Historial de renovaciones y recibos')->color('gray')
            ->modalHeading(fn (Loan $record) => 'Historial del préstamo '.$record->id)->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Volver al detalle')
            ->modalContent(function (Loan $record) {
                app(LoanQuery::class)->loans(auth()->user())->findOrFail($record->id);

                return view('prestamos.history-modal', ['loanId' => $record->id]);
            });
    }
}
