<?php

namespace App\Filament\Resources\Liquidaciones\History;

use App\Domain\Cajas\Models\CashBoxLiquidation;
use App\Domain\Liquidaciones\Queries\LiquidationHistoryQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LiquidationHistoryResource extends Resource
{
    protected static ?string $model = CashBoxLiquidation::class;

    protected static ?string $pluralModelLabel = 'Historial de liquidaciones';

    protected static ?string $navigationLabel = 'Historial de liquidaciones';

    protected static string|\UnitEnum|null $navigationGroup = 'Cajas';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'historial-liquidaciones';

    public static function canViewAny(): bool
    {
        return (auth()->user()?->hasRole('administrador') ?? false) && (auth()->user()?->can('cajas.operar') ?? false) && (auth()->user()?->can('cajas.liberar') ?? false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LiquidationHistoryQuery::class)->forUser(auth()->user());
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Registro')->prefix('#')->sortable(),
            TextColumn::make('box.name')->label('Caja')->weight('bold')->searchable(),
            TextColumn::make('user.name')->label('Cajera')->searchable(),
            TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->sortable(),
            TextColumn::make('close.period')->label('Turno')->formatStateUsing(fn (string $state): string => $state === 'manana' ? 'Mañana' : 'Tarde')->badge(),
            TextColumn::make('amount_cents')->label('Liquidado')->weight('bold')->color('success')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::format((int) $state)),
            TextColumn::make('source')->label('Origen')->formatStateUsing(fn (string $state): string => match ($state) {
                'bcp' => 'Cuenta BCP', 'yape' => 'Yape operativo', default => $state
            }),
            TextColumn::make('destination')->label('Cuenta de destino'),
        ])->filters([
            SelectFilter::make('cash_box_id')->label('Caja')->relationship('box', 'name'),
            SelectFilter::make('user_id')->label('Cajera')->relationship('user', 'name')->visible(fn (): bool => auth()->user()->hasRole('administrador') && auth()->user()->can('cajas.liberar')),
            SelectFilter::make('period')->label('Turno')->options(['manana' => 'Mañana', 'tarde' => 'Tarde'])->query(fn (Builder $query, array $data): Builder => empty($data['value']) ? $query : $query->whereHas('close', fn (Builder $q): Builder => $q->where('period', $data['value']))),
            Filter::make('dates')->label('Fechas')->schema([DatePicker::make('from')->label('Desde'), DatePicker::make('until')->label('Hasta')])
                ->query(fn (Builder $query, array $data): Builder => app(LiquidationHistoryQuery::class)->dates($query, $data)),
        ], layout: FiltersLayout::Dropdown)->filtersFormColumns(2)->filtersFormWidth('xl')->paginated([5, 10, 25, 50])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')->recordUrl(null)
            ->emptyStateHeading('Todavía no hay liquidaciones registradas')->emptyStateDescription('Aquí aparecerán las transferencias confirmadas, con su caja, cajera y turno.')
            ->recordActions([Action::make('detail')->label('Ver detalle')->icon('heroicon-o-eye')->iconButton()->tooltip('Ver liquidación')->modalHeading('Detalle de liquidación')->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                ->modalContent(fn (CashBoxLiquidation $record) => view('liquidaciones.detail', ['record' => $record]))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLiquidations::route('/')];
    }
}
