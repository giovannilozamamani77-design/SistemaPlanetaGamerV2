<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\Comisiones\Queries\OwnCommissionsQuery;
use App\Models\User;
use App\Support\Bolivianos;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class OwnCommissionsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected $listeners = ['commission-payments-updated' => '$refresh'];

    protected string $view = 'usuarios.own-commissions';

    public function overview(): array
    {
        return app(OwnCommissionsQuery::class)->overview(User::findOrFail(auth()->id()));
    }

    public function table(Table $table): Table
    {
        return $table->heading('Ventas que generaron mis comisiones')->query(app(OwnCommissionsQuery::class)->history(User::findOrFail(auth()->id())))
            ->queryStringIdentifier('ownCommissions')->paginated([5, 15, 25])->defaultPaginationPageOption(5)->defaultSort('commission_entries.id', 'desc')
            ->emptyStateHeading('Todavía no tienes comisiones registradas')->emptyStateDescription('Aquí aparecerán las nuevas ventas de accesorios y teléfonos con su comisión guardada.')
            ->columns([
                TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('sale_id')->label('Venta')->formatStateUsing(fn ($state) => '#'.$state),
                TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn (string $state) => $state === 'PHONE' ? 'Teléfono' : 'Accesorio')->badge()->color(fn (string $state) => $state === 'PHONE' ? 'info' : 'warning')->icon(fn (string $state) => $state === 'PHONE' ? 'heroicon-o-device-phone-mobile' : 'heroicon-o-cube'),
                TextColumn::make('product_name')->label('Producto')->wrap(),
                TextColumn::make('quantity')->label('Cantidad'),
                TextColumn::make('total_cents')->label('Cobrado')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('amount_cents')->label('Mi comisión')->color('success')->weight('bold')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
            ])->filters([
                SelectFilter::make('kind')->label('Tipo de venta')->options(['ACCESSORY' => 'Accesorios', 'PHONE' => 'Teléfonos'])->query(fn (Builder $query, array $data) => in_array($data['value'] ?? null, ['ACCESSORY', 'PHONE'], true) ? $query->where('commission_line.kind', $data['value']) : $query),
            ]);
    }
}
