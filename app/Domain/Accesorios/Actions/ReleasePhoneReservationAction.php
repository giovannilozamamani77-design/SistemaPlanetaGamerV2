<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ReleasePhoneReservationAction
{
    public function execute(User $actor, string $cartKey, ?int $unitId = null): void
    {
        app(AccessorySaleQuery::class)->authorize($actor);
        DB::transaction(function () use ($actor, $cartKey, $unitId): void {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessorySaleQuery::class)->authorize($actor);
            $ids = PhoneReservation::where('cart_key', $cartKey)->where('status', 'ACTIVE')->when($unitId, fn ($q) => $q->where('unit_id', $unitId))->orderBy('unit_id')->pluck('unit_id');
            foreach ($ids as $id) {
                $p = PhoneUnit::whereKey($id)->lockForUpdate()->firstOrFail();
                $r = PhoneReservation::where('unit_id', $id)->where('cart_key', $cartKey)->where('status', 'ACTIVE')->lockForUpdate()->first();
                if (! $r) {
                    continue;
                }if ($r->user_id !== $u->id) {
                    throw new AuthorizationException;
                }
                $r->update(['status' => $r->expires_at->isFuture() ? 'RELEASED' : 'EXPIRED', 'ended_at' => now()]);
                if ($p->reservation_id === $r->id && $p->status === 'RESERVED') {
                    $p->update(['status' => 'AVAILABLE', 'reservation_id' => null]);
                }
            }
        }, 3);
    }
}
