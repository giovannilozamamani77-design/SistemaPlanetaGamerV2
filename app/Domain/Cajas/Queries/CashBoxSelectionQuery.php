<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Models\CashBox;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CashBoxSelectionQuery
{
    public function builder(User $actor): Builder
    {
        Gate::forUser($actor)->authorize('cajas.operar');

        return CashBox::query()->select(['id', 'code', 'name', 'type', 'is_active'])->where('is_active', true)
            ->addSelect(['account_reference' => DB::table('cash_box_accounts')->select('reference')
                ->whereColumn('cash_box_id', 'cash_boxes.id')->where('is_active', true)
                ->whereIn('code', ['bcp', 'tigo_money'])->orderBy('position')->orderBy('id')->limit(1)])
            ->withExists(['sessions as has_own_open_session' => fn (Builder $query) => $query->where('user_id', $actor->id)->whereNotNull('active_slot')->where('status', 'ABIERTA'),
                'sessions as has_open_session' => fn (Builder $query) => $query->whereNotNull('active_slot')->where('status', 'ABIERTA'),
                'sessions as has_own_pending_close' => fn (Builder $query) => $query->where('user_id', $actor->id)->whereNotNull('active_slot')->where('status', 'CIERRE_REGISTRADO'),
                'sessions as has_pending_close' => fn (Builder $query) => $query->whereNotNull('active_slot')->where('status', 'CIERRE_REGISTRADO')]);
    }

    public function groups(Collection $records): array
    {
        $groups = [
            'BCP' => ['title' => 'Agente BCP', 'initials' => 'AB', 'description' => 'Cajas de Agente BCP para apertura y registro de comisiones.', 'cards' => []],
            'TIGO_MONEY' => ['title' => 'Tigo Money', 'initials' => 'TM', 'description' => 'Caja Tigo Money para saldos y registro de comisiones.', 'cards' => []],
        ];
        $catalog = app(CashBoxTypeCatalog::class);
        foreach ($records as $box) {
            $family = $catalog->family($box->type);
            if (! isset($groups[$family])) {
                continue;
            }
            $status = $box->has_open_session ? 'Abierta' : ($box->has_pending_close ? 'No disponible' : 'Cerrada');
            $groups[$family]['cards'][] = ['record' => $box, 'status' => $status, 'state' => $box->has_open_session ? 'open' : ($box->has_pending_close ? 'pending' : 'closed'),
                'description' => $box->has_open_session ? 'Esta caja tiene una sesión en curso.' : ($box->has_pending_close ? 'Esta caja no está disponible para operar. Consulta con Administración.' : 'Disponible para iniciar una nueva sesión.')];
        }

        return $groups;
    }
}
