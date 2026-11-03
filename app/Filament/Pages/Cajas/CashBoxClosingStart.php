<?php

namespace App\Filament\Pages\Cajas;

use App\Domain\Cajas\Actions\PrepareCashBoxClosingAccessAction;
use App\Domain\Cajas\Calculators\CashBoxClosingBalancePreview;
use App\Domain\Cajas\Calculators\CashBoxClosingCounterPreview;
use App\Domain\Cajas\Queries\CashBoxClosingAccessQuery;
use App\Domain\Cajas\Queries\CashBoxClosingCounterReferenceQuery;
use App\Domain\Cajas\Queries\CashBoxClosingStartQuery;
use App\Filament\Support\Cajas\InteractsWithClosingCashCount;
use App\Filament\Support\Cajas\InteractsWithClosingConfirmation;
use App\Filament\Support\Cajas\InteractsWithClosingLiquidationPreview;
use App\Filament\Support\Cajas\InteractsWithClosingRestoration;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class CashBoxClosingStart extends Page
{
    use InteractsWithClosingCashCount;
    use InteractsWithClosingConfirmation;
    use InteractsWithClosingLiquidationPreview;
    use InteractsWithClosingRestoration;

    protected string $view = 'cajas.closing-start';

    protected static ?string $slug = 'cajas/{cashBoxSession}/iniciar-cierre';

    protected static ?string $title = 'Cerrar caja';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public int $cashBoxSession = 0;

    public ?string $period = null;

    #[Locked]
    public int $step = 1;

    public array $balances = [];

    public array $readings = ['general' => '', 'fixed' => ''];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('cajas.operar') ?? false;
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(int $cashBoxSession): void
    {
        $this->cashBoxSession = $cashBoxSession;
        $gate = app(PrepareCashBoxClosingAccessAction::class)->execute(auth()->user(), $cashBoxSession);
        if (! $gate['allowed']) {
            Notification::make()->title('Cierre anticipado')->body($gate['message'])->warning()->send();
            $box = $this->context['box_id'];
            $this->redirect(CashBoxOperations::getUrl(['cashBox' => $box]), navigate: false);

            return;
        }

        $this->confirmationKey = (string) Str::uuid();
        $this->balances = array_fill_keys(array_keys($this->context['accounts']), '');
    }

    #[Computed]
    public function context(): array
    {
        return app(CashBoxClosingStartQuery::class)->execute(auth()->user(), $this->cashBoxSession, $this->step === 4);
    }

    #[Computed]
    public function preview(): array
    {
        return app(CashBoxClosingBalancePreview::class)->calculate($this->balances, $this->context['expected_balances']);
    }

    #[Computed]
    public function counterReference(): array
    {
        $context = $this->context;

        return $context['family'] === 'BCP'
            ? app(CashBoxClosingCounterReferenceQuery::class)->forBox($context['box_id'])
            : ['counters' => []];
    }

    #[Computed]
    public function counterPreview(): array
    {
        return $this->context['family'] === 'BCP'
            ? app(CashBoxClosingCounterPreview::class)->calculate($this->readings, $this->counterReference)
            : ['valid' => true, 'counters' => []];
    }

    public function nextStep(): void
    {
        $this->validateOnly('period', ['period' => ['required', Rule::in(array_keys($this->context['shifts']))]]);
        app(CashBoxClosingAccessQuery::class)->assertAllowed(auth()->user(), $this->cashBoxSession, $this->period);
        if ($this->step >= 2 && ! $this->preview['valid']) {
            throw ValidationException::withMessages(['balances' => 'Revisa los saldos: usa importes no negativos con hasta dos decimales.']);
        }
        if ($this->step >= 3 && ! $this->counterPreview['valid']) {
            $errors = [];
            foreach ($this->counterPreview['counters'] as $key => $counter) {
                if ($counter['error'] !== null) {
                    $errors['readings.'.$key] = $counter['error'];
                }
            }
            throw ValidationException::withMessages($errors);
        }
        if ($this->step === 3) {
            app(CashBoxClosingStartQuery::class)->assertOperationsReady(auth()->user(), $this->cashBoxSession);
        }
        $this->closeCashCountEditor();
        $this->closeLiquidationPreview();
        $this->step = min(4, $this->step + 1);
        unset($this->context);
        if ($this->step === 4) {
            $this->reviewedRevision = $this->context['revision'];
            $this->reviewedLiquidates = $this->context['shifts'][$this->period]['liquidates'];
        }
        $this->resetValidation();
    }

    public function previousStep(): void
    {
        $this->context;
        $this->closeCashCountEditor();
        $this->closeLiquidationPreview();
        $this->step = max(1, $this->step - 1);
        $this->resetClosingConfirmation();
        unset($this->context);
        $this->resetValidation();
    }

    public function cancelClosing(): void
    {
        $context = app(CashBoxClosingStartQuery::class)->execute(auth()->user(), $this->cashBoxSession);
        $this->period = null;
        $this->resetClosingConfirmation();
        $this->closingReason = '';
        $this->step = 1;
        $this->balances = array_fill_keys(array_keys($context['accounts']), '');
        $this->readings = ['general' => '', 'fixed' => ''];
        $this->resetCashCount();
        $this->resetRestoration();
        $this->closeLiquidationPreview();
        $this->declaredTransfer = null;
        $this->resetValidation();
        unset($this->context, $this->preview, $this->counterReference, $this->counterPreview);
        $this->redirect(CashBoxOperations::getUrl(['cashBox' => $context['box_id']]), navigate: false);
    }

    public function updatedPeriod(): void
    {
        $this->validateOnly('period', ['period' => ['required', Rule::in(array_keys($this->context['shifts']))]]);
        app(CashBoxClosingAccessQuery::class)->assertAllowed(auth()->user(), $this->cashBoxSession, $this->period);
    }
}
