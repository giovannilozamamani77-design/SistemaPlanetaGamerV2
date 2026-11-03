<?php

namespace App\Domain\Envios\Actions;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Calculators\TransferQuoteCalculator;
use App\Domain\Envios\Models\ExchangePolicy;
use App\Domain\Envios\Models\InternationalTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateInternationalTransferAction
{
    public function execute(User $actor, array $data): InternationalTransfer
    {
        $v = Validator::make($data, [
            'session_id' => ['required', 'integer'], 'access_id' => ['required', 'integer'], 'component' => ['required', 'string'],
            'direction' => ['required', Rule::in(['BO_BR', 'BR_BO'])], 'currency' => ['required', Rule::in(['BOB', 'BRL'])], 'amount' => ['required', 'string'],
            'sender_name' => ['required', 'string', 'min:2', 'max:160'], 'sender_whatsapp' => ['required', 'string', 'regex:/\A\+?[0-9]{8,15}\z/'],
            'recipient_name' => ['required', 'string', 'min:2', 'max:160'], 'pix_key' => ['required_if:direction,BO_BR', 'nullable', 'string', 'max:160'],
            'bank' => ['nullable', 'string', 'max:160'], 'request_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $v): InternationalTransfer {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $target = CashBoxSession::findOrFail($v['session_id']);
            CashBox::whereKey($target->cash_box_id)->lockForUpdate()->firstOrFail();
            $session = CashBoxSession::whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(TransferAuthorization::class)->owner($user, $session, $v['access_id']);
            $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
            if ($prior = InternationalTransfer::where('request_key', $v['request_key'])->first()) {
                if ($prior->requested_by !== $user->id || $prior->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['amount' => 'La solicitud ya se utilizó con otros datos.']);
                }

                return $prior;
            }
            if ($session->status !== 'ABIERTA' || ! array_key_exists($v['component'], $session->current_balances ?? $session->opening_balances)) {
                throw ValidationException::withMessages(['component' => 'Selecciona un saldo de tu caja abierta.']);
            }
            $policy = ExchangePolicy::latest('id')->first();
            if (! $policy?->enabled) {
                throw ValidationException::withMessages(['amount' => 'Administración debe configurar y habilitar las tasas antes de cotizar.']);
            }
            $quote = app(TransferQuoteCalculator::class)->calculate($v['direction'], $v['currency'], $v['amount'], $policy->base_rate, $policy->sell_rate, $policy->buy_rate);
            $quote['cost_source'] = $policy->cost_source;

            return InternationalTransfer::create([
                'cash_box_id' => $session->cash_box_id, 'created_in_session_id' => $session->id, 'requested_by' => $user->id, 'exchange_policy_id' => $policy->id,
                'direction' => $v['direction'], 'status' => 'COTIZADO', 'component' => $v['component'], 'sender_name' => $v['sender_name'], 'sender_whatsapp' => $v['sender_whatsapp'],
                'recipient_name' => $v['recipient_name'], 'pix_key' => $v['pix_key'] ?? null, 'bank' => $v['bank'] ?? null,
                'quote' => $quote, 'bob_cents' => $quote['bob_cents'], 'brl_cents' => $quote['brl_cents'], 'principal_cents' => $quote['principal_cents'], 'margin_cents' => $quote['margin_cents'],
                'expires_at' => now()->addMinutes($policy->valid_minutes), 'request_key' => $v['request_key'], 'payload_hash' => $hash,
            ]);
        });
    }
}
