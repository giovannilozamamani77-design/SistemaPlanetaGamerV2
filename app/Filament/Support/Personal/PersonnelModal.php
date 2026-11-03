<?php

namespace App\Filament\Support\Personal;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

class PersonnelModal
{
    /** Presentation only: sections have no state path, so action payloads stay unchanged. */
    public static function fields(array $fields): array
    {
        $hidden = [];
        $data = [];
        $confirmation = [];
        foreach ($fields as $field) {
            if ($field instanceof Hidden) {
                $hidden[] = $field;
            } elseif ($field instanceof Textarea || $field instanceof Toggle) {
                $confirmation[] = $field->columnSpanFull();
            } else {
                $data[] = $field;
            }
        }

        $cards = $hidden;
        if ($data !== []) {
            $cards[] = Section::make('Datos de la operación')->schema($data)
                ->columns(['default' => 1, 'md' => 2])->columnSpanFull();
        }
        if ($confirmation !== []) {
            $cards[] = Section::make('Motivo y comprobaciones')
                ->description('Revisa la información y deja una constancia clara antes de confirmar.')
                ->schema($confirmation)->columns(1)->columnSpanFull();
        }

        return $cards;
    }
}
