<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardAverageCostCalculator;
use App\Domain\Tarjetas\Calculators\CardSaleCalculator;
use App\Domain\Tarjetas\Calculators\EntelRechargeCalculator;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelDigitalMovement;
use App\Domain\Tarjetas\Models\EntelRecharge;
use App\Domain\Tarjetas\Queries\EntelRechargeQuery;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordEntelRechargeAction
{
    public function __construct(private CardInventoryAuthorization $authorization, private CardInventoryCommandRunner $commands, private EntelRechargeCalculator $calculator) {}

    public function execute(User $actor, array $data): EntelRecharge
    {
        $this->authorization->authorize($actor, 'sell');
        $v = Validator::make($data, ['kind' => ['required', Rule::in(array_keys(EntelDigitalAccount::KINDS))], 'amount' => ['required'], 'external_confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid']])->validate();
        $amount = CardInventoryInput::money($v['amount'], 'amount');
        $this->calculator->total($amount, 0);
        $payload = ['kind' => $v['kind'], 'amount_cents' => $amount, 'external_confirmed' => true];
        $result = $this->commands->execute($actor, 'sell', 'ENTEL_RECHARGE', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload, $v, $amount): array {
            if (! $store->initialized_at) {
                throw ValidationException::withMessages(['amount' => 'Administración debe registrar primero el inventario y efectivo iniciales.']);
            }
            $account = EntelDigitalAccount::where('kind', $payload['kind'])->lockForUpdate()->first();
            if (! $account || $account->balance_cents < $amount) {
                throw ValidationException::withMessages(['amount' => EntelRechargeQuery::UNAVAILABLE]);
            }
            if ($amount > CardSaleCalculator::MAX_CENTS - $store->cash_balance_cents) {
                throw ValidationException::withMessages(['amount' => 'El efectivo resultante supera el importe admitido.']);
            }
            if (EntelDigitalMovement::where('request_key', $v['request_key'])->exists()) {
                throw ValidationException::withMessages(['request_key' => 'Este envío ya fue utilizado. Abre un formulario nuevo.']);
            }
            $totals = $this->calculator->total($amount, $store->{$payload['kind'] === 'CREDIT' ? 'entel_credit_bonus_bps' : 'entel_packages_bonus_bps'});
            $before = $account->balance_cents;
            $valueBefore = $account->cost_value_cents;
            $valuation = app(CardAverageCostCalculator::class)->consume($before, $valueBefore, $amount);
            $cost = $valuation['consumed'];
            $account->update(['balance_cents' => $before - $amount, 'cost_value_cents' => $valuation['remaining'], 'cost_revision' => $account->cost_revision + 1]);
            $digital = EntelDigitalMovement::create(['entel_digital_account_id' => $account->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'payload' => $payload, 'operation' => 'RECHARGE', 'cost_before_cents' => $valueBefore, 'cost_after_cents' => $valuation['remaining'], 'credited_cents' => 0, 'debited_cents' => $amount, 'paid_cents' => null, 'bonus_cents' => null, 'bonus_rate_bps' => null, 'balance_before_cents' => $before, 'balance_after_cents' => $before - $amount, 'created_at' => now()]);
            $cash = CardInventoryMovement::create(['card_store_id' => $store->id, 'card_product_id' => null, 'user_id' => $user->id, 'kind' => 'RECHARGE_'.$payload['kind'], 'quantity' => 0, 'unit_cost_cents' => null, 'total_cost_cents' => $cost, 'cash_delta_cents' => $amount, 'stock_before' => null, 'stock_after' => null, 'cash_before_cents' => $store->cash_balance_cents, 'cash_after_cents' => $store->cash_balance_cents + $amount, 'product_snapshot' => ['label' => 'Recarga Entel · '.EntelDigitalAccount::KINDS[$payload['kind']]], 'reference' => 'Recarga Entel confirmada']);
            $sale = EntelRecharge::create(['card_store_id' => $store->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'kind' => $payload['kind'], 'amount_cents' => $amount, 'cost_cents' => $cost, 'gross_margin_cents' => $cost === null ? null : $amount - $cost, ...$totals, 'entel_digital_movement_id' => $digital->id, 'card_inventory_movement_id' => $cash->id, 'created_at' => now()]);
            $store->update(['cash_balance_cents' => $store->cash_balance_cents + $amount, 'revision' => $store->revision + 1]);

            return ['recharge_id' => $sale->id];
        });

        return EntelRecharge::findOrFail($result['recharge_id']);
    }
}
