<?php

namespace App\Domain\Usuarios\Actions;

use App\Domain\Usuarios\Authorization\EnsureUsableAdministrator;
use App\Domain\Usuarios\Support\Username;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SaveUserAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, #[SensitiveParameter] array $data, ?User $target = null): User
    {
        Gate::forUser($actor)->authorize($target ? 'update' : 'create', $target ?? User::class);
        Gate::forUser($actor)->authorize('usuarios.permisos.asignar');
        if (is_string($data['roles'] ?? null)) {
            $data['roles'] = [$data['roles']];
        }
        $candidate = $data['username'] ?? null;
        $data['username'] = blank($candidate)
            ? ($target?->username ?? Username::availableFromName(is_string($data['name'] ?? null) ? $data['name'] : ''))
            : (is_string($candidate) ? trim($candidate) : $candidate);
        $validated = Validator::make($data, [
            'username' => ['bail', 'required', 'string', 'max:64', 'regex:/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/', function (string $attribute, mixed $value, \Closure $fail) use ($target): void {
                if (User::where('username_key', Username::key($value))->when($target, fn ($query) => $query->where('id', '!=', $target->id))->exists()) {
                    $fail('Este nombre de usuario ya existe. Asigna uno diferente.');
                }
            }],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($target?->id)],
            'password' => [$target ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
            'pin' => ['nullable', 'string', 'regex:/\A[0-9]{4}\z/', 'confirmed'],
            'salary_pay_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'], 'is_active' => ['required', 'boolean'],
            'roles' => ['required', 'array', 'size:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
            'restrictions' => ['present', 'array'],
            'restrictions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ])->validate();
        $saved = DB::transaction(function () use ($actor, $target, $validated): User {
            Role::where('guard_name', 'web')->orderBy('id')->lockForUpdate()->get();
            $user = $target ? User::whereKey($target->id)->lockForUpdate()->firstOrFail() : new User;
            $before = $user->exists ? $this->snapshot($user) : null;
            $user->fill(['name' => $validated['name'], 'email' => $validated['email'], 'username' => $validated['username']]);
            if (array_key_exists('salary_pay_day', $validated)) {
                $user->salary_pay_day = $validated['salary_pay_day'];
            }
            $user->is_active = $validated['is_active'];
            if (! empty($validated['password'])) {
                $user->password = $validated['password'];
            }
            try {
                $user->save();
            } catch (UniqueConstraintViolationException $error) {
                if (str_contains($error->getMessage(), 'username_key')) {
                    throw ValidationException::withMessages(['data.username' => 'Este nombre de usuario ya existe. Asigna uno diferente.']);
                }
                throw $error;
            }
            $user->syncRoles($validated['roles']);
            $user->syncPermissions($validated['permissions']);
            $ids = Permission::where('guard_name', 'web')->whereIn('name', $validated['restrictions'])->pluck('id');
            $user->restrictedPermissions()->sync($ids);
            $user->unsetRelation('restrictedPermissions');
            app(EnsureUsableAdministrator::class)->execute();
            $after = $this->snapshot($user);
            $passwordChanged = ! empty($validated['password']);
            if ($before !== $after || $passwordChanged) {
                DB::table('user_audits')->insert([
                    'actor_user_id' => $actor->id, 'subject_user_id' => $user->id,
                    'action' => $before === null ? 'created' : 'updated',
                    'changes' => json_encode(['before' => $before, 'after' => $after, 'password_changed' => $passwordChanged], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }

            if (filled($validated['pin'] ?? null)) {
                app(SetUserPinAction::class)->execute($actor, $user, $validated['pin']);
            }

            return $user;
        });
        $target?->refresh();
        if ($actor->id === $saved->id) {
            $actor->refresh();
        }

        return $saved;
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name, 'email' => $user->email, 'username' => $user->username, 'is_active' => $user->is_active,
            'salary_pay_day' => $user->salary_pay_day,
            'roles' => $user->roles()->orderBy('name')->pluck('name')->all(),
            'permissions' => $user->permissions()->orderBy('name')->pluck('name')->all(),
            'restrictions' => $user->restrictedPermissions()->orderBy('name')->pluck('name')->all(),
        ];
    }
}
