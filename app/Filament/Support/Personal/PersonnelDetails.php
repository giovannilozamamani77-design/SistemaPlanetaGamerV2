<?php

namespace App\Filament\Support\Personal;

use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class PersonnelDetails
{
    public static function action(): Action
    {
        return PersonnelTable::modal(Action::make('detalle'))->label('Ver detalle')->icon(Heroicon::OutlinedEye)->color('gray')
            ->modalHeading('Información del registro')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
            ->schema(fn (Model $record): array => self::schema($record));
    }

    private static function entry(string $name, string $label, mixed $value): TextEntry
    {
        return TextEntry::make($name)->label($label)->state($value)->placeholder('Sin registro')->columnSpan(in_array($name, ['reason', 'decision', 'note'], true) ? 'full' : 1);
    }

    public static function schema(Model $record): array
    {
        $fields = [
            self::entry('reference', 'Registro', '#'.$record->id),
            self::entry('employee', 'Cajera', $record->user?->name),
        ];
        if ($record instanceof ScheduledWorkday) {
            $fields = [...$fields,
                self::entry('date', 'Fecha del turno', $record->work_date),
                self::entry('period', 'Turno', $record->period === 'tarde' ? 'Tarde' : 'Mañana'),
                self::entry('hours', 'Horario programado', $record->starts_at->setTimezone('America/La_Paz')->format('H:i').' a '.$record->ends_at->setTimezone('America/La_Paz')->format('H:i')),
                self::entry('arrival', 'Llegada por PIN', $record->attendance?->first_pin_at?->setTimezone('America/La_Paz')->format('d/m/Y H:i')),
                self::entry('attendance', 'Asistencia', PersonnelLabels::text($record->attendance?->status)),
            ];
            $help = 'Puedes reprogramar un turno futuro sin asistencia desde «Gestionar». Si ya empezó, se conserva el registro original; los retrasos y ausencias se resuelven en su sección.';
        } elseif ($record instanceof TimeIncident) {
            $fields = [...$fields,
                self::entry('date', 'Jornada', $record->scheduledWorkday?->work_date),
                self::entry('kind', 'Tipo', PersonnelLabels::text($record->kind)),
                self::entry('status', 'Estado', PersonnelLabels::text($record->status)),
                self::entry('minutes', 'Minutos reales / ponderados / pendientes', $record->real_minutes.' / '.$record->nominal_minutes.' / '.$record->pending_minutes),
                self::entry('period', 'Periodo salarial original', $record->period_start ? $record->period_start.' al '.$record->period_end : null),
                self::entry('reason', 'Explicación de la cajera', $record->reason),
                self::entry('decision', 'Respuesta administrativa', $record->decision_reason),
            ];
            $help = '«Resolver» permite revisar la incidencia, aplicar crédito disponible, usar el día libre completo o registrar un descuento real según el estado. Perdonar deja constancia; no equivale a cobrar dinero.';
        } elseif ($record instanceof LeaveRequest) {
            $fields = [...$fields,
                self::entry('kind', 'Solicitud', PersonnelLabels::text($record->kind)),
                self::entry('month', 'Mes del derecho', $record->monthlyLeave?->month),
                self::entry('date', 'Jornada solicitada', $record->scheduledWorkday?->work_date),
                self::entry('status', 'Estado', PersonnelLabels::text($record->status)),
                self::entry('reason', 'Motivo de la solicitud', $record->reason),
                self::entry('decision', 'Decisión administrativa', $record->decision_reason),
            ];
            $help = 'La solicitud no consume el día libre. Al aprobar se utiliza el derecho completo; al rechazar no se consume. Una decisión terminada se conserva en el historial.';
        } elseif ($record instanceof EconomicIncident) {
            $fields = [...$fields,
                self::entry('date', 'Fecha del hecho', $record->occurred_at?->setTimezone('America/La_Paz')->format('d/m/Y H:i')),
                self::entry('box', 'Caja / origen', $record->box_label ?: $record->source_module.' · '.$record->source_reference),
                self::entry('component', 'Componente', $record->component),
                self::entry('cause', 'Causa registrada', PersonnelLabels::text($record->cause)),
                self::entry('status', 'Estado', PersonnelLabels::text($record->status)),
                self::entry('amount', 'Importe original / pendiente', 'Bs '.Bolivianos::format($record->amount_cents).' / Bs '.Bolivianos::format($record->pending_cents)),
                self::entry('reason', 'Observación original', $record->reason),
                self::entry('receipt_justification', 'Justificativo de la cajera', $record->receipt_justification),
                self::entry('receipt_justified_at', 'Justificativo enviado', $record->receipt_justified_at?->setTimezone('America/La_Paz')->format('d/m/Y H:i')),
                self::entry('decision', 'Decisión administrativa', $record->decision),
            ];
            $help = ($record->policy_snapshot['automatic_reception_debt'] ?? false) ? 'Asignado automáticamente al recibir la caja siguiente. La cajera puede justificar después y Administración mantener o anular. Un justificativo no cambia saldos ni reduce el pendiente.' : 'Primero se revisa la responsabilidad. Declarar una reposición no baja la deuda: Administración debe verificar la recepción. Puedes perdonar o anular un error con motivo; no se elimina el registro.';
        } else {
            /** @var GlobalRecovery $record */
            $fields = [...$fields,
                self::entry('date', 'Fecha de declaración', $record->created_at?->setTimezone('America/La_Paz')->format('d/m/Y H:i')),
                self::entry('amount', 'Importe declarado', 'Bs '.Bolivianos::format($record->amount_cents)),
                self::entry('method', 'Medio', PersonnelLabels::text($record->method)),
                self::entry('declared', 'Declarado por', $record->declarant?->name),
                self::entry('operation', 'Operación / destino', trim(($record->operation_reference ?? '').' '.($record->destination ?? ''))),
                self::entry('confirmed', 'Verificado por Administración', $record->confirmed_at?->setTimezone('America/La_Paz')->format('d/m/Y H:i')),
                self::entry('rejected', 'Rechazada el', $record->rejected_at?->setTimezone('America/La_Paz')->format('d/m/Y H:i')),
                self::entry('rejection_reason', 'Motivo del rechazo', $record->rejection_reason),
                self::entry('note', 'Constancia de declaración', $record->note),
            ];
            $help = '«Verificar recepción» exige constancia del ingreso o descuento realmente aplicado. El importe se distribuye internamente entre faltantes pendientes sin restarlo dos veces. Las devoluciones reales se registran desde el faltante relacionado.';
        }

        return [
            Section::make('Datos del registro')->schema($fields)->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Cómo resolverlo')->description($help)->icon(Heroicon::OutlinedInformationCircle),
        ];
    }
}
