<?php

namespace App\Filament\Support\Arqueos;

use App\Domain\Arqueos\Calculators\CashCountCalculator;
use App\Support\Bolivianos;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait InteractsWithCashCount
{
    #[Locked]
    public bool $cashCountOpen = false;

    #[Locked]
    public ?array $acceptedCashCount = null;

    public array $cashCountQuantities = [];

    public array $cashCountPacked = [];

    #[Computed]
    public function cashCountPreview(): array
    {
        $this->authorizeCashCountAccess();

        return app(CashCountCalculator::class)->calculate($this->cashCountQuantities, $this->cashCountPacked);
    }

    public function openCashCount(): void
    {
        $this->authorizeCashCountStep();
        $empty = array_fill_keys(array_keys(CashCountCalculator::DENOMINATIONS), '');
        $this->cashCountQuantities = array_replace($empty, array_map(fn (int $pieces): string => (string) $pieces, $this->acceptedCashCount['quantities'] ?? []));
        $this->cashCountPacked = array_replace($empty, array_map(fn (int $cents): string => Bolivianos::format($cents), $this->acceptedCashCount['packed_amounts_cents'] ?? []));
        $this->cashCountOpen = true;
        $this->resetValidation('cashCount');
        unset($this->cashCountPreview);
        $this->dispatch('open-modal', id: 'cash-count-editor');
    }

    public function applyCashCount(): void
    {
        $this->authorizeCashCountStep();
        if (! $this->cashCountOpen) {
            throw ValidationException::withMessages(['cashCount' => 'Abre el arqueo antes de aplicar el conteo.']);
        }
        $result = $this->cashCountPreview;
        if (! $result['valid']) {
            throw ValidationException::withMessages(['cashCount' => 'Revisa las piezas y los importes embalados antes de aplicar.']);
        }
        $this->acceptedCashCount = $result['snapshot'];
        $this->applyAcceptedCashCount($this->acceptedCashCount);
        $this->closeCashCountEditor();
    }

    public function dismissCashCount(): void
    {
        $this->authorizeCashCountAccess();
        $this->closeCashCountEditor();
    }

    abstract protected function authorizeCashCountAccess(): void;

    abstract protected function authorizeCashCountStep(): void;

    abstract protected function applyAcceptedCashCount(array $snapshot): void;

    protected function closeCashCountEditor(): void
    {
        $this->cashCountOpen = false;
        $this->cashCountQuantities = [];
        $this->cashCountPacked = [];
        $this->resetValidation('cashCount');
        unset($this->cashCountPreview);
        $this->dispatch('close-modal', id: 'cash-count-editor');
    }

    protected function resetCashCount(): void
    {
        $this->acceptedCashCount = null;
        $this->closeCashCountEditor();
    }
}
