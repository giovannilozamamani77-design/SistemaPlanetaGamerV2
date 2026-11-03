<?php

namespace App\Domain\Liquidaciones\Queries;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;

class LiquidationRulesQuery
{
    public function forAdmin(User $actor): array
    {
        app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.gestionar');

        $destinations = app(LiquidationAccountQuery::class)->catalog($actor)->keyBy('account_key');

        return CashBox::with('latestConfiguration')->orderBy('name')->get()->mapWithKeys(function (CashBox $box) use ($destinations): array {
            $configuration = $box->latestConfiguration;
            $options = [];
            foreach ($configuration?->accounts ?? [] as $code => $account) {
                if (($account['is_active'] ?? true) && in_array($account['category'] ?? ($code === 'bcp' ? 'banco' : ($code === 'yape' ? 'billetera' : '')), ['banco', 'billetera', 'tigo_money'], true)) {
                    $options[$code] = $account['name'];
                }
            }
            $source = $configuration?->settlement_source;

            return [$box->id => ['id' => $box->id, 'name' => $box->name, 'revision' => $box->revision, 'configured' => $configuration !== null,
                'destination_key' => $configuration?->settlement_destination_key ?? 'legacy',
                'destination_label' => $destinations->get($configuration?->settlement_destination_key ?? 'legacy')?->name ?? 'Sin configurar',
                'destination_reference' => $destinations->get($configuration?->settlement_destination_key ?? 'legacy')?->destination,
                'source' => $source, 'source_label' => $options[$source] ?? 'Sin configurar',
                'source_reference' => $configuration?->accounts[$source]['reference'] ?? null,
                'options' => $options, 'accounts' => $configuration?->accounts ?? [],
                'morning' => (bool) ($configuration?->shifts['manana']['liquidates'] ?? false),
                'afternoon' => (bool) ($configuration?->shifts['tarde']['liquidates'] ?? false)]];
        })->all();
    }
}
