<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Support\Bolivianos;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Gate;

class OwnContributionsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        Gate::authorize('incidencias.ver_propios');

        return $table->paginated([5])->defaultPaginationPageOption(5)->emptyStateHeading('No tienes aportes registrados')->heading('Mis reposiciones informadas')->query(RecoveryPayment::where('paid_by_user_id', auth()->id())->with(['receiver', 'economicIncident:id,box_label']))->columns([
            TextColumn::make('created_at')->label('Declarado')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            TextColumn::make('operation_reference')->label('ID de transacción')->placeholder('Registro anterior'),
            TextColumn::make('economicIncident.box_label')->label('Caja')->placeholder('Otro origen'),
            TextColumn::make('amount_cents')->label('Importe')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::format($state)),
            TextColumn::make('estado')->label('Estado')->state(fn (RecoveryPayment $record): string => $record->rejected_at ? 'Rechazada' : ($record->confirmed_at ? 'Verificada' : 'Por verificar'))->badge(),
            TextColumn::make('rejection_reason')->label('Respuesta')->placeholder('Sin observaciones')->wrap(),
            TextColumn::make('confirmed_at')->label('Verificado')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->placeholder('Pendiente'),
            TextColumn::make('receiver.name')->label('Verifica')->placeholder('Pendiente'),
        ])->defaultSort('id', 'desc');
    }
}
