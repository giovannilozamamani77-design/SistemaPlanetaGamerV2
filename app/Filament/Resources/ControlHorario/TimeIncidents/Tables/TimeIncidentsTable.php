<?php

namespace App\Filament\Resources\ControlHorario\TimeIncidents\Tables;

use App\Domain\ControlHorario\Actions\ApplyTimeDiscountAction;
use App\Domain\ControlHorario\Actions\ReverseTimeSalaryAction;
use App\Domain\ControlHorario\Actions\ReviewTimeAppealAction;
use App\Domain\ControlHorario\Actions\ReviewTimeIncidentAction;
use App\Domain\ControlHorario\Actions\UseMonthlyLeaveForTimeAction;
use App\Domain\ControlHorario\Actions\UseTimeCreditAction;
use App\Domain\ControlHorario\Calculators\TimeDiscountCalculator;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\ControlHorario\Queries\TimeAppealQuery;
use App\Filament\Support\Personal\PersonnelDetails;
use App\Filament\Support\Personal\PersonnelLabels;
use App\Filament\Support\Personal\PersonnelModal;
use App\Filament\Support\Personal\PersonnelTable;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TimeIncidentsTable
{
    public static function configure(Table $table): Table
    {
        return PersonnelTable::configure($table, 'Retrasos y ausencias', 'Revisa el motivo y el pendiente de cada cajera. Las resoluciones se guardan con constancia y no eliminan registros.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'scheduledWorkday'])
                ->select('time_incidents.*')->addSelect(['has_salary_application' => TimeMovement::selectRaw('1')->whereColumn('time_incident_id', 'time_incidents.id')->where('metadata->kind', 'SALARIO_APLICADO')->limit(1)]))
            ->filters([
                PersonnelTable::employee(),
                SelectFilter::make('status')->label('Estado')->options(PersonnelLabels::options(['PENDIENTE_MOTIVO', 'PENDIENTE_APROBACION', 'EN_REVISION', 'FALTA_PENDIENTE', 'CONFIRMADA', 'JUSTIFICADA', 'PERDONADA', 'INVALIDADA', 'SIN_RETRASO', 'PERMISO_AUTORIZADO'])),
                SelectFilter::make('kind')->label('Tipo')->options(PersonnelLabels::options(['RETRASO', 'FALTA', 'FALTA_PARCIAL'])),
                PersonnelTable::dates('work_date', true, 'scheduledWorkday'),
            ])->columns([
                TextColumn::make('user.name')->label('Cajera')->searchable()->weight('semibold'),
                TextColumn::make('scheduledWorkday.work_date')->label('Jornada')->date('d/m/Y'),
                TextColumn::make('kind')->label('Incidencia')->formatStateUsing(PersonnelLabels::text(...))->badge()->color('gray'),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(PersonnelLabels::text(...))->color(PersonnelLabels::color(...)),
                TextColumn::make('pending_minutes')->label('Minutos pendientes')->weight('semibold')
                    ->description(fn (TimeIncident $record): string => $record->real_minutes.' reales · '.$record->nominal_minutes.' ponderados'),
                TextColumn::make('reason')->label('Explicación de la cajera')->placeholder('Sin explicación')->limit(55)->wrap(),
            ])->recordActions([PersonnelDetails::action(), PersonnelTable::resolve([
                self::appealAction(true),
                self::appealAction(false),
                Action::make('revertirSalario')->label('Registrar reversa salarial')->icon('heroicon-o-arrow-uturn-left')->visible(fn (TimeIncident $record): bool => (bool) $record->has_salary_application)->schema(PersonnelModal::fields([
                    Select::make('movement_id')->label('Descuento aplicado')->options(fn (TimeIncident $record): array => TimeMovement::where('time_incident_id', $record->id)->where('metadata->kind', 'SALARIO_APLICADO')->get()->mapWithKeys(fn ($m): array => [$m->id => $m->metadata['salary_period'].' · Bs '.Bolivianos::format($m->metadata['amount_cents'])])->all())->required(),
                    Toggle::make('returned')->label('La reversa salarial externa ya se aplicó')->accepted(),
                    Textarea::make('receipt')->label('Constancia real')->required(),
                    Toggle::make('exception')->label('Autoaprobación excepcional: único administrador'),
                ]))->action(function (TimeIncident $record, array $data): void {
                    $movement = TimeMovement::where('time_incident_id', $record->id)->findOrFail($data['movement_id']);
                    app(ReverseTimeSalaryAction::class)->execute(auth()->user(), $movement, (bool) $data['returned'], $data['receipt'], (bool) ($data['exception'] ?? false));
                }),
                Action::make('usarCredito')->label('Aplicar crédito disponible')->icon('heroicon-o-arrow-path')->visible(fn (TimeIncident $record): bool => $record->kind === 'RETRASO' && $record->status === 'CONFIRMADA' && $record->pending_minutes > 0)->modalDescription('Aplica solo crédito ya trabajado a retrasos confirmados. No crea horas nuevas ni gasta dos veces un mismo crédito.')->schema(PersonnelModal::fields([
                    Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
                ]))->requiresConfirmation()->action(fn (TimeIncident $record, array $data) => app(UseTimeCreditAction::class)->execute(auth()->user(), $record->user, $data['key'])),
                Action::make('usarDiaLibre')->label('Consumir día libre completo')->visible(fn (TimeIncident $record): bool => $record->status === 'CONFIRMADA' && $record->pending_minutes > 0)->modalDescription('Consume todo el derecho del mes, aunque el pendiente sea menor a una jornada. Los retrasos se compensan hasta 360 minutos; para una falta se resuelve el caso seleccionado.')->schema(PersonnelModal::fields([
                    Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
                    Textarea::make('reason')->label('Motivo y acuerdo')->required(),
                    Toggle::make('exception')->label('Autoaprobación excepcional: único administrador'),
                ]))->action(fn (TimeIncident $record, array $data) => app(UseMonthlyLeaveForTimeAction::class)->execute(auth()->user(), $record, $data['reason'], $data['key'], (bool) ($data['exception'] ?? false))),
                Action::make('revisar')->label('Revisar o cambiar estado')->icon('heroicon-o-pencil-square')->visible(fn (TimeIncident $record): bool => ! in_array($record->status, ['JUSTIFICADA', 'INVALIDADA', 'PERDONADA', 'SIN_RETRASO', 'PERMISO_AUTORIZADO'], true))->modalDescription('Confirma la incidencia, aprueba una justificación válida, corrige un error o perdona el pendiente dejando motivo. La decisión no aplica un descuento de sueldo.')->schema(PersonnelModal::fields([
                    Select::make('decision')->label('Decisión')->options(['CONFIRMAR' => 'Confirmar incidencia', 'JUSTIFICAR' => 'Aprobar justificación', 'INVALIDAR' => 'Invalidar error demostrado', 'PERDONAR' => 'Perdonar / llamada de atención'])->required(),
                    Textarea::make('reason')->label('Motivo de decisión')->required(),
                    Toggle::make('exception')->label('Autoaprobación excepcional: soy el único administrador'),
                ]))->action(function (TimeIncident $record, array $data): void {
                    app(ReviewTimeIncidentAction::class)->execute(auth()->user(), $record, $data['decision'], $data['reason'], (bool) ($data['exception'] ?? false));
                }),
                Action::make('registrarDescuentoAplicado')->label('Registrar descuento aplicado')->visible(fn (TimeIncident $record): bool => $record->status === 'CONFIRMADA' && $record->pending_minutes > 0)
                    ->modalDescription(fn (TimeIncident $record): string => 'Si liquidas todo el pendiente, el importe es Bs '.Bolivianos::format(app(TimeDiscountCalculator::class)->cents($record->pending_minutes)).'. Solo registra una aplicación real en planilla.')
                    ->schema(PersonnelModal::fields([
                        Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
                        TextInput::make('minutes')->label('Minutos realmente liquidados')->integer()->required(),
                        TextInput::make('salary_period')->label('Periodo salarial (AAAA-MM)')->required(),
                        Textarea::make('receipt')->label('Constancia de aplicación real')->required(),
                        Toggle::make('applied')->label('Confirmo que el descuento ya se aplicó')->accepted(),
                        Toggle::make('exception')->label('Autoaprobación excepcional: único administrador'),
                    ]))->action(function (TimeIncident $record, array $data): void {
                        app(ApplyTimeDiscountAction::class)->execute(auth()->user(), $record, (int) $data['minutes'], $data['salary_period'], $data['receipt'], $data['key'], (bool) ($data['exception'] ?? false));
                    }),
            ])]);
    }

    private static function appealAction(bool $accept): Action
    {
        return Action::make($accept ? 'aceptarApelacion' : 'rechazarApelacion')
            ->label($accept ? 'Aceptar apelación' : 'Rechazar apelación')->icon($accept ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')->color($accept ? 'success' : 'danger')
            ->visible(fn (TimeIncident $record): bool => in_array($record->kind, ['RETRASO', 'FALTA_PARCIAL'], true) && in_array($record->status, ['EN_REVISION', 'PENDIENTE_APROBACION'], true))
            ->modalHeading($accept ? 'Aceptar apelación de tiempo' : 'Rechazar apelación de tiempo')->modalWidth('3xl')
            ->modalDescription($accept ? 'Elige la resolución que corresponde. Se conservan el registro original y sus movimientos.' : 'Se conserva la deuda pendiente. La cajera podrá consultar tu respuesta.')
            ->modalSubmitActionLabel($accept ? 'Aceptar apelación' : 'Rechazar apelación')->modalCancelActionLabel('Volver')
            ->modalContent(fn (TimeIncident $record) => view('usuarios.time-appeal-history', app(TimeAppealQuery::class)->execute(auth()->user(), $record)))
            ->fillForm(fn (TimeIncident $record): array => ['appeal_key' => app(TimeAppealQuery::class)->execute(auth()->user(), $record)['appeal_key']])
            ->schema(PersonnelModal::fields([
                Hidden::make('appeal_key')->required(),
                Select::make('resolution')->label('Cómo se resuelve')->options(['JUSTIFICAR' => 'Demora administrativa o falla del sistema verificada', 'INVALIDAR' => 'Error de registro demostrado', 'PERDONAR' => 'Perdonar el pendiente'])->visible($accept)->required($accept),
                Textarea::make('reason')->label('Respuesta para la cajera')->required()->maxLength(2000),
                Toggle::make('exception')->label('Confirmo mi revisión excepcional como único administrador')->visible(fn (TimeIncident $record): bool => $record->user_id === auth()->id()),
            ]))
            ->action(function (TimeIncident $record, array $data) use ($accept): void {
                app(ReviewTimeAppealAction::class)->execute(auth()->user(), $record, $data['appeal_key'], $accept ? 'ACEPTAR' : 'RECHAZAR', $data['reason'], (bool) ($data['exception'] ?? false), $data['resolution'] ?? 'JUSTIFICAR');
                Notification::make()->title('Respuesta de apelación registrada')->success()->send();
            });
    }
}
