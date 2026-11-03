<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardProduct;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CardWholesaleSelectionForm
{
    public static function values(): array
    {
        app(CardInventoryAuthorization::class)->authorize(auth()->user(), 'update');
        $products = CardProduct::where('is_active', true)->orderBy('company')->orderBy('face_value_cents')->get();
        $enabled = [];
        foreach (array_keys(CardInventoryInput::COMPANIES) as $company) {
            $enabled[$company] = $products->where('company', $company)->where('wholesale_enabled', true)->pluck('id')->all();
        }

        return ['enabled' => $enabled, 'revisions' => $products->pluck('revision', 'id')->all(), 'request_key' => (string) Str::uuid()];
    }

    public static function schema(): array
    {
        if (! Gate::allows('update', CardProduct::class)) {
            return [];
        }
        $products = CardProduct::where('is_active', true)->orderBy('face_value_cents')->get();
        $sections = [];
        foreach (CardInventoryInput::COMPANIES as $company => $label) {
            $sections[] = Section::make($label)->schema([CheckboxList::make('enabled.'.$company)->label('Cortes habilitados')->options($products->where('company', $company)->mapWithKeys(fn ($p) => [$p->id => $p->cutLabel()])->all())->default([])]);
        }

        return [Grid::make(3)->schema($sections), Hidden::make('revisions'), CardInventoryForms::key()];
    }
}
