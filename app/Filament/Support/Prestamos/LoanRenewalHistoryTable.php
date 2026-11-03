<?php

namespace App\Filament\Support\Prestamos;

use App\Domain\Prestamos\Data\LoanRenewalHistoryData;
use App\Domain\Prestamos\Models\LoanTermChange;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoanRenewalHistoryTable
{
    public static function configure(Table $table, int $loanId): Table
    {
        abort_unless(auth()->check(), 403);

        return $table->query(app(LoanQuery::class)->termChanges(auth()->user(), $loanId))->heading('Historial de renovaciones')->description('La fecha del pago puede ser anterior al periodo renovado. Todas las operaciones pertenecen al mismo préstamo #'.$loanId.'.')->columns([
            TextColumn::make('created_at')->label('Pagado el')->dateTime('d/m/Y H:i', timezone: 'America/La_Paz')->sortable(),
            TextColumn::make('kind')->label('Operación')->formatStateUsing(fn ($state) => $state === 'RENEW' ? 'Renovación' : 'Ampliación')->badge()->color('info'),
            TextColumn::make('period')->label('Periodo añadido')->state(fn (LoanTermChange $record) => app(LoanRenewalHistoryData::class)->present($record)['period'])->description(fn (LoanTermChange $record) => app(LoanRenewalHistoryData::class)->present($record)['duration'])->wrap(),
            TextColumn::make('summary.registered_by')->label('Atendió')->wrap(),
            TextColumn::make('summary.pending_cents')->label('Interés pendiente')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('summary.paid_cents')->label('Total cobrado')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state))->description(fn (LoanTermChange $record) => app(LoanRenewalHistoryData::class)->present($record)['payment_breakdown']),
        ])->recordActions([
            Action::make('receipt')->label('Recibo de esta operación')->icon('heroicon-o-printer')->iconButton()->tooltip('Ver o imprimir el recibo de esta operación')->url(fn (LoanTermChange $record) => route('prestamos.renovacion-pdf', ['change' => $record->id]))->openUrlInNewTab(),
        ])->defaultSort('id', 'desc')->paginated([5, 10, 25])->defaultPaginationPageOption(5)->emptyStateHeading('Sin renovaciones registradas')->emptyStateDescription('Al renovar, se conservarán aquí el pago, el periodo y su recibo.');
    }
}
