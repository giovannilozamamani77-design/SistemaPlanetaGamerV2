<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Cajas\Services\ClosingFinancialAssessment;
use App\Domain\Cajas\Services\ClosingRestoration;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait InteractsWithClosingRestoration
{
    #[Locked]
    public ?string $restorationToken = null;

    public array $restorationInput = ['component' => 'bcp', 'amount' => '', 'receipt' => '', 'received' => false];

    public ?int $restorationAdminId = null;

    public string $restorationAdminPin = '';

    #[Computed]
    public function restorationAdministrators(): array
    {
        return app(ClosingRestoration::class)->administrators(auth()->user(), $this->cashBoxSession);
    }

    #[Computed]
    public function financialAssessment(): array
    {
        try {
            return ['valid' => true] + app(ClosingFinancialAssessment::class)->calculate(auth()->user(), $this->cashBoxSession,
                ['period' => $this->period, 'balances' => $this->balances, 'restoration_token' => $this->restorationToken], $this->context);
        } catch (ValidationException $exception) {
            return ['valid' => false, 'message' => $exception->validator->errors()->first()];
        }
    }

    public function authorizeRestoration(): void
    {
        abort_unless($this->step === 4 && $this->reviewedRevision !== null, 403);
        try {
            $this->restorationToken = app(ClosingRestoration::class)->authorize(auth()->user(), $this->cashBoxSession,
                ['period' => $this->period, 'balances' => $this->balances, 'expected_revision' => $this->reviewedRevision],
                $this->restorationInput, (int) $this->restorationAdminId, $this->restorationAdminPin);
            unset($this->financialAssessment, $this->liquidationPreview);
            $this->closeLiquidationPreview();
            $this->resetValidation();
        } finally {
            $this->restorationAdminPin = '';
        }
    }

    protected function resetRestoration(): void
    {
        $this->restorationToken = null;
        $this->restorationInput = ['component' => 'bcp', 'amount' => '', 'receipt' => '', 'received' => false];
        $this->restorationAdminId = null;
        $this->restorationAdminPin = '';
        unset($this->financialAssessment);
    }
}
