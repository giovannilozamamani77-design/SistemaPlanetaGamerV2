<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Cajas\Services\ClosingConfirmationRules;
use App\Domain\Liquidaciones\Queries\ClosingLiquidationPreviewQuery;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait InteractsWithClosingLiquidationPreview
{
    #[Locked]
    public ?array $declaredTransfer = null;

    #[Locked]
    public bool $liquidationPreviewOpen = false;

    #[Locked]
    public bool $liquidationReady = false;

    #[Locked]
    public ?string $liquidationReadinessMessage = null;

    #[Computed]
    public function liquidationPreview(): array
    {
        return app(ClosingLiquidationPreviewQuery::class)->execute(auth()->user(), $this->cashBoxSession, $this->period, $this->balances, $this->restorationToken);
    }

    public function openLiquidationPreview(): void
    {
        $this->context;
        if ($this->step !== 4 || ! $this->counterPreview['valid']) {
            throw ValidationException::withMessages(['liquidation' => 'Completa los pasos del cierre antes de consultar la liquidación.']);
        }
        $quote = $this->liquidationPreview;
        $this->liquidationConfirmation = ['account_id' => $quote['account']?->id, 'amount_cents' => $quote['calculation']['amount_cents']];
        $readiness = app(ClosingConfirmationRules::class)->preview(auth()->user(), $this->cashBoxSession, [
            'period' => $this->period, 'balances' => $this->balances, 'readings' => $this->readings, 'expected_revision' => $this->reviewedRevision,
            'restoration_token' => $this->restorationToken, 'liquidate' => true, 'reason' => $this->closingReason, 'cash_count' => $this->acceptedCashCount,
        ]);
        $this->liquidationReady = $readiness['ready'];
        $this->liquidationReadinessMessage = $readiness['message'];
        $this->operationReference = '';
        $this->transferred = false;
        if ($this->declaredTransfer !== null) {
            $this->operationReference = $this->declaredTransfer['reference'];
            $this->transferred = true;
            if (! $this->matchesDeclaredTransfer()) {
                $this->liquidationReady = false;
                $this->liquidationReadinessMessage = 'Ya declaraste una transferencia con otra cuenta o importe. No la repitas: concilia esa referencia antes de continuar.';
            }
        }
        $this->liquidationPreviewOpen = true;
        $this->dispatch('open-modal', id: 'closing-liquidation-preview');
    }

    public function dismissLiquidationPreview(): void
    {
        $this->context;
        $this->closeLiquidationPreview();
    }

    protected function closeLiquidationPreview(): void
    {
        if ($this->declaredTransfer === null && $this->transferred && trim($this->operationReference) !== '' && $this->liquidationConfirmation !== null) {
            $this->declaredTransfer = $this->liquidationConfirmation + ['period' => $this->period, 'source' => $this->context['settlement_source'], 'reference' => trim($this->operationReference)];
        }

        $this->liquidationPreviewOpen = false;
        $this->liquidationReady = false;
        $this->liquidationReadinessMessage = null;
        $this->liquidationConfirmation = null;
        $this->operationReference = '';
        $this->transferred = false;
        unset($this->liquidationPreview);
        $this->dispatch('close-modal', id: 'closing-liquidation-preview');
    }

    protected function matchesDeclaredTransfer(): bool
    {
        return $this->declaredTransfer === null || ($this->reviewedLiquidates
            && $this->declaredTransfer['account_id'] === ($this->liquidationConfirmation['account_id'] ?? null)
            && $this->declaredTransfer['amount_cents'] === ($this->liquidationConfirmation['amount_cents'] ?? null)
            && $this->declaredTransfer['reference'] === trim($this->operationReference)
            && $this->transferred
            && $this->declaredTransfer['period'] === $this->period
            && $this->declaredTransfer['source'] === $this->context['settlement_source']);
    }
}
