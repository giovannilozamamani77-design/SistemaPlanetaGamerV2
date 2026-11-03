<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RequestCashBoxCorrectionAction
{
    public function execute(User $actor, CashBoxOperation $operation, array $data): CashBoxCorrection
    {
        $v = Validator::make($data, ['reason' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\S/u']])->validate();
        $id = app(CashBoxCommand::class)->execute($actor, $operation->cash_box_session_id, true, 'correction_request:'.$operation->id, $data, function (User $user, $session) use ($operation, $v): int {
            if ($session->status !== 'ABIERTA' || $operation->fresh()->status !== 'VIGENTE') {
                throw ValidationException::withMessages(['reason' => 'La operación no admite solicitud en este estado.']);
            }
            if ($operation->fee_type === 'INTERNACIONAL') {
                throw ValidationException::withMessages(['reason' => 'El margen corresponde a un envío confirmado; revisa su expediente.']);
            }
            $request = CashBoxCorrection::where('cash_box_operation_id', $operation->id)->lockForUpdate()->first() ?? new CashBoxCorrection(['cash_box_operation_id' => $operation->id]);
            if ($request->exists && $request->status !== 'DEVUELTA') {
                return $request->id;
            }
            $request->appendHistory($user, $request->exists ? 'COMPLETADA' : 'SOLICITADA', 'PENDIENTE', $v['reason']);
            if (! $request->exists) {
                $request->created_at = now();
            }
            $request->fill(['cash_box_session_id' => $session->id, 'reason' => $v['reason'], 'status' => 'PENDIENTE'])->save();

            return $request->id;
        });

        return CashBoxCorrection::findOrFail($id);
    }
}
