<?php

namespace App\Console\Commands;

use App\Domain\Liquidaciones\Actions\InstallDemoLiquidationAccountAction;
use App\Domain\Liquidaciones\Actions\SetLiquidationRulesAction;
use App\Domain\Liquidaciones\Queries\LiquidationRulesQuery;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallLiquidationDemo extends Command
{
    protected $signature = 'cajas:install-liquidation-demo {--actor= : ID de Administración que autoriza el ejemplo} {--configure-examples : Completar referencias vacías de origen sin cambiar sesiones abiertas}';

    protected $description = 'Instala una cuenta y una imagen de demostración, sin reemplazar destinos reales ni crear liquidaciones.';

    public function handle(InstallDemoLiquidationAccountAction $action): int
    {
        $actor = User::findOrFail((int) $this->option('actor'));
        $id = $action->execute($actor);
        $configured = 0;
        if ($this->option('configure-examples')) {
            foreach (app(LiquidationRulesQuery::class)->forAdmin($actor) as $box) {
                $source = $box['source'];
                if (! $box['configured'] || ! $source || $box['source_reference'] || ! isset($box['options'][$source])) {
                    continue;
                }
                app(SetLiquidationRulesAction::class)->execute($actor, $box['id'], ['source' => $source,
                    'source_reference' => $source === 'yape' ? '76537982' : 'PRUEBA-BCP-'.$box['id'],
                    'morning' => $box['morning'], 'afternoon' => $box['afternoon'], 'expected_revision' => $box['revision'],
                    'request_key' => (string) Str::uuid(), 'reason' => 'Referencia de origen de prueba solicitada por el propietario; solo próximas aperturas.']);
                $configured++;
            }
        }
        $this->info('Referencias de ejemplo completadas: '.$configured);
        $this->info('Ejemplo instalado: cuenta #'.$id.'. No se registraron movimientos financieros.');

        return self::SUCCESS;
    }
}
