<?php

namespace App\Filament\Pages\Comisiones;

use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Filament\Support\Comisiones\CommissionPaymentActions;
use App\Filament\Widgets\Comisiones\AdminCommissionHistoryWidget;
use App\Filament\Widgets\Comisiones\CommissionPaymentsWidget;
use App\Models\User;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class CommissionDashboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'comisiones.dashboard';

    protected static ?string $title = 'Comisiones por cajera';

    protected static string|\UnitEnum|null $navigationGroup = 'Comisiones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        return auth()->check() && app(AdminCommissionsQuery::class)->canView(auth()->user());
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        app(AdminCommissionsQuery::class)->authorize(User::findOrFail(auth()->id()));
    }

    public function overview(): array
    {
        return app(AdminCommissionsQuery::class)->overview(User::findOrFail(auth()->id()));
    }

    #[On('commission-payments-updated')]
    public function refreshCommissionBalances(): void
    {
        app(AdminCommissionsQuery::class)->authorize(auth()->user());
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        return $table->heading('Acumulado por cajera')->query(app(AdminCommissionsQuery::class)->cashiers(User::findOrFail(auth()->id())))
            ->queryStringIdentifier('commissionCashiers')->paginated([5, 15, 25])->defaultPaginationPageOption(5)->defaultSort('total_cents', 'desc')->emptyStateHeading('Sin cajeras registradas')
            ->columns([
                TextColumn::make('name')->label('Cajera / responsable')->searchable()->wrap(),
                TextColumn::make('accessory_cents')->label('Accesorios')->color('warning')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('phone_cents')->label('Teléfonos')->color('info')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('total_cents')->label('Ganado')->weight('bold')->color('success')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('deducted_cents')->label('Descontado')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('paid_cents')->label('Pagado')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('pending_cents')->label('Pendiente')->weight('bold')->color('warning')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('is_active')->label('Cuenta')->badge()->formatStateUsing(fn (bool $state) => $state ? 'Activa' : 'Desactivada')->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])->recordActions([
                CommissionPaymentActions::pay(),
                Action::make('payments')->label('Ver pagos')->icon('heroicon-o-receipt-refund')->modalHeading(fn (User $record) => 'Pagos de '.$record->name)->modalWidth(Width::FiveExtraLarge)->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->schema(fn (User $record) => [Livewire::make(CommissionPaymentsWidget::class, ['userId' => $record->id])->key('commission-payments-'.$record->id)]),
                Action::make('history')->label('Ver ventas')->icon('heroicon-o-clock')->modalHeading(fn (User $record) => 'Comisiones de '.$record->name)->modalWidth(Width::SevenExtraLarge)->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->schema(fn (User $record) => [Livewire::make(AdminCommissionHistoryWidget::class, ['userId' => $record->id])->key('commission-history-'.$record->id)]),
            ]);
    }
}
