<?php

namespace App\Console\Commands;

use App\Domain\Configuraciones\Actions\InitializeEmptyInstallationAction;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class InitializeEmptyInstallation extends Command
{
    protected $signature = 'planeta:instalar {--email= : Correo privado del administrador Giovanni}';

    protected $description = 'Inicializa una base vacía con Giovanni y catálogo de Tarjetas, sin movimientos ni claves públicas';

    public function handle(InitializeEmptyInstallationAction $action): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Ejecuta por SSH interactivo: la contraseña se solicita oculta, nunca por argumentos.');

            return self::FAILURE;
        }
        $email = $this->option('email') ?: $this->ask('Correo de acceso para Giovanni');
        $password = $this->secret('Contraseña nueva (mínimo 12 caracteres)');
        if (! $password || $password !== $this->secret('Repite la contraseña')) {
            $this->error('Las contraseñas no coinciden. No se realizó la instalación.');

            return self::FAILURE;
        }
        try {
            $action->execute((string) $email, $password);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }
        $this->info('Instalación completa: Giovanni, 16 cortes, stock y efectivo cero. Configura el PIN desde el perfil.');

        return self::SUCCESS;
    }
}
