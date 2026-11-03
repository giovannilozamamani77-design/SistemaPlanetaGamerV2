<?php

namespace App\Domain\Usuarios\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class VerifyPersonalPinAction
{
    public function execute(User $actor, #[SensitiveParameter] string $pin): void
    {
        $this->verifyAndReturnAudit($actor, $pin);
    }

    public function verifyAndReturnAudit(User $actor, #[SensitiveParameter] string $pin): int
    {
        $key = 'usuarios:pin:user:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['pin' => 'Demasiados intentos. Intenta nuevamente en '.RateLimiter::availableIn($key).' segundos.']);
        }
        $valid = DB::transaction(function () use ($actor, $pin): ?int {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('panel.acceder');
            if (! $user->pin_hash || ! preg_match('/\A[0-9]{4}\z/', $pin) || ! Hash::check($pin, $user->pin_hash)) {
                return null;
            }
            $auditId = DB::table('user_audits')->insertGetId([
                'actor_user_id' => $user->id, 'subject_user_id' => $user->id, 'action' => 'pin_verified',
                'changes' => '{}', 'created_at' => now(),
            ]);

            return $auditId;
        });
        if (! $valid) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['pin' => 'PIN incorrecto o no configurado.']);
        }
        RateLimiter::clear($key);

        return $valid;
    }
}
