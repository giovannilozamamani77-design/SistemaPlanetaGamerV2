<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Calculators\CardAverageCostCalculator;
use App\Domain\Tarjetas\Calculators\EntelFundingCalculator;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelDigitalMovement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordEntelDigitalFundingAction
{
    public function __construct(private EntelFundingCalculator $calculator) {}

    public function execute(User $actor, array $data): EntelDigitalMovement
    {
        Gate::forUser($actor->fresh())->authorize('record', EntelDigitalAccount::class);
        $v = Validator::make($data, [
            'kind' => ['required', Rule::in(array_keys(EntelDigitalAccount::KINDS))],
            'operation' => ['required', Rule::in(['INITIAL', 'TOP_UP'])],
            'credited_amount' => ['required'],
            'paid_amount' => ['nullable'],
            'reference' => ['nullable', 'string', 'max:200'],
            'request_key' => ['required', 'uuid'],
        ])->validate();
        $initial = $v['operation'] === 'INITIAL';
        $credited = CardInventoryInput::money($v['credited_amount'], 'credited_amount', $initial);
        if ($initial && filled($v['paid_amount'] ?? null)) {
            throw ValidationException::withMessages(['paid_amount' => 'El saldo inicial es lo que ya hay disponible; no registra una compra nueva.']);
        }
        $paid = $initial ? null : CardInventoryInput::money($v['paid_amount'] ?? '', 'paid_amount');
        $bonus = $initial ? ['bonus_cents' => null, 'bonus_rate_bps' => null] : $this->calculator->funding($paid, $credited);
        $payload = ['kind' => $v['kind'], 'operation' => $v['operation'], 'credited_cents' => $credited, 'paid_cents' => $paid, 'reference' => trim($v['reference'] ?? '')];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $initial, $credited, $paid, $bonus, $payload, $hash): EntelDigitalMovement {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('record', EntelDigitalAccount::class);
            if ($old = EntelDigitalMovement::where('request_key', $v['request_key'])->first()) {
                if ($old->user_id !== $user->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_key' => 'Este envío ya fue registrado con otros datos. Abre un formulario nuevo.']);
                }

                return $old;
            }
            $account = EntelDigitalAccount::where('kind', $v['kind'])->lockForUpdate()->first();
            if ($initial) {
                if ($account) {
                    throw ValidationException::withMessages(['credited_amount' => 'Este saldo inicial ya está registrado. Usa Registrar carga para los ingresos posteriores.']);
                }
                // The unique kind prevents two administrators from initializing the same book.
                $account = EntelDigitalAccount::firstOrCreate(['kind' => $v['kind']], ['balance_cents' => 0, 'initialized_at' => now(), 'initialized_by' => $user->id]);
                if (! $account->wasRecentlyCreated) {
                    throw ValidationException::withMessages(['credited_amount' => 'Otro administrador ya registró el saldo inicial. Actualiza la pantalla.']);
                }
            } elseif (! $account) {
                throw ValidationException::withMessages(['credited_amount' => 'Registra primero el saldo inicial de este tipo, incluso si es cero.']);
            }
            $before = $account->balance_cents;
            $after = $this->calculator->balance($before, $credited);
            $valueBefore = $account->cost_value_cents;
            $valueAfter = app(CardAverageCostCalculator::class)->receive($before, $valueBefore, $credited, $paid);
            $account->update(['balance_cents' => $after, 'cost_value_cents' => $valueAfter, 'cost_revision' => $account->cost_revision + 1]);

            return EntelDigitalMovement::create([
                'entel_digital_account_id' => $account->id, 'user_id' => $user->id,
                'request_key' => $v['request_key'], 'payload_hash' => $hash, 'payload' => $payload,
                'operation' => $v['operation'], 'credited_cents' => $credited, 'paid_cents' => $paid,
                'cost_before_cents' => $valueBefore, 'cost_after_cents' => $valueAfter, ...$bonus, 'balance_before_cents' => $before, 'balance_after_cents' => $after,
                'reference' => $payload['reference'] ?: null, 'created_at' => now(),
            ]);
        }, 3);
    }
}
