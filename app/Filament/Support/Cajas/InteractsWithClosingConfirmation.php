<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Cajas\Actions\ConfirmCashBoxClosingAction;
use App\Filament\Pages\Cajas\CashBoxCatalog;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait InteractsWithClosingConfirmation
{
    #[Locked]
    public string $confirmationKey = '';

    #[Locked]
    public ?int $reviewedRevision = null;

    #[Locked]
    public bool $reviewedLiquidates = false;

    #[Locked]
    public ?array $liquidationConfirmation = null;

    public string $closingReason = '';

    public string $operationReference = '';

    public bool $transferred = false;

    public function confirmClosing(): void
    {
        if ($this->step !== 4 || $this->reviewedRevision === null || ($this->reviewedLiquidates && (! $this->liquidationPreviewOpen || $this->liquidationConfirmation === null))) {
            throw ValidationException::withMessages(['closing' => 'Revisa el resumen y abre la liquidación antes de confirmar.']);
        }
        if (! $this->matchesDeclaredTransfer() || ($this->reviewedLiquidates && ! $this->liquidationReady)) {
            throw ValidationException::withMessages(['closing' => 'Revisa la liquidación. Si ya transferiste, conserva la referencia y concíliala; no repitas el envío.']);
        }
        $close = app(ConfirmCashBoxClosingAction::class)->execute(auth()->user(), $this->cashBoxSession, [
            'period' => $this->period, 'balances' => $this->balances, 'readings' => $this->readings,
            'expected_revision' => $this->reviewedRevision, 'liquidate' => $this->reviewedLiquidates,
            'account_id' => $this->liquidationConfirmation['account_id'] ?? null, 'amount_cents' => $this->liquidationConfirmation['amount_cents'] ?? 0,
            'operation_reference' => $this->operationReference, 'transferred' => $this->transferred,
            'restoration_token' => $this->restorationToken, 'reason' => $this->closingReason, 'cash_count' => $this->acceptedCashCount, 'request_key' => $this->confirmationKey,
        ]);
        Notification::make()->title($close->difference_cents < 0 ? 'Cierre registrado: pendiente de revisión administrativa' : 'Cierre confirmado')->success()->send();
        $this->redirect(CashBoxCatalog::getUrl(), navigate: false);
        $this->skipRender();
    }

    protected function resetClosingConfirmation(): void
    {
        $this->reviewedRevision = null;
        $this->reviewedLiquidates = false;
        $this->liquidationConfirmation = null;
        $this->operationReference = '';
        $this->transferred = false;
    }
}
