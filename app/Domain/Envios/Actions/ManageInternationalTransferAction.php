<?php

namespace App\Domain\Envios\Actions;

use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Models\InternationalTransfer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageInternationalTransferAction
{
    public const OWNER_ACTIONS = ['collect', 'handover', 'receive_funds', 'pay_local', 'receive_return', 'refund', 'return_local_funds'];

    public const ADMIN_ACTIONS = ['ack_admin', 'claim', 'confirm_pix', 'receive_brl', 'offer_funds', 'cancel', 'return_funds', 'ack_local_return'];

    public function execute(User $actor, InternationalTransfer $target, string $kind, array $data): InternationalTransfer
    {
        if (! config('envios.cash_box_integration_ready', false)) {
            throw ValidationException::withMessages(['receipt' => 'La integración financiera está pendiente de verificación. No se registró ningún movimiento.']);
        }
        $v = Validator::make([...$data, 'kind' => $kind], [
            'kind' => ['required', Rule::in([...self::OWNER_ACTIONS, ...self::ADMIN_ACTIONS])],
            'session_id' => ['required', 'integer'], 'access_id' => ['nullable', 'integer'], 'confirmed' => ['accepted'],
            'reference' => ['nullable', 'string', 'min:3', 'max:120'], 'receipt_file_path' => ['nullable', 'string', 'max:255'], 'receipt' => ['required', 'string', 'min:3', 'max:2000'],
            'request_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $target, $kind, $v): InternationalTransfer {
            $users = User::whereIn('id', [$actor->id, CashBoxSession::findOrFail($v['session_id'])->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user = $users[$actor->id];
            $box = CashBox::whereKey($target->cash_box_id)->lockForUpdate()->firstOrFail();
            $session = CashBoxSession::whereKey($v['session_id'])->lockForUpdate()->firstOrFail();
            $transfer = InternationalTransfer::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($session->cash_box_id !== $box->id || $transfer->cash_box_id !== $box->id) {
                throw new AuthorizationException;
            }
            $auth = app(TransferAuthorization::class);
            if (in_array($kind, self::OWNER_ACTIONS, true)) {
                $auth->owner($user, $session, (int) ($v['access_id'] ?? 0));
            } else {
                $auth->admin($user);
            }
            if (filled($v['receipt_file_path'] ?? null)) {
                $file = $v['receipt_file_path'];
                $disk = Storage::disk('local');
                if (! str_starts_with($file, 'envios/comprobantes/'.$user->id.'/') || str_contains($file, '..') || ! $disk->exists($file)
                    || ! in_array($disk->mimeType($file), ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) || $disk->size($file) > 5 * 1024 * 1024) {
                    throw ValidationException::withMessages(['receipt_file_path' => 'El comprobante debe ser un archivo privado propio PDF o imagen de hasta 5 MB.']);
                }
            }
            $hash = hash('sha256', json_encode([$transfer->id, $kind, $v], JSON_THROW_ON_ERROR));
            if ($prior = DB::table('international_transfer_events')->where('request_key', $v['request_key'])->first()) {
                if ($prior->actor_user_id !== $user->id || $prior->international_transfer_id !== $transfer->id || $prior->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['receipt' => 'La solicitud ya se utilizó con otros datos. No repitas la entrega.']);
                }

                return $transfer;
            }
            if ($session->status !== 'ABIERTA' || ! $session->active_slot) {
                throw ValidationException::withMessages(['receipt' => 'Ingresa a una sesión abierta de la caja. No se modifican cierres guardados.']);
            }
            $previous = $transfer->status;
            $balances = $session->current_balances ?? $session->opening_balances;
            if (! array_key_exists($transfer->component, $balances)) {
                throw ValidationException::withMessages(['receipt' => 'La cuenta de esta solicitud no está activa en la sesión actual.']);
            }
            $cashDelta = 0;
            $heldDelta = 0;
            $earn = false;
            $scope = null;
            switch ($kind) {
                case 'collect':
                    $this->state($transfer, ['COTIZADO'], 'BO_BR');
                    $this->validQuote($transfer);
                    $cashDelta = $heldDelta = $transfer->bob_cents;
                    $transfer->status = 'COBRADO';
                    break;
                case 'handover':
                    $this->state($transfer, ['COBRADO'], 'BO_BR');
                    $cashDelta = $heldDelta = -$transfer->principal_cents;
                    $transfer->admin_principal_cents = $transfer->principal_cents;
                    $transfer->status = 'ENTREGA_PENDIENTE';
                    break;
                case 'ack_admin':
                    $this->state($transfer, ['ENTREGA_PENDIENTE'], 'BO_BR');
                    $transfer->status = 'PRINCIPAL_RECIBIDO';
                    break;
                case 'claim':
                    $this->state($transfer, ['PRINCIPAL_RECIBIDO'], 'BO_BR');
                    $transfer->processing_by = $user->id;
                    $transfer->status = 'EN_PROCESO';
                    break;
                case 'confirm_pix':
                    $this->state($transfer, ['EN_PROCESO'], 'BO_BR');
                    if ($transfer->processing_by !== $user->id) {
                        throw new AuthorizationException('Solo Administración que tomó el envío puede confirmar su ejecución.');
                    }
                    $scope = 'PIX_SALIDA';
                    $earn = true;
                    $transfer->admin_principal_cents = 0;
                    $transfer->status = 'COMPLETADO';
                    break;
                case 'receive_brl':
                    $this->state($transfer, ['COTIZADO'], 'BR_BO');
                    $this->validQuote($transfer);
                    $scope = 'PIX_ENTRADA';
                    $transfer->status = 'BRL_RECIBIDO';
                    break;
                case 'offer_funds':
                    $this->state($transfer, ['BRL_RECIBIDO'], 'BR_BO');
                    $transfer->status = 'APORTE_PENDIENTE';
                    break;
                case 'receive_funds':
                    $this->state($transfer, ['APORTE_PENDIENTE'], 'BR_BO');
                    $cashDelta = $heldDelta = $transfer->principal_cents;
                    $transfer->status = 'LISTO_PARA_PAGAR';
                    break;
                case 'pay_local':
                    $this->state($transfer, ['LISTO_PARA_PAGAR'], 'BR_BO');
                    $cashDelta = $heldDelta = -$transfer->bob_cents;
                    $earn = true;
                    $transfer->status = 'COMPLETADO';
                    break;
                case 'cancel':
                    $this->state($transfer, ['COTIZADO', 'COBRADO', 'PRINCIPAL_RECIBIDO', 'EN_PROCESO', 'BRL_RECIBIDO']);
                    if ($previous === 'EN_PROCESO' && $transfer->processing_by !== $user->id) {
                        throw new AuthorizationException('El envío está en proceso por otro administrador.');
                    }
                    if ($transfer->direction === 'BR_BO' && $previous !== 'COTIZADO') {
                        $scope = 'DEVOLUCION_BRL';
                    }
                    $transfer->status = $transfer->admin_principal_cents > 0 ? 'DEVOLUCION_ADMIN_PENDIENTE' : ($transfer->local_held_cents > 0 ? 'REEMBOLSO_PENDIENTE' : 'CANCELADO');
                    break;
                case 'return_funds':
                    $this->state($transfer, ['DEVOLUCION_ADMIN_PENDIENTE'], 'BO_BR');
                    $transfer->status = 'DEVOLUCION_EN_CAMINO';
                    break;
                case 'receive_return':
                    $this->state($transfer, ['DEVOLUCION_EN_CAMINO'], 'BO_BR');
                    $cashDelta = $heldDelta = $transfer->admin_principal_cents;
                    $transfer->admin_principal_cents = 0;
                    $transfer->status = 'REEMBOLSO_PENDIENTE';
                    break;
                case 'refund':
                    $this->state($transfer, ['REEMBOLSO_PENDIENTE'], 'BO_BR');
                    $cashDelta = $heldDelta = -$transfer->bob_cents;
                    $transfer->status = 'CANCELADO';
                    break;
                case 'return_local_funds':
                    $this->state($transfer, ['LISTO_PARA_PAGAR'], 'BR_BO');
                    $cashDelta = $heldDelta = -$transfer->principal_cents;
                    $transfer->status = 'DEVOLUCION_LOCAL_PENDIENTE';
                    break;
                case 'ack_local_return':
                    $this->state($transfer, ['DEVOLUCION_LOCAL_PENDIENTE'], 'BR_BO');
                    $transfer->status = 'BRL_RECIBIDO';
                    break;
            }
            if ($scope) {
                if (! filled($v['reference'] ?? null)) {
                    throw ValidationException::withMessages(['reference' => 'Registra la referencia bancaria real y la constancia de ejecución.']);
                }
                if (DB::table('international_transfer_events')->where('reference_scope', $scope)->where('reference', $v['reference'])->exists()) {
                    throw ValidationException::withMessages(['reference' => 'Esta transferencia bancaria ya fue registrada. No la vuelvas a ejecutar.']);
                }
            }
            $newHeld = $transfer->local_held_cents + $heldDelta;
            if ($newHeld < 0 || $balances[$transfer->component] + $cashDelta < 0) {
                throw ValidationException::withMessages(['receipt' => 'No hay fondos suficientes para esta entrega. No uses el capital de la caja.']);
            }
            $transfer->local_held_cents = $newHeld;
            if ($earn) {
                if ($transfer->commission_operation_id || $newHeld !== $transfer->margin_cents) {
                    throw ValidationException::withMessages(['receipt' => 'El margen ya se reconoció o los fondos no coinciden.']);
                }
                $operation = CashBoxOperation::create([
                    'cash_box_session_id' => $session->id, 'user_id' => $session->user_id, 'source' => null, 'destination' => null,
                    'amount_cents' => 0, 'fee_type' => 'INTERNACIONAL', 'bill_count' => 0, 'suggested_fee_cents' => $transfer->margin_cents,
                    'fee_cents' => $transfer->margin_cents, 'fee_component' => $transfer->component, 'commission_only' => true,
                    'deltas' => array_fill_keys(array_keys($balances), 0), 'reason' => 'Margen de envío Brasil #'.$transfer->id, 'status' => 'VIGENTE', 'created_at' => now(),
                ]);
                $transfer->commission_operation_id = $operation->id;
                $transfer->local_held_cents = 0;
                $transfer->completed_at = now();
            }
            $balances[$transfer->component] += $cashDelta;
            $transfer->save();
            $reserved = app(ReservedCashBoxFunds::class)->forBox($box->id);
            if ($balances[$transfer->component] < (int) ($reserved[$transfer->component] ?? 0)) {
                throw ValidationException::withMessages(['receipt' => 'El movimiento consumiría fondos reservados de otro envío.']);
            }
            $session->update(['current_balances' => $balances, 'revision' => $session->revision + 1]);
            DB::table('international_transfer_events')->insert([
                'international_transfer_id' => $transfer->id, 'cash_box_session_id' => $session->id, 'actor_user_id' => $user->id,
                'kind' => $kind, 'previous_status' => $previous, 'next_status' => $transfer->status,
                'cash_delta_cents' => $cashDelta, 'held_delta_cents' => $transfer->local_held_cents - ($newHeld - $heldDelta),
                'reference' => $scope ? $v['reference'] : null, 'reference_scope' => $scope, 'receipt' => $v['receipt'], 'receipt_file_path' => $v['receipt_file_path'] ?? null,
                'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now(),
            ]);

            return $transfer;
        });
    }

    private function state(InternationalTransfer $transfer, array $allowed, ?string $direction = null): void
    {
        if (! in_array($transfer->status, $allowed, true) || ($direction && $direction !== $transfer->direction)) {
            throw ValidationException::withMessages(['receipt' => 'El estado cambió o esta acción no corresponde al envío. Actualiza la lista.']);
        }
    }

    private function validQuote(InternationalTransfer $transfer): void
    {
        if (now()->greaterThan($transfer->expires_at)) {
            throw ValidationException::withMessages(['receipt' => 'La cotización venció antes de recibir los fondos. Genera una nueva con el cliente.']);
        }
    }
}
