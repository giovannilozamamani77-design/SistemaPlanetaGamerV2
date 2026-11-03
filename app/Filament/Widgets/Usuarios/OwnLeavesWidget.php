<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\ControlHorario\Actions\RequestLeaveAction;
use App\Domain\ControlHorario\Models\MonthlyLeave;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Queries\OwnTimeHistoryQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

class OwnLeavesWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $month = now('America/La_Paz')->format('Y-m');
        $status = MonthlyLeave::where('user_id', auth()->id())->where('month', $month)->value('status') ?? 'DISPONIBLE';

        return $table->paginated([5])->defaultPaginationPageOption(5)->emptyStateHeading('No tienes solicitudes de permiso')->heading('Permisos y día libre')->description('Mes '.$month.' · Día libre: '.$status)
            ->query(app(OwnTimeHistoryQuery::class)->leaves(auth()->user()))->columns([
                TextColumn::make('monthlyLeave.month')->label('Mes'),
                TextColumn::make('kind')->label('Solicitud')->badge(),
                TextColumn::make('scheduledWorkday.work_date')->label('Fecha del turno')->date('d/m/Y'),
                TextColumn::make('status')->label('Decisión')->badge(),
                TextColumn::make('reason')->label('Motivo')->limit(70),
                TextColumn::make('decision_reason')->label('Respuesta administrativa')->limit(90),
                TextColumn::make('decided_at')->label('Resuelto')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            ])->headerActions([
                Action::make('solicitarPermiso')->label('Solicitar permiso o día libre')->visible(fn (): bool => auth()->user()->can('controlhorario.solicitar'))
                    ->schema([
                        Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
                        Select::make('kind')->label('Uso')->options(['AUSENCIA_FUTURA' => 'Día libre para una fecha futura', 'COMPENSAR_RETRASOS' => 'Solicitar resolución de retrasos con día libre', 'COMPENSAR_FALTA' => 'Solicitar resolución de falta o salida anticipada'])->required()->live(),
                        Hidden::make('workday_id'),
                        DatePicker::make('work_date')->label('Fecha del permiso')->helperText('Solo afecta ese día. No cambia tu turno habitual ni se autoriza automáticamente.')
                            ->visible(fn (Get $get): bool => $get('kind') === 'AUSENCIA_FUTURA')->required(fn (Get $get): bool => $get('kind') === 'AUSENCIA_FUTURA' && ! $get('workday_id')),
                        Select::make('period')->label('Turno que necesitas libre')->options(['manana' => 'Mañana', 'tarde' => 'Tarde'])
                            ->visible(fn (Get $get): bool => $get('kind') === 'AUSENCIA_FUTURA')->required(fn (Get $get): bool => $get('kind') === 'AUSENCIA_FUTURA' && ! $get('workday_id')),
                        Select::make('incident_id')->label('Falta completa')->options(fn (): array => TimeIncident::where('user_id', auth()->id())->whereIn('kind', ['FALTA', 'FALTA_PARCIAL'])->where('status', 'CONFIRMADA')->where('pending_minutes', '>', 0)->with('scheduledWorkday')->get()->mapWithKeys(fn (TimeIncident $case): array => [$case->id => 'Falta del '.$case->scheduledWorkday->work_date.' · '.$case->pending_minutes.' minutos'])->all()),
                        Textarea::make('reason')->label('Motivo')->required()->maxLength(2000),
                    ])->action(function (array $data): void {
                        app(RequestLeaveAction::class)->execute(auth()->user(), $data);
                    }),
            ])->defaultSort('id', 'desc');
    }
}
