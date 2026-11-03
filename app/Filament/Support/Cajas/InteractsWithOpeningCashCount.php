<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Arqueos\Calculators\CashCountCalculator;
use App\Filament\Support\Arqueos\InteractsWithCashCount;
use App\Support\Bolivianos;

trait InteractsWithOpeningCashCount
{
    use InteractsWithCashCount;

    protected function authorizeCashCountAccess(): void
    {
        abort_unless($this->workspace && ! $this->workspace['session_id'] && $this->openingKey, 403);
    }

    protected function authorizeCashCountStep(): void
    {
        $this->authorizeCashCountAccess();
        abort_unless(isset($this->workspace['accounts']['efectivo']), 403);
    }

    protected function applyAcceptedCashCount(array $snapshot): void
    {
        $this->openingBalances['efectivo'] = Bolivianos::formatCompact($snapshot['total_cents']);
        unset($this->openingPreview);
    }

    public function updatedOpeningBalances(mixed $value, ?string $key = null): void
    {
        $this->authorizeCashCountAccess();
        if ($this->acceptedCashCount !== null && ($key === null || $key === 'efectivo')
            && ! app(CashCountCalculator::class)->matchesCash($this->acceptedCashCount, $this->openingBalances['efectivo'] ?? '')) {
            $this->acceptedCashCount = null;
        }
    }
}
