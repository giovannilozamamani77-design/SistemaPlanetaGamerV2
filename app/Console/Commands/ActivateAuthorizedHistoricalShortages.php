<?php

namespace App\Console\Commands;

use App\Domain\Incidencias\Actions\ActivateAuthorizedHistoricalShortagesAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class ActivateAuthorizedHistoricalShortages extends Command
{
    protected $signature = 'incidencias:activate-authorized-c101 {--apply : Aplicar exclusivamente IDs1,2 por Bs90 autorizados} {--backup= : Archivo SQLite íntegro bajo storage/app/private/backups/c101}';

    protected $description = 'Regularización puntual C101 autorizada; no cambia saldos ni operaciones de Caja.';

    public function handle(ActivateAuthorizedHistoricalShortagesAction $action): int
    {
        if (! $this->option('apply')) {
            $this->info('C101: solamente faltantes IDs1=Bs10 e IDs2=Bs80. Usa --apply y --backup para la activación autorizada.');

            return self::SUCCESS;
        }
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Este mantenimiento fue validado para la base SQLite local.');

            return self::FAILURE;
        }
        $path = realpath((string) $this->option('backup'));
        $root = realpath(storage_path('app/private/backups/c101'));
        if (! $path || ! $root || ! str_starts_with(strtolower($path), strtolower($root.DIRECTORY_SEPARATOR)) || ! is_file($path)) {
            $this->error('Falta un respaldo C101 válido.');

            return self::FAILURE;
        }
        $backup = new PDO('sqlite:'.$path);
        $backup->exec('PRAGMA query_only=ON');
        if ($backup->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            $this->error('Respaldo SQLite no íntegro.');

            return self::FAILURE;
        }
        $amounts = $backup->query('SELECT id, amount_cents FROM economic_incidents WHERE id IN (1,2) ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        if (array_map('intval', $amounts) !== [1 => 1000, 2 => 8000]) {
            $this->error('El respaldo no corresponde a los dos importes autorizados.');

            return self::FAILURE;
        }
        $changed = $action->execute(hash_file('sha256', $path));
        $this->info('C101: activados '.$changed.' faltantes. Alcance autorizado:2 por Bs90. Sin modificar Caja ni operaciones.');

        return self::SUCCESS;
    }
}
