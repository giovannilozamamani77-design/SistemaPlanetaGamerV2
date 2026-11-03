<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class CardDepositQuery
{
    public function history(User $user): Builder
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'deposit');
        app(CardInventoryAuthorization::class)->authorize($user, 'history');

        return CardInventoryMovement::query()->where('kind', 'ADMIN_CASH_DEPOSIT')->with('user');
    }

    public function historyFilterOptions(User $user): array
    {
        $history = $this->history($user);
        $users = User::whereIn('id', (clone $history)->select('user_id'))->orderBy('name')->pluck('name', 'id')->all();
        $latest = (clone $history)->selectRaw('MAX(id) as id')->groupBy('destination_snapshot->id');
        $accounts = CardInventoryMovement::whereIn('id', $latest)->orderBy('id')->get(['destination_snapshot'])->mapWithKeys(fn ($m) => [
            $m->destination_snapshot['id'] => $m->destination_snapshot['name'].' · '.$m->destination_snapshot['bank'].' · '.$m->destination_snapshot['number'],
        ])->all();

        return ['users' => $users, 'accounts' => $accounts];
    }

    public function filterHistory(Builder $query, array $data): Builder
    {
        if (Validator::make($data, ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'], 'user_id' => ['nullable', 'integer', 'min:1'], 'account_id' => ['nullable', 'integer', 'min:1']])->fails()
            || (filled($data['from'] ?? null) && filled($data['until'] ?? null) && $data['from'] > $data['until'])) {
            return $query->whereRaw('1 = 0');
        }
        foreach (['from', 'until'] as $field) {
            if (filled($data[$field] ?? null)) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $data[$field], 'America/La_Paz');
                $boundary = ($field === 'from' ? $date : $date->addDay())->utc();
                $query->where('created_at', $field === 'from' ? '>=' : '<', $boundary);
            }
        }
        if (filled($data['user_id'] ?? null)) {
            $query->where('user_id', (int) $data['user_id']);
        }
        if (filled($data['account_id'] ?? null)) {
            $query->where('destination_snapshot->id', (int) $data['account_id']);
        }

        return $query;
    }

    public function accounts(User $user): Builder
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'configureDeposits');

        return CardDepositAccount::query();
    }

    public function options(User $user): array
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'deposit');

        return CardDepositAccount::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->bank])->all();
    }

    public function quote(User $user, ?int $id): array
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'deposit');
        $store = CardStore::where('code', 'TIENDA')->first();
        $a = $id ? CardDepositAccount::whereKey($id)->where('is_active', true)->first() : null;

        return ['cash_cents' => $store?->initialized_at ? $store->cash_balance_cents : null, 'destination' => $a?->snapshot(), 'qr_url' => $a?->qr_path ? route('tarjetas.deposito-qr', $a->id) : null];
    }
}
