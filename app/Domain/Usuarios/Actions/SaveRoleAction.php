<?php

namespace App\Domain\Usuarios\Actions;

use App\Domain\Usuarios\Authorization\EnsureUsableAdministrator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SaveRoleAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, array $data, ?Role $target = null): Role
    {
        Gate::forUser($actor)->authorize($target ? 'update' : 'create', $target ?? Role::class);
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9_]*\z/', Rule::unique('roles')->where('guard_name', 'web')->ignore($target?->id)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ])->validate();

        try {
            return DB::transaction(function () use ($actor, $target, $validated): Role {
                Role::where('guard_name', 'web')->orderBy('id')->lockForUpdate()->get();
                $role = $target ? Role::whereKey($target->id)->lockForUpdate()->firstOrFail() : new Role;
                if ($role->exists && $role->name !== $validated['name']) {
                    throw ValidationException::withMessages(['name' => 'El identificador de un rol existente no se puede cambiar.']);
                }
                $before = $role->exists ? $this->snapshot($role) : null;
                $role->name = $validated['name'];
                $role->guard_name = 'web';
                $role->save();
                $role->syncPermissions($validated['permissions']);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                app(EnsureUsableAdministrator::class)->execute('permissions');
                $after = $this->snapshot($role);
                if ($before !== $after) {
                    DB::table('role_audits')->insert([
                        'actor_user_id' => $actor->id, 'role_id' => $role->id,
                        'changes' => json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }

                return $role;
            });
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $actor->refresh();
            $target?->refresh();
        }
    }

    /** @return array{name: string, permissions: array<string>} */
    private function snapshot(Role $role): array
    {
        return ['name' => $role->name, 'permissions' => $role->permissions()->orderBy('name')->pluck('name')->all()];
    }
}
