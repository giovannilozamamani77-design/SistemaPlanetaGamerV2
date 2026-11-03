<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\Incidencias\Actions\ReportOwnGlobalRepaymentAction;
use App\Domain\Incidencias\Queries\OwnGlobalRepaymentQuoteQuery;
use App\Domain\Incidencias\Queries\PersonnelBalancesQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class OwnPersonnelSummaryWidget extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;

    protected string $view = 'incidencias.own-personnel-summary';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    #[Locked]
    public string $section = 'all';

    protected function getViewData(): array
    {
        return ['balances' => app(PersonnelBalancesQuery::class)->execute(auth()->user()),
            'quote' => $this->section === 'primary' && auth()->user()->can('incidencias.declarar_aporte') ? app(OwnGlobalRepaymentQuoteQuery::class)->execute(auth()->user()) : null];
    }

    public function payTotalAction(): Action
    {
        return $this->repaymentAction('payTotal', 'Pagar totalidad', 'total');
    }

    public function payPartialAction(): Action
    {
        return $this->repaymentAction('payPartial', 'Pagar una cantidad', 'partial');
    }

    private function repaymentAction(string $name, string $label, string $mode): Action
    {
        return Action::make($name)->label($label)->icon('heroicon-o-banknotes')->color($mode === 'total' ? 'primary' : 'gray')
            ->modalHeading($label)->modalWidth('lg')->modalDescription('Informa un depósito a la cuenta de reposiciones. El saldo se reducirá cuando Administración verifique el ingreso; primero se cubrirán los faltantes más antiguos.')
            ->modalContent(fn () => view('incidencias.global-repayment-destination', app(OwnGlobalRepaymentQuoteQuery::class)->execute(auth()->user())))
            ->schema([
                TextInput::make('amount')->label('Importe depositado')->prefix('Bs')->required()->readOnly($mode === 'total')
                    ->default(fn () => $mode === 'total' ? Bolivianos::format(app(OwnGlobalRepaymentQuoteQuery::class)->execute(auth()->user())['pending']) : null),
                TextInput::make('operation_reference')->label('ID de transacción')->required()->maxLength(120),
                Hidden::make('request_key')->default(fn () => (string) Str::uuid()),
            ])->modalSubmitActionLabel('Enviar para verificación')->modalCancelActionLabel('Volver sin registrar')
            ->action(function (array $data) use ($mode): void {
                app(ReportOwnGlobalRepaymentAction::class)->execute(auth()->user(), [...$data, 'mode' => $mode]);
                $this->cachedStats = null;
                $this->dispatch('personnel-record-updated');
                Notification::make()->success()->title('Depósito informado. Pendiente de verificación.')->send();
            });
    }

    protected function getStats(): array
    {
        $b = app(PersonnelBalancesQuery::class)->execute(auth()->user());

        $primary = Stat::make('Faltante acumulado pendiente', 'Bs '.Bolivianos::format($b['pending']))
            ->description('Solo responsabilidad confirmada. Por revisar: Bs '.Bolivianos::format($b['under_review']).' (no incluido).')
            ->icon('heroicon-o-exclamation-circle')->color($b['pending'] > 0 ? 'danger' : 'primary')->columnSpanFull()
            ->extraAttributes(['class' => 'pg-shortage-accumulated']);
        $details = [
            Stat::make('Reposiciones realizadas', 'Bs '.Bolivianos::format($b['received']))->description('Solo depósitos y entregas verificados.'),
            Stat::make('Descuento salarial aplicado', 'Bs '.Bolivianos::format($b['salary']))->description('Solo aplicaciones reales verificadas.'),
            Stat::make('Perdonado', 'Bs '.Bolivianos::format($b['forgiven']))->description('Decisiones administrativas conservadas.'),
            Stat::make('Pendiente anterior', 'Bs '.Bolivianos::format($b['previous']))->description('Ya incluido en el acumulado; no se suma otra vez.'),
        ];

        return match ($this->section) {
            'primary' => [$primary],
            'details' => $details,
            default => [$primary, ...$details],
        };
    }
}
