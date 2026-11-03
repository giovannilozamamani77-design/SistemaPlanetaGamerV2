<?php

namespace App\Filament\Resources\Incidencias\GlobalRecoveries\Tables;

use App\Domain\Incidencias\Actions\ConfirmGlobalRecoveryAction;
use App\Domain\Incidencias\Actions\RejectGlobalRecoveryAction;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Filament\Support\Personal\PersonnelDetails;
use App\Filament\Support\Personal\PersonnelLabels;
use App\Filament\Support\Personal\PersonnelModal;
use App\Filament\Support\Personal\PersonnelTable;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GlobalRecoveriesTable
{
    public static function configure(Table $table): Table
    {
        return PersonnelTable::configure($table, 'Reposiciones globales', 'Registra una entrega parcial o total sin elegir cada faltante. Solo Administración verifica su recepción.')->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'declarant']))->columns([
            TextColumn::make('user.name')->label('Cajera')->searchable()->weight('semibold'),
            TextColumn::make('created_at')->label('Declarada')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->sortable(),
            TextColumn::make('amount_cents')->label('Importe')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::format($state)),
            TextColumn::make('method')->label('Medio')->formatStateUsing(PersonnelLabels::text(...)),
            TextColumn::make('operation_reference')->label('Operación')->placeholder('No corresponde'),
            TextColumn::make('estado_recepcion')->label('Estado')->state(fn (GlobalRecovery $record): string => $record->rejected_at ? 'Rechazada' : ($record->confirmed_at ? 'Verificada' : 'Por verificar'))->badge()->color(fn (GlobalRecovery $record): string => $record->rejected_at ? 'danger' : ($record->confirmed_at ? 'success' : 'warning')),
            TextColumn::make('confirmed_at')->label('Recibido por Administración')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->placeholder('Pendiente de verificación'),
            TextColumn::make('rejection_reason')->label('Motivo del rechazo')->wrap()->placeholder('—'),
            TextColumn::make('note')->label('Constancia')->limit(60),
        ])->filters([
            PersonnelTable::employee(),
            TernaryFilter::make('recepcion')->label('Recepción')->placeholder('Todas')->trueLabel('Verificadas')->falseLabel('Pendientes')
                ->queries(true: fn (Builder $query): Builder => $query->whereNotNull('confirmed_at'), false: fn (Builder $query): Builder => $query->whereNull('confirmed_at')->whereNull('rejected_at')),
            SelectFilter::make('rechazo')->label('Rechazo')->options(['rechazadas' => 'Rechazadas'])->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->whereNotNull('rejected_at') : $query),
            SelectFilter::make('method')->label('Medio')->options(PersonnelLabels::options(['EFECTIVO', 'DEPOSITO', 'QR', 'SUELDO'])),
            PersonnelTable::dates('created_at'),
        ])->recordActions([PersonnelDetails::action(), PersonnelTable::resolve([
            Action::make('recibir')->label('Verificar recepción')->icon('heroicon-o-check-badge')->modalHeading('Confirmar reposición recibida')->modalDescription('Verifica el ingreso o descuento salarial real. La deuda se reducirá una sola vez y quedará vinculada a los faltantes correspondientes.')->visible(fn (GlobalRecovery $record): bool => ! $record->confirmed_at && ! $record->rejected_at)
                ->schema(PersonnelModal::fields([Toggle::make('received')->label('Confirmo recepción o aplicación salarial real')->accepted(), Textarea::make('receipt')->label('Constancia')->required(), Toggle::make('exception')->label('Autoaprobación excepcional: único administrador')]))
                ->action(fn (GlobalRecovery $record, array $data) => app(ConfirmGlobalRecoveryAction::class)->execute(auth()->user(), $record, (bool) $data['received'], $data['receipt'], (bool) ($data['exception'] ?? false))),
            Action::make('rechazar')->label('Rechazar reporte')->icon('heroicon-o-x-circle')->color('danger')
                ->modalHeading('Rechazar reposición global')->modalDescription('El reporte se conservará y la deuda no cambiará. La titular podrá informar nuevamente el depósito con el ID correcto.')
                ->visible(fn (GlobalRecovery $record): bool => ! $record->confirmed_at && ! $record->rejected_at)
                ->schema(PersonnelModal::fields([Textarea::make('reason')->label('Motivo del rechazo')->required()->minLength(3)->maxLength(2000), Toggle::make('exception')->label('Revisión excepcional de mi propio reporte')->visible(fn (GlobalRecovery $record): bool => $record->user_id === auth()->id())]))
                ->action(fn (GlobalRecovery $record, array $data) => app(RejectGlobalRecoveryAction::class)->execute(auth()->user(), $record, $data['reason'], (bool) ($data['exception'] ?? false))),
        ])]);
    }
}
