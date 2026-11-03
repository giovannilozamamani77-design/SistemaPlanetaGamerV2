<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\ControlHorario\Queries\OwnTimeHistoryQuery;
use App\Support\Bolivianos;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OwnTimeAuditWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table->paginated([5])->defaultPaginationPageOption(5)->emptyStateHeading('Todavía no hay decisiones registradas')->heading('Historial de decisiones y aplicaciones')->query(app(OwnTimeHistoryQuery::class)->events(auth()->user()))->columns([
            TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            TextColumn::make('actor.name')->label('Registrado por')->placeholder('Sistema'),
            TextColumn::make('action')->label('Hecho')->badge(),
            TextColumn::make('details.month')->label('Mes'),
            TextColumn::make('details.reason')->label('Respuesta o motivo')->limit(100)->wrap(),
            TextColumn::make('details.receipt')->label('Constancia')->limit(100)->wrap(),
            TextColumn::make('details.minutes')->label('Minutos'),
            TextColumn::make('details.amount_cents')->label('Importe registrado')->formatStateUsing(fn (?int $state): string => $state === null ? '—' : 'Bs '.Bolivianos::format($state)),
        ])->defaultSort('id', 'desc');
    }
}
