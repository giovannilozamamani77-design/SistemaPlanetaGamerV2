<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReceiptObservationsQuery
{
    public function execute(User $actor, int $boxId): Collection
    {
        app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.ver');

        return DB::table('cash_box_receipt_discrepancies as receipt')
            ->join('cash_box_sessions as session', 'session.id', '=', 'receipt.cash_box_session_id')
            ->where('session.cash_box_id', $boxId)->orderByDesc('receipt.id')->limit(5)
            ->get(['receipt.id', 'receipt.cash_box_session_id', 'receipt.difference_cents', 'receipt.reason', 'receipt.status', 'receipt.created_at']);
    }
}
