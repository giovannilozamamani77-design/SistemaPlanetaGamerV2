<?php

namespace App\Domain\Liquidaciones\Actions;

use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstallDemoLiquidationAccountAction
{
    public function execute(User $actor): int
    {
        app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.gestionar');

        return DB::transaction(function () use ($actor): int {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.gestionar');
            if ($prior = DB::table('cash_box_commission_accounts')->where('is_demo', true)->first()) {
                return $prior->id;
            }
            $path = 'cash-box-commission-qr/'.$actor->id.'/demo-reference.png';
            $image = file_get_contents(public_path('images/liquidaciones/qr-demo.png'));
            Storage::disk('local')->put($path, $image);

            return DB::table('cash_box_commission_accounts')->insertGetId(['holder_name' => 'CUENTA DE EJEMPLO - NO TRANSFERIR', 'destination' => '00000000',
                'qr_path' => $path, 'qr_sha256' => hash('sha256', $image), 'is_demo' => true,
                'reason' => 'Instalación de ejemplo solicitada por el propietario. Sin valor de pago; no reemplaza cuentas reales.',
                'created_by' => $actor->id, 'request_key' => (string) Str::uuid(), 'created_at' => now()]);
        });
    }
}
