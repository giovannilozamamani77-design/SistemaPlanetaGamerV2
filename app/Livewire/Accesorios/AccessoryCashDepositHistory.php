<?php

namespace App\Livewire\Accesorios;

use App\Domain\Accesorios\Actions\VoidAccessoryCashDepositAction;
use App\Domain\Accesorios\Models\AccessoryCashDeposit;
use App\Domain\Accesorios\Queries\AccessoryCashDepositQuery;
use App\Filament\Support\Accesorios\AccessoryCashDepositForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Component;

class AccessoryCashDepositHistory extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions,InteractsWithSchemas,InteractsWithTable;

    protected ?array $historyOptions = null;

    protected function historyFilterOptions(): array
    {
        return $this->historyOptions ??= app(AccessoryCashDepositQuery::class)->historyFilterOptions(auth()->user());
    }

    public function mount(): void
    {
        app(AccessoryCashDepositQuery::class)->authorize(auth()->user(), 'viewAny');
    }

    public function table(Table $table): Table
    {
        return $table->query(app(AccessoryCashDepositQuery::class)->history(auth()->user()))->columns([
            TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i', timezone: 'America/La_Paz')->icon('heroicon-o-clock')->color('info'),
            TextColumn::make('actor_name')->label('Depositó')->searchable()->icon('heroicon-o-user'),
            TextColumn::make('destination_snapshot.name')->label('Cuenta')->description(fn (AccessoryCashDeposit $r) => $r->destination_snapshot['bank'].' · '.$r->destination_snapshot['number']),
            TextColumn::make('amount_cents')->label('Monto · Bs')->formatStateUsing(fn ($state) => Bolivianos::formatCompact((int) $state))->color('success')->weight('bold'),
            TextColumn::make('operation_reference')->label('Operación')->placeholder('Sin número')->searchable(),
            TextColumn::make('status')->label('Estado')->state(fn (AccessoryCashDeposit $r) => $r->reversal ? 'Anulado' : 'Registrado')->badge()->color(fn (AccessoryCashDeposit $r) => $r->reversal ? 'danger' : 'success'),
        ])->filters([
            Filter::make('history')->label('Buscar depósitos')->schema([
                DatePicker::make('from')->label('Desde')->native(),
                DatePicker::make('until')->label('Hasta')->native()->afterOrEqual('from'),
                Select::make('user_id')->label('Cajera')->options(fn () => $this->historyFilterOptions()['users'])->placeholder('Todas')->searchable(),
                Select::make('account_id')->label('Cuenta')->options(fn () => $this->historyFilterOptions()['accounts'])->placeholder('Todas')->searchable(),
            ])->query(fn (Builder $query, array $data) => app(AccessoryCashDepositQuery::class)->filterHistory($query, $data))
                ->indicateUsing(fn (array $data) => array_values(array_filter([
                    filled($data['from'] ?? null) ? 'Desde: '.$data['from'] : null,
                    filled($data['until'] ?? null) ? 'Hasta: '.$data['until'] : null,
                    $this->historyFilterOptions()['users'][$data['user_id'] ?? ''] ?? null,
                    $this->historyFilterOptions()['accounts'][$data['account_id'] ?? ''] ?? null,
                ]))),
        ])->filtersFormColumns(2)->filtersTriggerAction(fn (Action $action) => $action->label('Filtrar depósitos')->button()->size('sm'))->recordActions([
            Action::make('detail')->label('Detalle')->icon('heroicon-o-eye')->iconButton()->tooltip('Ver depósito y anulación')->modalSubmitAction(false)->modalContent(function (AccessoryCashDeposit $record) {
                $d = app(AccessoryCashDepositQuery::class)->history(auth()->user())->findOrFail($record->id);

                return view('accesorios.deposit-detail', ['deposit' => $d]);
            }),
            Action::make('voidDeposit')->label('Anular depósito')->icon('heroicon-o-x-circle')->color('danger')->iconButton()->tooltip('Anular depósito y devolver el efectivo')->visible(fn (AccessoryCashDeposit $record) => ! $record->reversal && app(AccessoryCashDepositQuery::class)->canVoid(auth()->user()))
                ->modalHeading('Anular depósito de efectivo')->modalDescription('Solo anula un registro equivocado. El importe vuelve al efectivo de Accesorios y las operaciones posteriores se conservan. No revierte una transferencia bancaria real.')
                ->fillForm(fn () => ['expected_revision' => app(AccessoryCashDepositQuery::class)->quote(auth()->user())['expected_revision'], 'request_key' => (string) Str::uuid(), 'confirmed' => false])
                ->schema([Hidden::make('request_key'), Hidden::make('expected_revision'), Textarea::make('reason')->label('Motivo de anulación')->required()->maxLength(300), Toggle::make('confirmed')->label('Confirmo que corresponde devolver este importe al efectivo disponible')->accepted()->required()])
                ->action(function (AccessoryCashDeposit $record, array $data): void {
                    AccessoryCashDepositForms::run($this, function () use ($record, $data): void {
                        app(VoidAccessoryCashDepositAction::class)->execute(auth()->user(), array_replace($data, ['deposit_id' => $record->id]));
                        Notification::make()->title('Depósito anulado; efectivo restituido')->success()->send();
                    });
                }),
        ])->defaultSort('id', 'desc')->paginated([5, 10, 25])->defaultPaginationPageOption(5)->emptyStateHeading('Sin depósitos registrados');
    }

    public function render(): View
    {
        app(AccessoryCashDepositQuery::class)->authorize(auth()->user(), 'viewAny');

        return view('accesorios.stock-entry-history');
    }
}
