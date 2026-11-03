<?php

namespace App\Filament\Widgets\Comisiones;

use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Models\User;
use App\Support\Bolivianos;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;

class AdminCommissionHistoryWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    #[Locked]
    public int $userId = 0;

    public function mount(int $userId): void
    {
        app(AdminCommissionsQuery::class)->authorize(User::findOrFail(auth()->id()));
        $this->userId = User::findOrFail($userId)->id;
    }

    public function table(Table $table): Table
    {
        return $table->heading('Ventas de origen')->query(app(AdminCommissionsQuery::class)->history(User::findOrFail(auth()->id()), $this->userId))
            ->queryStringIdentifier('adminCommissionHistory')->paginated([5, 15, 25])->defaultPaginationPageOption(5)->defaultSort('commission_entries.id', 'desc')->emptyStateHeading('Sin comisiones registradas')
            ->columns([
                TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('sale_id')->label('Venta')->formatStateUsing(fn ($state) => '#'.$state),
                TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn (string $state) => $state === 'PHONE' ? 'Teléfono' : 'Accesorio')->badge()->color(fn (string $state) => $state === 'PHONE' ? 'info' : 'warning')->icon(fn (string $state) => $state === 'PHONE' ? 'heroicon-o-device-phone-mobile' : 'heroicon-o-cube'),
                TextColumn::make('product_name')->label('Producto')->wrap(),
                TextColumn::make('quantity')->label('Cantidad'),
                TextColumn::make('total_cents')->label('Cobrado')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('amount_cents')->label('Comisión')->weight('bold')->color('success')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
            ])->filters([
                SelectFilter::make('kind')->label('Tipo de venta')->options(['ACCESSORY' => 'Accesorios', 'PHONE' => 'Teléfonos'])->query(fn (Builder $query, array $data) => in_array($data['value'] ?? null, ['ACCESSORY', 'PHONE'], true) ? $query->where('commission_line.kind', $data['value']) : $query),
            ]);
    }
}
