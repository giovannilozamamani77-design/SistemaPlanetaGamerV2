<?php

namespace App\Filament\Support\Prestamos;

use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Calculators\LoanTermCalculator;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Queries\LoanQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class LoanTermActions
{
    public static function make(string $name, string $kind): Action
    {
        return Action::make($name)->label($kind === 'RENEW' ? 'Renovar' : 'Ampliar a 30 días')->icon($kind === 'RENEW' ? 'heroicon-o-arrow-path' : 'heroicon-o-calendar-days')->color($kind === 'RENEW' ? 'primary' : 'gray')
            ->visible(fn (Loan $record) => Gate::allows('renew', Loan::class) && in_array($record->displayStatus(), ['ACTIVO', 'CON_MULTA'], true) && ($kind === 'RENEW' || app(LoanTermCalculator::class)->canExtend($record)))
            ->modalHeading($kind === 'RENEW' ? 'Renovar el mismo préstamo' : 'Ampliar el periodo actual de 15 a 30 días')
            ->modalDescription($kind === 'RENEW' ? 'Se paga el interés pendiente actual. El interés del nuevo periodo queda pendiente para su vencimiento. No se entrega capital nuevamente.' : 'Se añaden solo 15 días y se paga la diferencia de interés. Este abono queda reconocido y no se cobrará otra vez.')
            ->fillForm(function (Loan $record) use ($kind): array {
                $days = $kind === 'RENEW' && app(LoanTermCalculator::class)->current($record)['days'] === 15 ? 15 : 30;
                $quote = app(LoanTermCalculator::class)->quote($record, $kind, $days, 1);

                return ['days' => $days, 'periods' => 1, 'expected_revision' => app(LoanTermCalculator::class)->current($record)['revision'], 'expected_paid_cents' => $quote['paid_cents']];
            })
            ->schema([
                Hidden::make('expected_revision'), Hidden::make('expected_paid_cents'), LoanForms::key(),
                View::make('prestamos.term-context')->viewData(fn (Loan $record) => ['borrower' => $record->borrower_snapshot, 'object' => $record->object_snapshot, 'capital_cents' => $record->capital_cents, 'current' => app(LoanTermCalculator::class)->current($record)]),
                Select::make('days')->label('Nuevo periodo')->options([15 => '15 días', 30 => '30 días'])->required()->live()->afterStateUpdated(fn (Get $get, Set $set, Loan $record) => self::refreshAmount($get, $set, $record, $kind))->visible($kind === 'RENEW'),
                Select::make('periods')->label('Cantidad de periodos')->options([1 => '1 periodo', 2 => '2 periodos', 3 => '3 periodos', 4 => '4 periodos'])->required()->live()->afterStateUpdated(fn (Get $get, Set $set, Loan $record) => self::refreshAmount($get, $set, $record, $kind))->visible($kind === 'RENEW'),
                View::make('prestamos.term-preview')->viewData(fn (Get $get, Loan $record) => ['quote' => app(LoanQuery::class)->termPreview(auth()->user(), $record->id, $kind, $kind === 'EXTEND' ? 30 : (int) $get('days'), $kind === 'EXTEND' ? 1 : (int) $get('periods'))]),
                TextInput::make('reference')->label('Número de operación (opcional)')->maxLength(120),
                Checkbox::make('payment_confirmed')->label('Confirmo el cobro mostrado y su ingreso real a Yape Préstamos.')->required()->accepted(),
            ])->modalSubmitActionLabel('Confirmar cobro y guardar')->action(function (array $data, Loan $record, Component $livewire) use ($kind): void {
                $data['kind'] = $kind;
                if ($kind === 'EXTEND') {
                    $data['days'] = 30;
                    $data['periods'] = 1;
                }
                $event = app(ChangeLoanTermAction::class)->execute(auth()->user(), $record->id, $data);
                $livewire->dispatch('loan-term-changed', loanId: $record->id);
                Notification::make()->title('Operación registrada en el mismo préstamo')->success()->actions([Action::make('print')->label('Ver comprobante')->url(route('prestamos.renovacion-pdf', ['change' => $event->id]))->openUrlInNewTab()])->send();
            });
    }

    private static function refreshAmount(Get $get, Set $set, Loan $record, string $kind): void
    {
        $quote = app(LoanQuery::class)->termPreview(auth()->user(), $record->id, $kind, (int) $get('days'), (int) $get('periods'));
        $set('expected_paid_cents', $quote['paid_cents'] ?? null);
    }
}
