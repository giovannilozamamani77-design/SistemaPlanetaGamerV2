<?php

namespace App\Filament\Pages\Prestamos;

use App\Domain\Prestamos\Actions\ImportHistoricalLoanAction;
use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Calculators\LoanTermCalculator;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Domain\Prestamos\Services\HistoricalLoanImport;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Prestamos\HistoricalLoanImportForm;
use App\Filament\Support\Prestamos\LoanAnnulmentActions;
use App\Filament\Support\Prestamos\LoanDetailActions;
use App\Filament\Support\Prestamos\LoanRecoveryActions;
use App\Filament\Support\Prestamos\LoanSettlementActions;
use App\Filament\Support\Prestamos\LoanTermActions;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class LoanList extends Page implements HasTable
{
    use HandlesPersonnelActionErrors, InteractsWithTable;

    protected string $view = 'prestamos.loan-list';

    protected static ?string $title = 'Préstamos registrados';

    protected static string|\UnitEnum|null $navigationGroup = 'Préstamos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 1;

    #[Locked]
    public array $historicalImportData = [];

    #[Locked]
    public array $historicalImportReview = [];

    public function confirmHistoricalLoanAction(): Action
    {
        return Action::make('confirmHistoricalLoan')->modalHeading('Revisar préstamo antiguo y ajuste del fondo')->modalWidth('3xl')
            ->visible(fn (): bool => Gate::allows('importHistorical', Loan::class))
            ->modalContent(fn () => view('prestamos.historical-import-review', ['review' => $this->historicalImportReview]))
            ->schema([Checkbox::make('review_confirmed')->label('Verifiqué el préstamo original, su estado y el ajuste del fondo.')->accepted()->required()])
            ->modalSubmitActionLabel('Confirmar importación permanente')->modalCancelActionLabel('Cancelar sin importar')->closeModalByClickingAway(false)
            ->extraModalWindowAttributes(['x-on:keydown.enter.prevent.stop' => ''])
            ->extraModalFooterActions([Action::make('editHistoricalImport')->label('Volver a corregir')->color('gray')->action(fn () => $this->replaceMountedAction('registerHistoricalLoan'))])
            ->action(function (array $data): void {
                if (! $this->historicalImportData || ! $this->historicalImportReview) {
                    throw ValidationException::withMessages(['review_confirmed' => 'Completa y revisa primero los datos antiguos.']);
                }
                try {
                    $loan = app(ImportHistoricalLoanAction::class)->execute(auth()->user(), $this->historicalImportData + [
                        'review_confirmed' => $data['review_confirmed'] ?? false, 'expected_fund_revision' => $this->historicalImportReview['fund_revision'],
                        'expected_display_status' => $this->historicalImportReview['display_status'], 'expected_collection_cents' => $this->historicalImportReview['current_collection_cents'],
                    ]);
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(['review_confirmed' => collect($error->errors())->flatten()->implode(' · ')]);
                }
                $this->historicalImportData = [];
                $this->historicalImportReview = [];
                $this->resetTable();
                Notification::make()->title('Préstamo antiguo importado · N.º '.$loan->id)->success()->send();
            });
    }

    public function registerHistoricalLoanAction(): Action
    {
        return Action::make('registerHistoricalLoan')->label('Registrar préstamo antiguo')->icon('heroicon-o-clock')->color('warning')
            ->visible(fn (): bool => Gate::allows('importHistorical', Loan::class))->modalWidth('4xl')
            ->modalDescription('Solo carga del sistema anterior. Se registran los datos históricos y se ajusta el fondo por el desembolso menos los cobros históricos. No realiza una transferencia bancaria nueva.')
            ->schema(HistoricalLoanImportForm::schema())->fillForm(fn (): array => array_replace(['status' => 'ACTIVO', 'historical_expenses' => '0', 'request_key' => (string) Str::uuid()], $this->historicalImportData))->stickyModalHeader()->stickyModalFooter()
            ->modalSubmitActionLabel('Revisar antes de importar')->modalCancelActionLabel('Cancelar')->extraModalWindowAttributes(['x-on:keydown.enter.prevent.stop' => ''])
            ->action(function (array $data): void {
                app(LoanAuthorization::class)->authorize(auth()->user(), 'importHistorical');
                $this->historicalImportReview = app(HistoricalLoanImport::class)->prepare(auth()->user(), $data);
                $this->historicalImportData = $data;
                $this->replaceMountedAction('confirmHistoricalLoan');
            });
    }

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('viewAny', Loan::class);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getViewData(): array
    {
        return ['summary' => app(LoanQuery::class)->summary(auth()->user())];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->registerHistoricalLoanAction(),
            Action::make('clients')->label('Clientes')->icon('heroicon-o-users')->color('gray')->url(LoanClients::getUrl())->visible(fn () => LoanClients::canAccess()),
            Action::make('objects')->label('Tipos de objeto')->icon('heroicon-o-archive-box')->color('gray')->url(LoanObjectTypes::getUrl())->visible(fn () => LoanObjectTypes::canAccess()),
            Action::make('settings')->label('Capital en Yape')->icon('heroicon-o-cog-6-tooth')->color('gray')->url(LoanSettings::getUrl())->visible(fn () => LoanSettings::canAccess()),
            Action::make('new')->label('Realizar préstamo')->icon('heroicon-o-plus')->url(RegisterLoan::getUrl())->visible(fn () => RegisterLoan::canAccess()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->recordAction('detail')->query(app(LoanQuery::class)->loans(auth()->user()))->columns([
            TextColumn::make('borrower_snapshot.name')->label('Cliente')->weight('medium')->color('primary')->wrap()->searchable(['borrower_snapshot->name', 'borrower_snapshot->ci'])->tooltip('Ver detalle del préstamo'),
            TextColumn::make('terms_snapshot.historical_import.reference')->label('Referencia anterior')->placeholder('Registro nuevo')->searchable(['terms_snapshot->historical_import->reference'])->badge()->color('warning'),
            TextColumn::make('object_snapshot.type_name')->label('Objeto')->tooltip(fn (Loan $record) => $record->object_snapshot['description'])->wrap(),
            TextColumn::make('collection_total')->label('Total para recoger')->state(fn (Loan $record) => app(LoanTermCalculator::class)->amountToCollect($record))->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state))->placeholder('Por revisar')->weight('semibold')->color(fn (Loan $record) => $record->displayStatus() === 'ACTIVO' ? 'success' : 'gray')->tooltip(fn (Loan $record) => match ($record->displayStatus()) {
                'CON_MULTA' => 'El total incluye el interés pendiente y la multa fija del contrato.',
                'PERDIDA_TOTAL' => 'El objeto no se recupera mediante un pago ordinario.',
                default => null,
            }),
            TextColumn::make('due_date')->label('Vencimiento')->date('d/m/Y')->sortable(),
            TextColumn::make('state')->label('Estado')->state(fn (Loan $record) => $record->displayStatus())->formatStateUsing(fn ($state) => match ($state) {
                'ACTIVO' => 'Activo', 'CON_MULTA' => 'Con multa', 'PERDIDA_TOTAL' => 'Pérdida total', 'VENDIDO' => 'Vendido', 'RECOGIDO_PAGADO' => 'Recogido y pagado', 'ANULADO' => 'Anulado', default => $state,
            })->badge()->color(fn ($state) => match ($state) {
                'ACTIVO', 'RECOGIDO_PAGADO', 'VENDIDO' => 'success', 'CON_MULTA' => 'warning', 'ANULADO' => 'gray', default => 'danger',
            }),
            TextColumn::make('cashier_snapshot.name')->label('Registrado por')->wrap(),
        ])->filters([
            SelectFilter::make('status')->label('Estado')->options(['ACTIVO' => 'Activos', 'CON_MULTA' => 'Con multa', 'PERDIDA_TOTAL' => 'Pérdida total', 'RECOGIDO_PAGADO' => 'Recogidos y pagados', 'ANULADO' => 'Anulados', 'VENDIDO' => 'Vendidos'])->query(fn ($query, array $data) => app(LoanQuery::class)->filterStatus($query, $data['value'] ?? null)),
        ])->recordActions([
            LoanDetailActions::make()->icon('heroicon-o-eye')->iconButton()->color('gray')->tooltip('Ver detalle del préstamo'),
            LoanRecoveryActions::expense()->iconButton()->tooltip('Registrar gasto de reparación'),
            LoanRecoveryActions::sale()->iconButton()->tooltip('Registrar venta del objeto perdido'),
            LoanSettlementActions::make()->iconButton()->tooltip('Registrar pago y entrega'),
            LoanTermActions::make('renew', 'RENEW')->iconButton()->tooltip('Renovar préstamo'),
            LoanAnnulmentActions::make()->iconButton()->tooltip(fn (Loan $record) => $record->latestTermChange ? 'Tiene pagos previos; requiere corrección administrativa' : 'Anular préstamo'),
        ])->paginated([5, 10, 25, 50])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')->emptyStateHeading('Todavía no hay préstamos en esta consulta');
    }
}
