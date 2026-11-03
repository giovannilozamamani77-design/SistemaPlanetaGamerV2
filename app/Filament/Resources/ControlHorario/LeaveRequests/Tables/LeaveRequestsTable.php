<?php

namespace App\Filament\Resources\ControlHorario\LeaveRequests\Tables;

use App\Domain\ControlHorario\Actions\ApproveLeaveAction;
use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Filament\Support\Personal\PersonnelDetails;
use App\Filament\Support\Personal\PersonnelLabels;
use App\Filament\Support\Personal\PersonnelModal;
use App\Filament\Support\Personal\PersonnelTable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeaveRequestsTable
{
    public static function configure(Table $table): Table
    {
        return PersonnelTable::configure($table, 'Solicitudes y días libres', 'Consulta el motivo y responde desde «Resolver». Las decisiones anteriores permanecen en el historial.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'monthlyLeave', 'scheduledWorkday']))
            ->columns([
                TextColumn::make('user.name')->label('Cajera')->searchable()->weight('semibold'),
                TextColumn::make('kind')->label('Solicitud')->formatStateUsing(PersonnelLabels::text(...))
                    ->description(fn (LeaveRequest $record): ?string => $record->reason)->wrap()->limit(65),
                TextColumn::make('monthlyLeave.month')->label('Mes del derecho'),
                TextColumn::make('scheduledWorkday.work_date')->label('Jornada')->date('d/m/Y')->placeholder('Compensación de horario'),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(PersonnelLabels::text(...))->color(PersonnelLabels::color(...)),
                TextColumn::make('created_at')->label('Solicitado')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            ])->filters([
                PersonnelTable::employee(),
                SelectFilter::make('status')->label('Estado')->options(PersonnelLabels::options(['PENDIENTE', 'APROBADA', 'RECHAZADA', 'REVERTIDA'])),
                SelectFilter::make('kind')->label('Tipo de solicitud')->options(PersonnelLabels::options(['AUSENCIA_FUTURA', 'COMPENSAR_RETRASOS', 'COMPENSAR_FALTA'])),
                PersonnelTable::dates('created_at'),
            ])->recordActions([
                PersonnelDetails::action(),
                PersonnelTable::resolve([
                    Action::make('decidir')->label('Aprobar o rechazar')->icon('heroicon-o-check-circle')
                        ->modalHeading('Resolver solicitud de permiso')
                        ->modalDescription('Aprobar consume el día libre completo del mes correspondiente. Rechazar conserva el derecho disponible.')
                        ->visible(fn (LeaveRequest $record): bool => $record->status === 'PENDIENTE')
                        ->schema(PersonnelModal::fields([
                            Select::make('decision')->label('Decisión')->options(['aprobar' => 'Aprobar y consumir día libre', 'rechazar' => 'Rechazar sin consumir'])->required(),
                            Textarea::make('reason')->label('Respuesta administrativa')->helperText('Explica la decisión; la cajera podrá verla en su perfil.')->required(),
                            Toggle::make('exception')->label('Autoaprobación excepcional: único administrador'),
                        ]))->action(function (LeaveRequest $record, array $data): void {
                            app(ApproveLeaveAction::class)->execute(auth()->user(), $record, $data['decision'] === 'aprobar', $data['reason'], (bool) ($data['exception'] ?? false));
                        }),
                ]),
            ]);
    }
}
