<?php

namespace App\Domain\Envios\Queries;

use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Models\UsdRateSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UsdRatesQuery
{
    public function overview(User $actor): array
    {
        app(TransferAuthorization::class)->admin(User::findOrFail($actor->id));
        $history = UsdRateSetting::with('creator:id,name')->latest('id')->limit(5)->get();

        return ['current' => $history->first(), 'history' => $history, 'reference' => DB::table('usd_rate_references')->latest('quoted_at')->first()];
    }
}
