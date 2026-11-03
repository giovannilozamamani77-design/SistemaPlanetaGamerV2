<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Incidencias\Data\IncidentOrigin;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OwnIncidentDetailQuery
{
    public function execute(User $actor, int $incidentId): array
    {
        Gate::forUser(User::findOrFail($actor->id))->authorize('incidencias.ver_propios');
        $record = EconomicIncident::findOrFail($incidentId);
        if ($record->user_id !== $actor->id) {
            throw new AuthorizationException;
        }
        $events = DB::table('economic_history_events')->where('economic_incident_id', $record->id)->orderBy('id')->get()->map(function ($event) {
            $event->details = json_decode($event->details, true, 512, JSON_THROW_ON_ERROR);

            return $event;
        });

        return ['origin' => $this->origin($record), 'record' => $record, 'payments' => $record->recoveries()->with(['payer', 'receiver'])->get(), 'events' => $events];
    }

    private function origin(EconomicIncident $record): array
    {
        $label = IncidentOrigin::label($record);
        if ($record->source_module === 'tarjetas') {
            return ['label' => $label, 'detected_by' => User::find($record->reported_by)?->name, 'responsible' => User::find($record->user_id)?->name,
                'detected_at' => $record->occurred_at->setTimezone('America/La_Paz')->format('d/m/Y H:i'), 'shift' => null, 'shift_caption' => 'Sesión de Tarjetas',
                'explanation' => $label === 'Detectado en apertura' ? 'Diferencia nueva detectada al recibir Tarjetas, asignada a la custodia anterior.' : 'Faltante registrado al contar y cerrar Tarjetas en la sesión responsable.'];
        }
        $detector = null;
        $moment = $record->occurred_at;
        $shift = null;
        $caption = 'Turno de cierre';
        $explanation = 'Consulta el origen y la observación registrados.';
        if ($label === 'Detectado en apertura') {
            $receipt = DB::table('cash_box_receipt_discrepancies')->where('economic_incident_id', $record->id)->first();
            if ($receipt) {
                $session = CashBoxSession::find($receipt->cash_box_session_id);
                $detector = User::find($receipt->reported_by)?->name;
                $moment = $session?->opened_at ?? $record->occurred_at;
                $previousClose = CashBoxClose::where('cash_box_session_id', $receipt->previous_session_id)->whereNull('cancelled_at')->latest('id')->first();
                $shift = $previousClose?->period;
            }
            $caption = 'Turno del cierre anterior';
            $explanation = 'La diferencia se detectó al abrir la caja recibida y se asignó a la responsable de la entrega anterior.';
        } elseif ($label === 'Cierre de turno') {
            $close = CashBoxClose::where('economic_incident_id', $record->id)->first();
            if ($close) {
                $detector = User::find($close->user_id)?->name;
                $moment = $close->created_at;
                $shift = $close->period;
            }
            $explanation = 'La diferencia se registró al realizar el cierre del turno, no en una apertura posterior.';
        }

        return ['label' => $label, 'detected_by' => $detector, 'responsible' => User::find($record->user_id)?->name,
            'detected_at' => CarbonImmutable::parse($moment, 'UTC')->setTimezone('America/La_Paz')->format('d/m/Y H:i'),
            'shift' => match ($shift) {
                'manana' => 'Mañana','tarde' => 'Tarde',default => null
            }, 'shift_caption' => $caption, 'explanation' => $explanation];
    }
}
