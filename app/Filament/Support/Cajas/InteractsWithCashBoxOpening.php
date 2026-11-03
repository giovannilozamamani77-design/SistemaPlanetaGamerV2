<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Calculators\CashBoxClosingBalancePreview;
use App\Domain\Cajas\Queries\CashBoxPreviousHandoverQuery;
use App\Domain\Cajas\Queries\LateOpeningNoticeQuery;
use App\Support\Bolivianos;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait InteractsWithCashBoxOpening
{
    public array $openingBalances = [];

    #[Locked]
    public ?string $openingKey = null;

    private function initializeOpening(): void
    {
        $this->resetCashCount();
        $this->openingBalances = [];
        $this->openingKey = null;
        if ($this->workspace && ! $this->workspace['session_id']) {
            $this->openingBalances = array_map(fn (int $cents): string => $this->workspace['first_count'] ? '' : Bolivianos::formatCompact($cents), $this->workspace['expected']);
            $this->openingKey = (string) Str::uuid();
        }
    }

    #[Computed]
    public function openingPreview(): array
    {
        return app(CashBoxClosingBalancePreview::class)->calculate($this->openingBalances, $this->workspace['expected']);
    }

    #[Computed]
    public function previousHandover(): ?array
    {
        return $this->accessId ? app(CashBoxPreviousHandoverQuery::class)->execute(auth()->user(), $this->accessId) : null;
    }

    public function confirmOpening(): void
    {
        abort_unless($this->accessId && $this->openingKey, 403);
        try {
            $session = app(OpenCashBoxAction::class)->execute(auth()->user(), $this->accessId, [
                'balances' => $this->openingBalances, 'request_key' => $this->openingKey, 'cash_count' => $this->acceptedCashCount,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                fn ($messages, $field) => [str_starts_with($field, 'balances.') ? 'openingBalances.'.substr($field, 9) : $field => $messages]
            )->all());
        }
        $this->closeCashCountEditor();
        $this->selectedCashBoxId = $session->cash_box_id;
        unset($this->workspace);
        $this->resetQuickCommissionForms();
        Notification::make()->title('Caja abierta a tu nombre')->success()->send();
        if (app(LateOpeningNoticeQuery::class)->execute(auth()->user(), $session->id)) {
            $this->mountAction('lateOpeningNotice');
        }

    }
}
