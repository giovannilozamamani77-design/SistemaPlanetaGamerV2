<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Calculators\CashBoxClosingBalancePreview;
use App\Domain\Cajas\Queries\CashBoxPreviousHandoverQuery;
use App\Domain\Cajas\Queries\LateOpeningNoticeQuery;
use App\Domain\Cajas\Services\OpeningSurplusConsent;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
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

    #[Locked]
    public ?string $openingSurplusToken = null;

    #[Locked]
    public array $openingSurplusReview = [];

    public function confirmOpeningSurplusAction(): Action
    {
        return Action::make('confirmOpeningSurplus')->modalHeading('Atención: registrarás un sobrante de apertura')->color('warning')
            ->modalDescription(fn (): string => 'Contaste Bs '.Bolivianos::format($this->openingSurplusReview['total'] ?? 0).'. Se esperaban Bs '.Bolivianos::format($this->openingSurplusReview['reference'] ?? 0).'. La diferencia de Bs '.Bolivianos::format($this->openingSurplusReview['surplus'] ?? 0).' se registrará como sobrante, se mostrará junto a las comisiones y entrará en la liquidación. No aumenta el capital operativo. Cancela si es un error de digitación.')
            ->schema([Checkbox::make('accept_surplus')->label('Entiendo y confirmo que este sobrante es real.')->accepted()->required()])
            ->extraModalWindowAttributes(['x-on:keydown.enter.prevent.stop' => ''])
            ->modalSubmitActionLabel('Confirmar sobrante y abrir caja')->modalCancelActionLabel('Volver a corregir')
            ->action(function (): void {
                if (! $this->openingSurplusToken) {
                    throw ValidationException::withMessages(['opening_surplus_consent' => 'Vuelve a revisar los importes de apertura.']);
                }
                $this->persistOpening($this->openingSurplusToken);
                $this->openingSurplusToken = null;
                $this->openingSurplusReview = [];
            });
    }

    private function initializeOpening(): void
    {
        $this->openingSurplusToken = null;
        $this->openingSurplusReview = [];
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
        if ($this->openingPreview['valid'] && $this->openingPreview['total_cents'] > $this->workspace['opening_required_total']) {
            $review = app(OpeningSurplusConsent::class)->issue(auth()->user(), $this->accessId, ['balances' => $this->openingBalances, 'request_key' => $this->openingKey]);
            $this->openingSurplusToken = $review['token'];
            unset($review['token']);
            $this->openingSurplusReview = $review;
            $this->mountAction('confirmOpeningSurplus');

            return;
        }
        $this->persistOpening();
    }

    private function persistOpening(?string $surplusToken = null): void
    {
        try {
            $session = app(OpenCashBoxAction::class)->execute(auth()->user(), $this->accessId, [
                'surplus_confirmation' => $surplusToken, 'balances' => $this->openingBalances, 'request_key' => $this->openingKey, 'cash_count' => $this->acceptedCashCount,
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
        } else {
            $this->showScheduleSetupNotice();
        }

    }
}
