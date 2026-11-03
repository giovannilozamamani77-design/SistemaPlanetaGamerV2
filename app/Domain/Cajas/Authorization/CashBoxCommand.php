<?php

namespace App\Domain\Cajas\Authorization;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class CashBoxCommand
{
    public function execute(User $actor, int $sessionId, bool $ownerOnly, string $kind, #[SensitiveParameter] array $data, Closure $write): int
    {
        Validator::make($data, ['request_key' => ['required', 'uuid']])->validate();
        $fresh = User::findOrFail($actor->id);
        if ($ownerOnly) {
            Gate::forUser($fresh)->authorize('cajas.operar');
        } else {
            app(AdministrativeReview::class)->authorize($fresh, 'cajas.liberar');
        }
        if (array_key_exists('pin', $data)) {
            app(VerifyPersonalPinAction::class)->execute($fresh, $data['pin']);
        }
        $payload = $data;
        unset($payload['pin'],$payload['request_key']);
        $hash = hash('sha256', json_encode([$kind, $sessionId, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($fresh, $sessionId, $ownerOnly, $kind, $data, $hash, $write): int {
            $target = CashBoxSession::findOrFail($sessionId);
            $users = User::whereIn('id', array_unique([$fresh->id, $target->user_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user = $users[$fresh->id];
            CashBox::whereKey($target->cash_box_id)->lockForUpdate()->firstOrFail();
            $session = CashBoxSession::whereKey($sessionId)->lockForUpdate()->firstOrFail();
            if ($ownerOnly) {
                Gate::forUser($user)->authorize('cajas.operar');
                if ($session->user_id !== $user->id) {
                    throw new AuthorizationException('Solo la titular puede operar o registrar el cierre.');
                }
            } else {
                app(AdministrativeReview::class)->authorize($user, 'cajas.liberar');
            }
            if ($prior = DB::table('cash_box_commands')->where('request_key', $data['request_key'])->first()) {
                if ($prior->actor_user_id !== $user->id || $prior->cash_box_session_id !== $sessionId) {
                    throw new AuthorizationException;
                }
                if ($prior->kind !== $kind || $prior->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['reason' => 'La solicitud ya fue utilizada con otros datos. Abre nuevamente el formulario.']);
                }

                return $prior->result_id;
            }
            $id = $write($user, $session);
            DB::table('cash_box_commands')->insert(['cash_box_session_id' => $sessionId, 'actor_user_id' => $user->id, 'kind' => $kind, 'request_key' => $data['request_key'], 'payload_hash' => $hash, 'result_id' => $id, 'created_at' => now()]);

            return $id;
        });
    }
}
