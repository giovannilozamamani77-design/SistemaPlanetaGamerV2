<?php

namespace App\Filament\Resources\ControlHorario\ScheduledWorkdays\Tables;

use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Filament\Support\Personal\PersonnelDetails;
use App\Filament\Support\Personal\PersonnelLabels;
use App\Filament\Support\Personal\PersonnelTable;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScheduledWorkdaysTable
{
    public static function configure(Table $table): Table
    {
        return PersonnelTable::configure($table, 'Historial diario y excepciones', 'Cinco registros recientes por página. Selecciona un turno para consultar su detalle.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'attendance']))
            ->columns([
                TextColumn::make('user.name')->label('Cajera')->searchable()->weight('semibold'),
                TextColumn::make('work_date')->label('Fecha del turno')->date('d/m/Y')->sortable(),
                TextColumn::make('period')->label('Turno')->badge()->formatStateUsing(fn (string $state): string => $state === 'tarde' ? 'Tarde' : 'Mañana')->color('primary'),
                TextColumn::make('starts_at')->label('Horario')->dateTime('H:i')->timezone('America/La_Paz')
                    ->description(fn (ScheduledWorkday $record): string => 'Hasta '.$record->ends_at->setTimezone('America/La_Paz')->format('H:i')),
                TextColumn::make('attendance.status')->label('Asistencia')->placeholder('Sin marca de PIN')->badge()
                    ->formatStateUsing(PersonnelLabels::text(...))->color(PersonnelLabels::color(...)),
            ])->filters([
                PersonnelTable::employee(),
                SelectFilter::make('period')->label('Turno')->options(['manana' => 'Mañana', 'tarde' => 'Tarde']),
                SelectFilter::make('asistencia')->label('Registro de asistencia')->options(['registrada' => 'Con marca de PIN', 'sin_registro' => 'Sin marca de PIN'])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(($data['value'] ?? null) === 'registrada', fn (Builder $query): Builder => $query->whereHas('attendance'))
                        ->when(($data['value'] ?? null) === 'sin_registro', fn (Builder $query): Builder => $query->whereDoesntHave('attendance'))),
                PersonnelTable::dates('work_date', true),
            ])->recordActions([
                PersonnelDetails::action(),
                PersonnelTable::resolve([
                    EditAction::make()->label('Reprogramar turno')->modal()->modalHeading('Reprogramar turno')
                        ->visible(fn (ScheduledWorkday $record): bool => $record->starts_at > now() && $record->attendance === null)
                        ->mutateRecordDataUsing(function (array $data, ScheduledWorkday $record): array {
                            $data['starts_time'] = $record->starts_at->setTimezone('America/La_Paz')->format('H:i');
                            $data['ends_time'] = $record->ends_at->setTimezone('America/La_Paz')->format('H:i');

                            return $data;
                        })->using(fn (ScheduledWorkday $record, array $data): ScheduledWorkday => app(SaveScheduledWorkdayAction::class)->execute(auth()->user(), $data, $record)),
                ], 'Gestionar'),
            ]);
    }
}
