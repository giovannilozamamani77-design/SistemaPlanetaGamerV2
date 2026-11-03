<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Support\Bolivianos;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

class OwnGlobalRecoveriesWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    #[On('personnel-record-updated')]
    public function refreshRepayments(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        Gate::authorize('incidencias.ver_propios');

        return $table->query(GlobalRecovery::where('user_id', auth()->id()))->heading('Pagos del acumulado')->description('Si un reporte es rechazado, revisa el motivo y vuelve a informar desde el acumulado con el ID correcto. La deuda solo baja al verificar el ingreso.')
            ->columns([
                TextColumn::make('created_at')->label('Declarada')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('amount_cents')->label('Importe')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::format($state)),
                TextColumn::make('method')->label('Medio'),
                TextColumn::make('operation_reference')->label('ID de transacción')->wrap(),
                TextColumn::make('estado')->label('Estado')->state(fn (GlobalRecovery $record): string => $record->rejected_at ? 'Rechazada' : ($record->confirmed_at ? 'Verificada' : 'Por verificar'))->badge()->color(fn (GlobalRecovery $record): string => $record->rejected_at ? 'danger' : ($record->confirmed_at ? 'success' : 'warning')),
                TextColumn::make('rejection_reason')->label('Motivo del rechazo')->wrap()->placeholder('—'),
                TextColumn::make('rejected_at')->label('Rechazada el')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->placeholder('—'),
                TextColumn::make('confirmed_at')->label('Verificada')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->placeholder('Pendiente'),
            ])->defaultSort('id', 'desc')->paginated([5])->defaultPaginationPageOption(5);
    }
}
