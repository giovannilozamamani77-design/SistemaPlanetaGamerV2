<?php

namespace App\Domain\Envios\Queries;

use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Models\ExchangePolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BrazilRatesQuery
{
    public function overview(User $actor): array
    {
        app(TransferAuthorization::class)->admin(User::findOrFail($actor->id));
        $history = ExchangePolicy::query()->leftJoin('users', 'users.id', '=', 'exchange_policies.created_by')->select('exchange_policies.*', 'users.name as actor_name')->orderByDesc('exchange_policies.id')->limit(5)->get();

        return ['current' => $history->first(), 'history' => $history, 'reference' => DB::table('exchange_references')->latest('quoted_at')->first()];
    }
}
