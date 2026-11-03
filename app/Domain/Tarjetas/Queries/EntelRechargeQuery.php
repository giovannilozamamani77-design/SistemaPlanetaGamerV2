<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\EntelRechargeCalculator;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelRecharge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class EntelRechargeQuery
{
    public const UNAVAILABLE = 'No hay saldo disponible; comuníquese con administración';

    public function availability(User $user, string $kind, mixed $amount): string
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'sell');
        if (! array_key_exists($kind, EntelDigitalAccount::KINDS)) {
            return self::UNAVAILABLE;
        }
        try {
            $cents = CardInventoryInput::money($amount, 'amount');
        } catch (ValidationException) {
            return 'Ingresa el importe (mínimo Bs 100).';
        }
        if ($cents < 10000) {
            return 'Ingresa el importe (mínimo Bs 100).';
        }

        return EntelDigitalAccount::where('kind', $kind)->where('balance_cents', '>=', $cents)->exists() ? 'Saldo disponible' : self::UNAVAILABLE;
    }

    public function preview(User $user, string $kind, mixed $amount): ?array
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'sell');
        if (! array_key_exists($kind, EntelDigitalAccount::KINDS)) {
            return null;
        }
        try {
            $cents = CardInventoryInput::money($amount, 'amount');
            $column = $kind === 'CREDIT' ? 'entel_credit_bonus_bps' : 'entel_packages_bonus_bps';
            $bps = CardStore::where('code', 'TIENDA')->value($column) ?? ($kind === 'CREDIT' ? 800 : 1500);

            return app(EntelRechargeCalculator::class)->total($cents, (int) $bps);
        } catch (ValidationException) {
            return null;
        }
    }

    public function sales(User $user): Builder
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'sell');

        return EntelRecharge::query()->with('user');
    }
}
