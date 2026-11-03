<?php

namespace App\Filament\Resources\Usuarios\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identidad del rol')->description('Los usuarios reciben automáticamente los permisos de sus roles.')
                ->schema([
                    TextInput::make('name')->label('Identificador del rol')->required()->maxLength(80)
                        ->regex('/\A[a-z][a-z0-9_]*\z/')->disabledOn('edit')->dehydrated()
                        ->helperText('Minúsculas y guion bajo. Los roles existentes conservan su identificador.'),
                ])->columnSpanFull(),
            Section::make('Acceso y permisos')->description('Marca las funciones disponibles. Los bloqueos individuales prevalecen; las operaciones exclusivas de Administración conservan esa condición.')
                ->schema([
                    CheckboxList::make('permissions')->label('Funciones permitidas')->default(['panel.acceder', 'perfil.ver', 'perfil.actualizar'])
                        ->options(fn (): array => self::permissionOptions())
                        ->searchable()->bulkToggleable()->columns(2),
                ])->columnSpanFull(),
        ]);
    }

    /** @return array<string, string> */
    private static function permissionOptions(): array
    {
        $labels = [
            'panel.acceder' => 'Sistema · Ingresar al panel',
            'perfil.ver' => 'Mi perfil · Ver mi información',
            'perfil.actualizar' => 'Mi perfil · Editar datos y PIN propios',
            'usuarios.ver' => 'Usuarios · Ver el equipo',
            'usuarios.crear' => 'Usuarios · Crear cuentas',
            'usuarios.actualizar' => 'Usuarios · Editar cuentas',
            'usuarios.permisos.asignar' => 'Usuarios y roles · Administrar permisos',
            'usuarios.pin.administrar' => 'Usuarios · Restablecer PIN ajeno (solo Administración)',
            'controlhorario.ver_propios' => 'Asistencia · Consultar mi historial',
            'controlhorario.ver_todos' => 'Asistencia · Ver todos los historiales',
            'controlhorario.asistencia.registrar' => 'Asistencia · Registrar llegada con PIN',
            'controlhorario.programar' => 'Asistencia · Programar jornadas',
            'controlhorario.solicitar' => 'Asistencia · Solicitar permisos o revisión',
            'controlhorario.revisar' => 'Asistencia · Revisar solicitudes (solo Administración)',
            'incidencias.ver_propios' => 'Faltantes · Consultar mis incidencias',
            'incidencias.ver_todos' => 'Faltantes · Consultar todas las incidencias',
            'incidencias.reportar' => 'Faltantes · Reportar incidencias',
            'incidencias.declarar_aporte' => 'Reposiciones · Declarar un aporte',
            'incidencias.confirmar' => 'Faltantes · Confirmar incidencias (solo Administración)',
            'incidencias.reposiciones.confirmar' => 'Reposiciones · Recibir y verificar (solo Administración)',
            'configuraciones.personal' => 'Ajustes · Configurar parámetros del historial (solo Administración)',
        ];

        return Permission::where('guard_name', 'web')->orderBy('name')->pluck('name')
            ->mapWithKeys(fn (string $name): array => [$name => $labels[$name] ?? $name])->all();
    }
}
