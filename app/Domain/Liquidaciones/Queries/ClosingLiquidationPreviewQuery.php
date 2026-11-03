<?php

namespace App\Domain\Liquidaciones\Queries;

use App\Domain\Cajas\Queries\CashBoxClosingStartQuery;
use App\Domain\Cajas\Services\ClosingFinancialAssessment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Read-only quote; opening or dismissing it never records a closing or transfer. */
class ClosingLiquidationPreviewQuery
{
    public function __construct(
        private CashBoxClosingStartQuery $closing,
        private LiquidationAccountQuery $accounts,
    ) {}

    public function execute(User $actor, int $sessionId, ?string $period, array $balances, ?string $restorationToken = null): array
    {
        $context = $this->closing->execute($actor, $sessionId);
        if (! ($context['shifts'][$period ?? '']['liquidates'] ?? false)) {
            throw ValidationException::withMessages(['liquidation' => 'Revisa el turno y los saldos antes de consultar la liquidación.']);
        }
        $assessment = app(ClosingFinancialAssessment::class)->calculate($actor, $sessionId,
            ['balances' => $balances, 'period' => $period, 'restoration_token' => $restorationToken], $context);
        $source = $context['settlement_source'] ?? '';
        $calculation = $assessment['liquidation'];

        return ['source' => $source, 'source_account' => $context['accounts'][$source] ?? ['name' => 'Origen sin configurar'],
            'calculation' => $calculation, 'account' => $this->accounts->forKey($actor, $context['settlement_destination_key'] ?? 'legacy')];
    }
}
