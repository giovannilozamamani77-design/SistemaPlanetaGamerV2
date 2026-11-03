<?php

namespace App\Domain\Notificaciones\Queries;

use App\Domain\Notificaciones\Models\PanelNotice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PanelInboxQuery
{
    public function execute(User $actor): Builder
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('panel.acceder');
        if (! $user->is_active) {
            throw new AuthorizationException;
        }
        $union = DB::query()->selectRaw('NULL AS id, 0 AS source_id, NULL AS category, NULL AS target, NULL AS title, 1 AS priority, 0 AS amount_cents, NULL AS occurred_at, NULL AS version, NULL AS actor_name, NULL AS box_name, NULL AS source_status')->whereRaw('1=0');
        if ($user->hasRole('administrador')) {
            if ($user->can('controlhorario.revisar')) {
                $q = $this->timeAppeals()->where('h.action', 'MOTIVO_O_REVISION')->whereIn('i.kind', ['RETRASO', 'FALTA_PARCIAL'])
                    ->whereIn('i.status', ['EN_REVISION', 'PENDIENTE_APROBACION'])
                    ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('time_history_events as newer')
                        ->whereColumn('newer.user_id', 'h.user_id')->where('newer.action', 'MOTIVO_O_REVISION')
                        ->whereColumn('newer.details->incident_id', 'i.id')->whereColumn('newer.id', '>', 'h.id'))
                    ->whereNotExists(fn ($response) => $response->selectRaw('1')->from('time_history_events as response')
                        ->whereColumn('response.user_id', 'h.user_id')->whereIn('response.action', ['APELACION_ACEPTAR', 'APELACION_RECHAZAR'])
                        ->whereColumn('response.details->appeal_key', 'h.event_key'));
                $union->unionAll($this->item($q, 'time-appeal-review', 'h.id', 'horario', 'Apelación de tiempo por revisar', '0', 'h.created_at', $this->text('h.id'), 'u.name', 'NULL'));
            }
            if ($user->can('cajas.liberar') && $user->can('cajas.operar')) {
                $q = DB::table('cash_box_closes as c')->join('users as u', 'u.id', '=', 'c.user_id')->join('cash_boxes as b', 'b.id', '=', 'c.cash_box_id')->where('c.workflow_version', 3)->whereNull('c.cancelled_at')->whereNull('c.released_at')->where('c.difference_cents', '<', 0);
                $union->unionAll($this->item($q, 'close-approval', 'c.id', 'cierres', 'Cierre con faltante por revisar', '0', 'c.created_at', 'c.created_at', 'u.name', 'b.name', '-c.difference_cents'));
                $q = DB::table('cash_box_corrections as r')->join('cash_box_sessions as s', 's.id', '=', 'r.cash_box_session_id')->join('users as u', 'u.id', '=', 's.user_id')->join('cash_boxes as b', 'b.id', '=', 's.cash_box_id')->where('r.status', 'PENDIENTE')->where('s.status', 'ABIERTA');
                $union->unionAll($this->item($q, 'correction-review', 'r.id', 'correcciones', 'Corrección de operación por revisar', '0', 'r.created_at', $this->text('r.response_version'), 'u.name', 'b.name'));
            }
            if ($user->can('incidencias.reposiciones.confirmar')) {
                $q = DB::table('recovery_payments as p')->join('economic_incidents as e', 'e.id', '=', 'p.economic_incident_id')->join('users as u', 'u.id', '=', 'e.user_id')->where('p.kind', 'RECUPERACION')->whereNull('p.confirmed_at')->whereNull('p.rejected_at');
                $union->unionAll($this->item($q, 'repayment-review', 'p.id', 'reposiciones', 'Reposición informada: verifica el depósito', '0', 'p.created_at', 'p.created_at', 'u.name', 'e.box_label', 'p.amount_cents'));
                $q = DB::table('global_recoveries as g')->join('users as u', 'u.id', '=', 'g.user_id')->whereNull('g.confirmed_at')->whereNull('g.rejected_at');
                $union->unionAll($this->item($q, 'global-review', 'g.id', 'reposiciones', 'Reposición global por verificar', '0', 'g.created_at', 'g.created_at', 'u.name', 'NULL', 'g.amount_cents'));
            }
            if ($user->can('incidencias.confirmar')) {
                $q = $this->incidents()->whereNotNull('e.receipt_justified_at')->whereNull('e.policy_snapshot->appeal_decision')->whereIn('e.status', ['DETECTADA', 'EN_REVISION', 'CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO']);
                $union->unionAll($this->item($q, 'appeal-review', 'e.id', 'reposiciones', 'Apelación de faltante por revisar', '0', 'e.receipt_justified_at', 'e.receipt_justified_at', 'u.name', 'e.box_label', 'e.amount_cents'));
            }
            if ($user->can('incidencias.ver_todos')) {
                $q = $this->incidents()->where('e.source_module', 'cajas');
                if ($user->can('incidencias.ver_propios') && $user->can('perfil.ver')) {
                    $q->where(fn ($q) => $q->where('e.user_id', '<>', $user->id)->orWhere('e.source_reference', 'not like', 'receipt:%'));
                }
                $union->unionAll($this->item($q, 'shortage-info', 'e.id', 'faltantes', 'Registro de faltante de caja', '1', 'e.occurred_at', 'e.occurred_at', 'u.name', 'e.box_label', 'e.amount_cents'));
            }
        }
        if ($user->can('incidencias.ver_propios') && $user->can('perfil.ver')) {
            $q = DB::table('economic_incidents as e')->leftJoin('users as u', 'u.id', '=', 'e.reported_by')->where('e.user_id', $user->id)->where('e.source_module', 'cajas')->where('e.source_reference', 'like', 'receipt:%');
            $union->unionAll($this->item($q, 'opening-shortage', 'e.id', 'perfil', 'Faltante detectado en una apertura posterior', '1', 'e.occurred_at', 'e.occurred_at', 'u.name', 'e.box_label', 'e.amount_cents'));
            $q = DB::table('recovery_payments as p')->join('economic_incidents as e', 'e.id', '=', 'p.economic_incident_id')->join('users as u', 'u.id', '=', 'e.user_id')->where('e.user_id', $user->id)->where('p.kind', 'RECUPERACION')->where(fn ($q) => $q->whereNotNull('p.confirmed_at')->orWhereNotNull('p.rejected_at'));
            $union->unionAll($this->item($q, 'repayment-response', 'p.id', 'perfil', 'Respuesta sobre tu reposición', '1', 'COALESCE(p.rejected_at,p.confirmed_at)', 'COALESCE(p.rejected_at,p.confirmed_at)', 'u.name', 'e.box_label', 'p.amount_cents', "CASE WHEN p.rejected_at IS NOT NULL THEN 'RECHAZADA' ELSE 'VERIFICADA' END"));
            $q = DB::table('global_recoveries as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.user_id', $user->id)->whereNotNull('g.rejected_at');
            $union->unionAll($this->item($q, 'global-response', 'g.id', 'perfil', 'Administración rechazó tu reposición acumulada', '1', 'g.rejected_at', 'g.rejected_at', 'u.name', 'NULL', 'g.amount_cents', "'RECHAZADA'"));
            $decision = DB::connection()->getQueryGrammar()->wrap('e.policy_snapshot->appeal_decision');
            $reviewed = DB::connection()->getQueryGrammar()->wrap('e.policy_snapshot->appeal_reviewed_at');
            $q = $this->incidents()->where('e.user_id', $user->id)->whereNotNull('e.receipt_justified_at')->whereNotNull('e.policy_snapshot->appeal_decision');
            $union->unionAll($this->item($q, 'appeal-response', 'e.id', 'perfil', 'Respuesta sobre tu apelación', '1', "COALESCE($reviewed,e.receipt_justified_at)", "COALESCE($reviewed,e.receipt_justified_at)", 'u.name', 'e.box_label', 'e.amount_cents', $decision));
        }
        if ($user->can('perfil.ver')) {
            $q = DB::table('commission_payments as cp')->leftJoin('commission_payment_reversals as cr', 'cr.payment_id', '=', 'cp.id')->where('cp.user_id', $user->id);
            $union->unionAll($this->item($q, 'commission-payment', 'cp.id', 'perfil-comisiones', 'Administración registró un pago de tus comisiones', '1', 'cp.created_at', 'cp.id', 'cp.registered_by_name', 'NULL', 'cp.amount_cents', "CASE WHEN cr.id IS NULL THEN 'REGISTRADO' ELSE 'ANULADO' END"));
            $q = DB::table('commission_payment_reversals as cr')->join('commission_payments as cp', 'cp.id', '=', 'cr.payment_id')->where('cp.user_id', $user->id);
            $union->unionAll($this->item($q, 'commission-payment-void', 'cr.id', 'perfil-comisiones', 'Administración anuló un pago de tus comisiones', '1', 'cr.created_at', 'cr.id', 'cr.voided_by_name', 'NULL', 'cr.amount_cents', "'PENDIENTE_RESTABLECIDO'"));
        }
        if ($user->can('controlhorario.ver_propios') && $user->can('perfil.ver')) {
            $q = $this->timeAppeals()->where('h.user_id', $user->id)->whereIn('i.kind', ['RETRASO', 'FALTA_PARCIAL'])
                ->whereIn('h.action', ['APELACION_ACEPTAR', 'APELACION_RECHAZAR']);
            $union->unionAll($this->item($q, 'time-appeal-response', 'h.id', 'perfil-horario', 'Respuesta sobre tu apelación de tiempo', '1', 'h.created_at', $this->text('h.id'), 'u.name', 'NULL', '0', "CASE WHEN h.action = 'APELACION_ACEPTAR' THEN 'ACEPTAR' ELSE 'RECHAZAR' END"));
        }
        if ($user->can('cajas.operar')) {
            $q = DB::table('cash_box_corrections as r')->join('cash_box_sessions as s', 's.id', '=', 'r.cash_box_session_id')->join('users as u', 'u.id', '=', 's.user_id')->join('cash_boxes as b', 'b.id', '=', 's.cash_box_id')->where('s.user_id', $user->id)->whereIn('r.status', ['APROBADA', 'RECHAZADA', 'DEVUELTA'])->whereNotNull('r.decided_at');
            $union->unionAll($this->item($q, 'correction-response', 'r.id', 'correcciones', 'Respuesta sobre tu corrección', "CASE WHEN r.status='DEVUELTA' THEN 0 ELSE 1 END", 'r.decided_at', $this->text('r.response_version'), 'u.name', 'b.name', '0', 'r.status'));
            $q = DB::table('cash_box_closes as c')->join('cash_boxes as b', 'b.id', '=', 'c.cash_box_id')->join('users as u', 'u.id', '=', 'c.user_id')->where('c.user_id', $user->id)->whereNotNull('c.reviewed_by')->whereNotNull('c.closure_approved_at')->whereNull('c.cancelled_at');
            $union->unionAll($this->item($q, 'close-response', 'c.id', 'cierres', 'Administración autorizó tu cierre', '1', 'c.closure_approved_at', 'c.closure_approved_at', 'u.name', 'b.name'));
        }

        return PanelNotice::query()->fromSub($union, 'notice_items')->leftJoin('panel_notice_reads as notice_reads', fn ($join) => $join->on('notice_reads.notice_key', '=', 'notice_items.id')->on('notice_reads.version', '=', 'notice_items.version')->where('notice_reads.user_id', $user->id))->select('notice_items.*', 'notice_reads.read_at');
    }

    private function timeAppeals(): Query
    {
        $incident = DB::connection()->getQueryGrammar()->wrap('h.details->incident_id');

        return DB::table('time_history_events as h')->join('time_incidents as i', 'i.id', '=', DB::raw($incident))
            ->join('users as u', 'u.id', '=', 'i.user_id')->whereColumn('h.user_id', 'i.user_id');
    }

    private function incidents(): Query
    {
        return DB::table('economic_incidents as e')->join('users as u', 'u.id', '=', 'e.user_id');
    }

    private function text(string $value): string
    {
        return 'CAST('.$value.' AS '.(DB::getDriverName() === 'mysql' ? 'CHAR' : 'TEXT').')';
    }

    private function item(Query $q, string $kind, string $id, string $target, string $title, string $priority, string $date, string $version, string $name, string $box, string $amount = '0', string $status = 'NULL'): Query
    {
        $key = DB::getDriverName() === 'mysql' ? "CONCAT('$kind:', $id)" : "'$kind:' || ".$this->text($id);

        return $q->selectRaw("$key AS id, $id AS source_id, ? AS category, ? AS target, ? AS title, $priority AS priority, $amount AS amount_cents, $date AS occurred_at, COALESCE(".$this->text($version).",'') AS version, $name AS actor_name, $box AS box_name, $status AS source_status", [$kind, $target, $title]);
    }
}
