<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReservePhoneAction
{
    public const MINUTES = 5;

    public function execute(User $actor, int $unitId, string $cartKey, int $minutes = self::MINUTES): PhoneReservation
    {
        app(AccessorySaleQuery::class)->authorize($actor);
        app(AccessorySaleQuery::class)->authorize($actor, 'viewAny');
        Validator::make(['cart_key' => $cartKey, 'minutes' => $minutes], ['cart_key' => ['required', 'uuid'], 'minutes' => ['required', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($actor, $unitId, $cartKey, $minutes): PhoneReservation {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessorySaleQuery::class)->authorize($actor);
            app(AccessorySaleQuery::class)->authorize($u, 'viewAny');
            $p = PhoneUnit::whereKey($unitId)->lockForUpdate()->firstOrFail();
            if ($p->status === 'RESERVED') {
                $old = PhoneReservation::whereKey($p->reservation_id)->lockForUpdate()->firstOrFail();
                if ($old->status === 'ACTIVE' && $old->expires_at->isFuture()) {
                    if ($old->user_id === $u->id && hash_equals($old->cart_key, $cartKey)) {
                        return $old;
                    }
                    throw ValidationException::withMessages(['cart' => 'Ese equipo está reservado en otra venta. Elige otro IMEI.']);
                }
                if ($old->status === 'ACTIVE') {
                    $old->update(['status' => 'EXPIRED', 'ended_at' => now()]);
                }
            } elseif ($p->status !== 'AVAILABLE') {
                throw ValidationException::withMessages(['cart' => 'Ese equipo ya no está disponible.']);
            }
            if ($p->sale_price_cents < $p->historical_cost_cents) {
                throw ValidationException::withMessages(['cart' => 'El precio no cubre el costo del equipo. Contacta con Administración.']);
            }
            $r = PhoneReservation::create(['unit_id' => $p->id, 'user_id' => $u->id, 'actor_name' => $u->name, 'cart_key' => $cartKey, 'status' => 'ACTIVE', 'price_cents' => $p->sale_price_cents, 'expires_at' => now()->addMinutes($minutes), 'created_at' => now()]);
            $p->update(['status' => 'RESERVED', 'reservation_id' => $r->id]);

            return $r;
        }, 3);
    }
}
