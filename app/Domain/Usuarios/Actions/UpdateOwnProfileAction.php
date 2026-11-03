<?php

namespace App\Domain\Usuarios\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class UpdateOwnProfileAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, #[SensitiveParameter] array $data): User
    {
        $saved = DB::transaction(function () use ($actor, $data): User {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('perfil.ver');
            Gate::forUser($user)->authorize('perfil.actualizar');
            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
                'password' => ['nullable', 'string', 'min:8', 'max:255'],
                'currentPassword' => ['nullable', 'string'],
            ])->validate();
            $passwordChanged = ! empty($validated['password']);
            $emailChanged = $validated['email'] !== $user->email;
            if (($passwordChanged || $emailChanged) && ! Hash::check($validated['currentPassword'] ?? '', $user->password)) {
                throw ValidationException::withMessages(['data.currentPassword' => 'La contraseña actual no es correcta.']);
            }
            $before = ['name' => $user->name, 'email' => $user->email];
            $user->fill(['name' => $validated['name'], 'email' => $validated['email']]);
            if ($emailChanged) {
                $user->email_verified_at = null;
            }
            if ($passwordChanged) {
                $user->password = $validated['password'];
            }
            $after = ['name' => $user->name, 'email' => $user->email];
            if ($before !== $after || $passwordChanged) {
                $user->save();
                DB::table('user_audits')->insert([
                    'actor_user_id' => $user->id, 'subject_user_id' => $user->id,
                    'action' => 'profile_updated',
                    'changes' => json_encode(['before' => $before, 'after' => $after, 'password_changed' => $passwordChanged], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }

            return $user;
        });
        $actor->refresh();

        return $saved;
    }
}
