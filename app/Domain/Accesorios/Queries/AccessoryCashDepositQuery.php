<?php

namespace App\Domain\Accesorios\Queries;

use App\Domain\Accesorios\Models\AccessoryCashDeposit;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class AccessoryCashDepositQuery
{
    public function authorize(User $actor, string $ability = 'create'): User
    {
        $u = $actor->fresh();
        if (! $u || ! $u->is_active) {
            throw new AuthorizationException;
        }
        app(AccessorySaleQuery::class)->authorize($u, $ability === 'void' ? 'viewAny' : $ability);
        if ($ability === 'void' && (! $u->hasRole('administrador') || ! $u->can('accesorios.inventario.gestionar'))) {
            throw new AuthorizationException;
        }

        return $u;
    }

    public function canVoid(User $actor): bool
    {
        try {
            $this->authorize($actor, 'void');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function history(User $u): Builder
    {
        $this->authorize($u, 'viewAny');

        return AccessoryCashDeposit::query()->with(['reversal', 'movement']);
    }

    public function historyFilterOptions(User $user): array
    {
        $history = $this->history($user);
        $latestUsers = (clone $history)->selectRaw('MAX(id) as id')->groupBy('user_id');
        $users = AccessoryCashDeposit::whereIn('id', $latestUsers)->orderBy('actor_name')->orderBy('user_id')->pluck('actor_name', 'user_id')->all();
        $latestAccounts = (clone $history)->selectRaw('MAX(id) as id')->groupBy('account_id');
        $accounts = AccessoryCashDeposit::whereIn('id', $latestAccounts)->orderBy('account_id')->get(['account_id', 'destination_snapshot'])->mapWithKeys(fn ($deposit) => [
            $deposit->account_id => $deposit->destination_snapshot['name'].' · '.$deposit->destination_snapshot['bank'].' · '.$deposit->destination_snapshot['number'],
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
            $query->where('account_id', (int) $data['account_id']);
        }

        return $query;
    }

    public function accounts(User $u): array
    {
        $this->authorize($u);

        return CardDepositAccount::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->bank])->all();
    }

    public function quote(User $u, ?int $id = null): array
    {
        $this->authorize($u);
        $fund = CommercialFund::where('code', 'GENERAL')->first();
        $a = $id ? CardDepositAccount::whereKey($id)->where('is_active', true)->first() : null;

        return ['cash_cents' => $fund?->balance_cents ?? 0, 'expected_revision' => $fund?->revision ?? 0, 'destination' => $a?->snapshot(), 'qr_url' => $a?->qr_path ? route('accesorios.deposito-qr', ['account' => $a->id, 'revision' => $a->revision]) : null];
    }
}
