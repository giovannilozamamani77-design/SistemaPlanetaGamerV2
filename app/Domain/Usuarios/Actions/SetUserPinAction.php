<?php

namespace App\Domain\Usuarios\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use SensitiveParameter;

class SetUserPinAction
{
    public function execute(User $actor, User $target, #[SensitiveParameter] string $pin): User
    {
        return $this->save($actor, $target, $pin, own: false);
    }

    public function executeOwn(User $actor, #[SensitiveParameter] string $pin): User
    {
        return $this->save($actor, $actor, $pin, own: true);
    }

    private function save(User $actor, User $target, #[SensitiveParameter] string $pin, bool $own): User
    {
        $saved = DB::transaction(function () use ($actor, $target, $pin, $own): User {
            $actingUser = User::whereKey($actor->id)->firstOrFail();
            if ($own) {
                Gate::forUser($actingUser)->authorize('perfil.ver');
                Gate::forUser($actingUser)->authorize('perfil.actualizar');
            } else {
                if (! $actingUser->hasRole('administrador')) {
                    throw new AuthorizationException('Solo Administración puede configurar el PIN de otros usuarios.');
                }
                Gate::forUser($actingUser)->authorize('usuarios.pin.administrar');
            }
            Validator::make(['pin' => $pin], ['pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/']])->validate();
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($user->pin_hash && Hash::check($pin, $user->pin_hash)) {
                return $user;
            }
            $wasConfigured = $user->pin_hash !== null;
            $user->pin_hash = Hash::make($pin);
            $user->pin_changed_at = now();
            $user->save();
            DB::table('user_audits')->insert([
                'actor_user_id' => $actingUser->id, 'subject_user_id' => $user->id, 'action' => 'pin_updated',
                'changes' => json_encode(['previously_configured' => $wasConfigured, 'pin_changed' => true], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $user;
        });
        $target->refresh();

        return $saved;
    }
}
