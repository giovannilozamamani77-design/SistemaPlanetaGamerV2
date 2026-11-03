<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Arqueos\Calculators\CashCountCalculator;
use App\Filament\Support\Arqueos\InteractsWithCashCount;
use App\Support\Bolivianos;
use Illuminate\Validation\ValidationException;

trait InteractsWithClosingCashCount
{
    use InteractsWithCashCount;

    protected function authorizeCashCountAccess(): void
    {
        $this->context;
    }

    protected function authorizeCashCountStep(): void
    {
        if ($this->step !== 2 || ! isset($this->context['accounts']['efectivo'])) {
            throw ValidationException::withMessages(['cashCount' => 'El arqueo se realiza junto al efectivo en el paso Saldos.']);
        }
    }

    protected function applyAcceptedCashCount(array $snapshot): void
    {
        $this->balances['efectivo'] = Bolivianos::format($snapshot['total_cents']);
        unset($this->preview);
    }

    public function updatedBalances(mixed $value, ?string $key = null): void
    {
        $this->authorizeCashCountAccess();
        if ($this->acceptedCashCount !== null && ($key === null || $key === 'efectivo')
            && ! app(CashCountCalculator::class)->matchesCash($this->acceptedCashCount, $this->balances['efectivo'] ?? '')) {
            $this->acceptedCashCount = null;
        }
    }
}
