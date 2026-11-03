<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'cajera')->where('guard_name', 'web')->value('id');
        $permission = DB::table('permissions')->where('name', 'tarjetas.efectivo.aportar')->where('guard_name', 'web')->value('id');
        if ($role && $permission) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only grant: removing it could revoke a pre-existing explicit assignment.
    }
};
