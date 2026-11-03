<?php

namespace App\Domain\Usuarios\Commands;

use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class InitializeAdministrator extends Command
{
    protected $signature = 'usuarios:inicializar-admin {email : Correo del usuario existente}';

    protected $description = 'Asigna el primer administrador activo, sin crear cuentas ni contraseñas';

    public function handle(): int
    {
        app(UsuariosPermissionsSeeder::class)->run();
        $result = DB::transaction(function (): bool {
            Role::findByName('administrador', 'web')->newQuery()->where('name', 'administrador')->lockForUpdate()->firstOrFail();
            $user = User::where('email', $this->argument('email'))->lockForUpdate()->first();
            if (! $user || ! $user->is_active) {
                return false;
            }
            if (User::role('administrador')->where('is_active', true)->exists()) {
                return $user->hasRole('administrador') && $user->can('panel.acceder');
            }
            $user->syncRoles(['administrador']);

            return true;
        });
        if (! $result) {
            $this->error('Usuario inexistente/inactivo o ya existe otro administrador activo.');

            return self::FAILURE;
        }
        $this->info('Administrador inicial disponible.');

        return self::SUCCESS;
    }
}
