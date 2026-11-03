<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\ControlHorario\Actions\SubmitTimeReasonAction;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Queries\OwnTimeHistoryQuery;
use App\Domain\ControlHorario\Queries\PersonnelTimePeriodQuery;
use App\Domain\ControlHorario\Queries\TimeAppealQuery;
use App\Filament\Support\ControlHorario\TimeReasonForm;
use App\Filament\Support\Personal\PersonnelLabels;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OwnTimeHistoryWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $period = app(PersonnelTimePeriodQuery::class)->execute(auth()->user());
        $summary = $period['period'] ? 'Pendiente anterior: '.$period['previous'].' min · Periodo '.$period['period']['start'].' al '.$period['period']['end'].': '.$period['current'].' min' : 'Configurar día salarial para separar periodos; todos los pendientes se conservan.';

        return $table->description($summary)->paginated([5])->defaultPaginationPageOption(5)->emptyStateHeading('No tienes retrasos ni faltas registrados')->heading('Retrasos y faltas')->query(app(OwnTimeHistoryQuery::class)->incidents(auth()->user()))->columns([
            TextColumn::make('scheduledWorkday.work_date')->label('Fecha')->date('d/m/Y'),
            TextColumn::make('period_start')->label('Periodo desde')->date('d/m/Y'),
            TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn (string $state): string => $state === 'RETRASO' ? 'Tardanza de apertura' : PersonnelLabels::text($state))->badge(),
            TextColumn::make('status')->label('Estado')->formatStateUsing(PersonnelLabels::text(...))->badge()->color(PersonnelLabels::color(...)),
            TextColumn::make('real_minutes')->label('Minutos reales'),
            TextColumn::make('nominal_minutes')->label('Minutos registrados'),
            TextColumn::make('pending_minutes')->label('Pendientes'),
            TextColumn::make('reason')->label('Mi explicación')->limit(70)->wrap(),
            TextColumn::make('decision_reason')->label('Respuesta administrativa')->limit(90)->wrap(),
        ])->recordActions([
            Action::make('historialApelacion')->label('Ver explicación y respuestas')->icon('heroicon-o-clock')->color('gray')
                ->modalHeading('Historial de revisión')->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                ->modalContent(fn (TimeIncident $record) => view('usuarios.time-appeal-history', app(TimeAppealQuery::class)->execute(auth()->user(), $record))),
            Action::make('solicitarRevision')->label(fn (TimeIncident $record): string => $record->kind === 'RETRASO' ? 'Apelar retraso' : 'Explicar o solicitar revisión')->icon('heroicon-o-chat-bubble-left-right')->color('warning')
                ->modalHeading('Explicar y solicitar revisión')->modalDescription('Escribe qué ocurrió. Administración revisará tu explicación; el pendiente se conserva hasta una resolución. Puedes continuar trabajando en Caja.')->modalSubmitActionLabel('Enviar explicación')->modalCancelActionLabel('Volver')
                ->visible(fn (TimeIncident $record): bool => auth()->user()->can('controlhorario.solicitar') && ! in_array($record->status, ['JUSTIFICADA', 'INVALIDADA', 'PERDONADA', 'SIN_RETRASO', 'PERMISO_AUTORIZADO'], true))
                ->schema(TimeReasonForm::fields())->action(function (TimeIncident $record, array $data): void {
                    app(SubmitTimeReasonAction::class)->execute(auth()->user(), $record, $data['code'], $data['reason']);
                    $this->dispatch('own-time-balance-updated');
                    Notification::make()->title('Explicación enviada a Administración')->success()->send();
                }),
        ])->defaultSort('id', 'desc');
    }
}
