<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InitializeCashBoxCounterAction
{
    public function execute(User $actor, CashBox $box, #[\SensitiveParameter] array $data): int
    {
        app(AdministrativeReview::class)->authorize($actor, 'cajas.liberar');
        $v = Validator::make($data, ['reading' => ['required', 'integer', 'min:0', 'max:999999999'], 'reason' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\S/u'], 'pin' => ['required', 'string']])->validate();
        if ($box->family() !== 'BCP') {
            throw ValidationException::withMessages(['reading' => 'Este contador solo aplica a BCP.']);
        }
        app(VerifyPersonalPinAction::class)->execute($actor, $v['pin']);

        return DB::transaction(function () use ($box, $actor, $v): int {
            CashBox::whereKey($box->id)->lockForUpdate()->firstOrFail();
            app(AdministrativeReview::class)->authorize($actor, 'cajas.liberar');
            $month = now('America/La_Paz')->format('Y-m');
            if ($old = DB::table('cash_box_counter_readings')->whereNull('voided_at')->where('cash_box_id', $box->id)->where('month', $month)->first()) {
                if ($old->baseline && $old->reading === (int) $v['reading']) {
                    return $old->id;
                }
                throw ValidationException::withMessages(['reading' => 'El mes ya tiene referencia o lecturas; no se reinicia desde este formulario.']);
            }

            return DB::table('cash_box_counter_readings')->insertGetId(['cash_box_id' => $box->id, 'user_id' => $actor->id, 'month' => $month, 'reading' => $v['reading'], 'delta' => 0, 'baseline' => true, 'reason' => $v['reason'], 'created_at' => now()]);
        });
    }
}
