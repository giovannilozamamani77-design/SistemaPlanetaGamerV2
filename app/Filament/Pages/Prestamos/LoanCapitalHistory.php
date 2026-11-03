<?php

namespace App\Filament\Pages\Prestamos;

use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class LoanCapitalHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Movimientos del fondo de Préstamos';

    protected string $view = 'prestamos.term-history';

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('manageFund', Loan::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('Ajustes de Préstamos')->url(LoanSettings::getUrl())];
    }

    public function table(Table $table): Table
    {
        return $table->query(app(LoanQuery::class)->fundMovements(auth()->user()))->columns([
            TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i', timezone: 'America/La_Paz'),
            TextColumn::make('loan_id')->label('Préstamo'),
            TextColumn::make('kind')->label('Movimiento')->formatStateUsing(fn ($state) => ['INITIAL' => 'Saldo inicial', 'IN' => 'Aporte', 'OUT' => 'Retiro', 'DISBURSEMENT' => 'Capital prestado', 'ANNULMENT' => 'Capital restablecido por anulación', 'SETTLEMENT' => 'Pago completo y entrega', 'OBJECT_EXPENSE' => 'Gasto de objeto perdido', 'OBJECT_SALE' => 'Venta de objeto perdido', 'TERM_PAYMENT' => 'Renovación: interés y multa'][$state] ?? $state),
            TextColumn::make('delta_cents')->label('Importe')->formatStateUsing(fn ($state) => ($state < 0 ? '−' : '+').' Bs '.Bolivianos::formatCompact(abs($state))),
            TextColumn::make('after_cents')->label('Disponible posterior')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('user.name')->label('Registró'), TextColumn::make('reference')->label('Referencia'),
        ])->defaultSort('id', 'desc')->paginated([5, 10, 25])->defaultPaginationPageOption(5);
    }
}
