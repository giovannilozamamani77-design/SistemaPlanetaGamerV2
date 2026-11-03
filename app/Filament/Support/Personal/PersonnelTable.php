<?php

namespace App\Filament\Support\Personal;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class PersonnelTable
{
    public static function configure(Table $table, string $heading, string $description): Table
    {
        return $table->heading($heading)->description($description)->extraAttributes(['class' => 'pg-personnel-table'])
            ->paginated([5])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')
            ->recordUrl(null)->recordAction('detalle')->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'md' => 2, 'xl' => 3])
            ->emptyStateHeading('No hay registros para mostrar')
            ->emptyStateDescription('Prueba con otra cajera, estado o rango de fechas. El historial se conserva.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList);
    }

    public static function employee(): SelectFilter
    {
        return SelectFilter::make('user_id')->label('Cajera')->relationship('user', 'name')->searchable();
    }

    public static function dates(string $column, bool $dateOnly = false, ?string $relationship = null): Filter
    {
        return Filter::make('fechas')->label('Fechas')->schema([
            DatePicker::make('desde')->label('Desde'),
            DatePicker::make('hasta')->label('Hasta')->afterOrEqual('desde'),
        ])->columns(['default' => 1, 'sm' => 2])->columnSpanFull()->query(function (Builder $query, array $data) use ($column, $dateOnly, $relationship): Builder {
            if (! filled($data['desde'] ?? null) && ! filled($data['hasta'] ?? null)) {
                return $query;
            }

            return $relationship
                ? $query->whereHas($relationship, fn (Builder $related): Builder => self::applyDates($related, $data, $column, $dateOnly))
                : self::applyDates($query, $data, $column, $dateOnly);
        })->indicateUsing(fn (array $data): ?string => filled($data['desde'] ?? null) || filled($data['hasta'] ?? null)
            ? 'Fechas: '.($data['desde'] ?? 'sin inicio').' a '.($data['hasta'] ?? 'sin fin') : null);
    }

    private static function applyDates(Builder $query, array $data, string $column, bool $dateOnly): Builder
    {
        if (filled($data['desde'] ?? null)) {
            $from = CarbonImmutable::parse($data['desde'], 'America/La_Paz')->startOfDay();
            $query->where($column, '>=', $dateOnly ? $from->toDateString() : $from->utc());
        }
        if (filled($data['hasta'] ?? null)) {
            $until = CarbonImmutable::parse($data['hasta'], 'America/La_Paz')->startOfDay()->addDay();
            $query->where($column, '<', $dateOnly ? $until->toDateString() : $until->utc());
        }

        return $query;
    }

    public static function modal(Action $action): Action
    {
        if ($action->getName() !== 'detalle') {
            if (! ($action instanceof CreateAction) && ! ($action instanceof EditAction)) {
                $action->successNotificationTitle('Registro guardado');
            }
            $action->after(fn (Component $livewire) => $livewire->dispatch('personnel-record-updated'));
        }

        return $action->modal()->modalWidth('4xl')->extraModalWindowAttributes(['class' => 'pg-personnel-modal'])->modalSubmitActionLabel(fn (Action $action): string => (string) $action->getLabel())->stickyModalHeader()->stickyModalFooter()->modalCancelActionLabel($action->getName() === 'resumenPersonal' ? 'Cerrar' : 'Cancelar');
    }

    public static function resolve(array $actions, string $label = 'Resolver'): ActionGroup
    {
        foreach ($actions as $action) {
            self::modal($action);
        }

        return ActionGroup::make($actions)->label($label)->button()->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('primary');
    }
}
