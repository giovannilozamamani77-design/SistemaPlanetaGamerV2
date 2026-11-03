<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Models\AccessoryCategory;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateAccessoryCategoryAction
{
    public function execute(User $actor, array $data): AccessoryCategory
    {
        app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
        $v = Validator::make($data, ['name' => ['required', 'string', 'max:100'], 'tracking' => ['required', Rule::in(['QUANTITY', 'IMEI'])], 'request_key' => ['required', 'uuid']])->validate();
        $name = preg_replace('/\s+/u', ' ', trim($v['name']));
        $key = Str::lower(Str::ascii($name));
        if ($key === '') {
            throw ValidationException::withMessages(['name' => 'Ingresa el nombre de la categoría.']);
        }$values = ['name' => $name, 'normalized_name' => $key, 'tracking' => $v['tracking']];
        $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $values, $hash): AccessoryCategory {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
            if ($old = AccessoryCategory::where('request_key', $v['request_key'])->first()) {
                if ($old->created_by !== $u->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['name' => 'Este envío ya se registró con otros datos.']);
                }

                return $old;
            }
            if ($old = AccessoryCategory::where('normalized_name', $values['normalized_name'])->first()) {
                if ($old->tracking !== $values['tracking']) {
                    throw ValidationException::withMessages(['name' => 'Esa categoría ya usa otro tipo de control.']);
                }

                return $old;
            }

            return AccessoryCategory::create($values + ['is_active' => true, 'created_by' => $u->id, 'actor_name' => $u->name, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
        }, 3);
    }
}
