<?php

namespace App\Domain\Envios\Queries;

use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Envios\Actions\ManageInternationalTransferAction;
use App\Domain\Envios\Models\InternationalTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InternationalTransferQuery
{
    public static function admin(User $user): bool
    {
        return config('envios.enabled', false) && $user->hasRole('administrador') && $user->can('envios.administrar');
    }

    public function builder(User $actor): Builder
    {
        $user = User::findOrFail($actor->id);
        abort_unless(config('envios.enabled', false) && $user->is_active, 403);
        Gate::forUser($user)->authorize('envios.ver');
        $query = InternationalTransfer::query()->with('box:id,name')
            ->leftJoin('cash_box_sessions as active_session', fn ($join) => $join->on('active_session.cash_box_id', '=', 'international_transfers.cash_box_id')->whereNotNull('active_session.active_slot')->where('active_session.status', 'ABIERTA'))
            ->select('international_transfers.*', 'active_session.id as active_session_id', 'active_session.user_id as active_owner_id');
        if (! self::admin($user)) {
            $query->where(fn ($q) => $q->where('international_transfers.requested_by', $user->id)->orWhere('active_session.user_id', $user->id));
        }

        return $query;
    }

    public function context(User $user, InternationalTransfer $transfer): array
    {
        $session = CashBoxSession::where('cash_box_id', $transfer->cash_box_id)->whereNotNull('active_slot')->where('status', 'ABIERTA')->first();

        return ['session_id' => $session?->id,
            'access_id' => $session ? CashBoxAccess::where('user_id', $user->id)->where('cash_box_session_id', $session->id)->latest('id')->value('id') : null];
    }

    public function available(User $user, InternationalTransfer $transfer, string $kind): bool
    {
        if (! config('envios.enabled', false) || ! config('envios.cash_box_integration_ready') || ! $transfer->active_session_id) {
            return false;
        }
        if (in_array($kind, ManageInternationalTransferAction::OWNER_ACTIONS, true)) {
            if (! $user->can('envios.registrar') || $transfer->active_owner_id !== $user->id) {
                return false;
            }
        } elseif (! self::admin($user)) {
            return false;
        }

        return match ($kind) {
            'collect' => $transfer->direction === 'BO_BR' && $transfer->status === 'COTIZADO',
            'handover' => $transfer->status === 'COBRADO',
            'ack_admin' => $transfer->status === 'ENTREGA_PENDIENTE',
            'claim' => $transfer->status === 'PRINCIPAL_RECIBIDO',
            'confirm_pix' => $transfer->status === 'EN_PROCESO' && $transfer->processing_by === $user->id,
            'receive_brl' => $transfer->direction === 'BR_BO' && $transfer->status === 'COTIZADO',
            'offer_funds' => $transfer->status === 'BRL_RECIBIDO',
            'receive_funds' => $transfer->status === 'APORTE_PENDIENTE',
            'pay_local', 'return_local_funds' => $transfer->status === 'LISTO_PARA_PAGAR',
            'cancel' => in_array($transfer->status, ['COTIZADO', 'COBRADO', 'PRINCIPAL_RECIBIDO', 'EN_PROCESO', 'BRL_RECIBIDO'], true) && ($transfer->status !== 'EN_PROCESO' || $transfer->processing_by === $user->id),
            'return_funds' => $transfer->status === 'DEVOLUCION_ADMIN_PENDIENTE',
            'receive_return' => $transfer->status === 'DEVOLUCION_EN_CAMINO',
            'refund' => $transfer->status === 'REEMBOLSO_PENDIENTE',
            'ack_local_return' => $transfer->status === 'DEVOLUCION_LOCAL_PENDIENTE',
            default => false,
        };
    }

    public function events(int $transferId): Collection
    {
        return DB::table('international_transfer_events')->join('users', 'users.id', '=', 'international_transfer_events.actor_user_id')
            ->where('international_transfer_id', $transferId)->orderBy('international_transfer_events.id')->get(['international_transfer_events.*', 'users.name as actor_name']);
    }
}
