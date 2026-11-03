<?php

namespace App\Domain\Cajas\Services;

use App\Domain\Cajas\Calculators\CashBoxClosingBalancePreview;
use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Liquidaciones\Calculators\LiquidationPreviewCalculator;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Shared financial assessment for summary, quote and locked confirmation. */
class ClosingFinancialAssessment
{
    public function calculate(User $actor, int $sessionId, array $data, array $context): array
    {
        $counted = app(CashBoxClosingBalancePreview::class)->calculate($data['balances'], $context['expected_balances']);
        if (! $counted['valid']) {
            throw ValidationException::withMessages(['balances' => 'Revisa los saldos: usa importes no negativos con hasta dos decimales.']);
        }
        $restored = app(ClosingRestoration::class)->apply($actor, $sessionId, $data, $counted, $context);
        $reserved = app(ReservedCashBoxFunds::class)->forBox($context['box_id']);
        try {
            $liquidation = app(LiquidationPreviewCalculator::class)->calculate($restored['balances'], $context['operating_capital'], $context['settlement_source'] ?? '', $reserved);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['closing' => 'Los saldos no cubren los fondos en custodia. Revisa los importes.']);
        }

        return compact('counted', 'restored', 'reserved', 'liquidation');
    }
}
