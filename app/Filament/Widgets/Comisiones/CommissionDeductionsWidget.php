<?php

namespace App\Filament\Widgets\Comisiones;

use App\Domain\Comisiones\Queries\CommissionDeductionQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Support\Bolivianos;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
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
                TextColumn::make('amount_cents')->label('Descontado')->color('danger')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::format((int) $state)),
                TextColumn::make('before_pending_cents')->label('Pendiente anterior')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::format((int) $state)),
                TextColumn::make('after_pending_cents')->label('Pendiente posterior')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::format((int) $state)),
                TextColumn::make('reason')->label('Motivo')->wrap(),
                TextColumn::make('registered_by_name')->label('Confirmado por')->wrap(),
            ])->emptyStateHeading('Sin descuentos registrados');
    }
}
