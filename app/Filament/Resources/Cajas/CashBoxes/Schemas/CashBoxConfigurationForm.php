<?php

namespace App\Filament\Resources\Cajas\CashBoxes\Schemas;

use App\Domain\Cajas\Data\CashBoxAccountSettings;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Support\Bolivianos;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

class CashBoxConfigurationForm
{
    public static function fields(CashBox $box): array
    {
        $cards = [];
        foreach (CashBoxAccountSettings::defaults($box) as $component) {
            $code = $component['code'];
            $label = $component['name'];
            $cards[] = Section::make($label)->columns(['default' => 1, 'md' => 2])->columnSpanFull()->schema([
                Hidden::make("accounts.$code.category")->default($component['category']),
                TextInput::make("accounts.$code.reference")->label('Referencia / número de cuenta (opcional)')->maxLength(120),
                Toggle::make("accounts.$code.is_active")->label('Componente activo')->default(true)->helperText('No puede desactivarse si conserva saldo de entrega.'),
                TextInput::make("accounts.$code.name")->label('Nombre visible')->required()->maxLength(100),
                Hidden::make("accounts.$code.initial"),
            ]);
        }
        $cards[] = Section::make('Añadir componentes a esta caja')->description('Cada cuenta tiene un código estable, nombre y referencia propios. Todos los saldos forman parte del capital.')
            ->columnSpanFull()->schema([Repeater::make('additional_accounts')->label('Cuentas adicionales')->defaultItems(0)->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('code')->label('Código único del componente')->required()->maxLength(20)->helperText('Minúsculas y guion bajo. No cambia después de utilizarse.'),
                TextInput::make('name')->label('Nombre visible')->required()->maxLength(100),
                Select::make('category')->label('Tipo de saldo')->options(CashBoxAccountSettings::categories())->required(),
                TextInput::make('reference')->label('Referencia')->maxLength(120),
                Hidden::make('initial')->default(null),
            ])->addActionLabel('Añadir saldo')->itemLabel(fn (array $state): string => $state['name'] ?? 'Nuevo componente')]);
        foreach (['manana' => 'Mañana', 'tarde' => 'Tarde'] as $period => $label) {
            $cards[] = Section::make('Turno '.$label)->description('Horario de referencia para la apertura y asistencia.')->collapsed()->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextInput::make("shifts.$period.start")->label('Inicio (HH:MM)')->required(),
                TextInput::make("shifts.$period.end")->label('Fin (HH:MM)')->required(),
                Hidden::make("shifts.$period.liquidates"),
            ]);
        }
        $cards[] = Section::make('Capital y confirmación')->description('Los saldos reales se cuentan al abrir. Tu sesión administrativa autoriza esta configuración, sin volver a pedir PIN.')->columnSpanFull()->schema([
            Hidden::make('settlement_source'),
            TextInput::make('base_capital')->label('Capital base')->prefix('Bs')->inputMode('decimal')->required(),
            Textarea::make('reason')->label('Motivo')->required()->minLength(3)->maxLength(2000),
            Hidden::make('expected_revision'), Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
        ]);

        return $cards;
    }

    public static function values(CashBox $box): array
    {
        $version = CashBoxConfiguration::where('cash_box_id', $box->id)->latest('id')->first();
        $accounts = [];
        foreach (CashBoxAccountSettings::defaults($box) as $component) {
            $code = $component['code'];
            $label = $component['name'];
            $old = $version?->accounts[$code] ?? null;
            $accounts[$code] = ['name' => $old['name'] ?? $label, 'category' => $component['category'], 'reference' => $old['reference'] ?? $component['reference'],
                'is_active' => $component['is_active'], 'initial' => $old && ! $version->first_count_pending ? Bolivianos::format($old['initial_cents']) : null];
        }

        return ['request_key' => (string) Str::uuid(), 'reason' => $version ? null : 'Configuración inicial de la caja', 'accounts' => $accounts, 'settlement_source' => $version?->settlement_source ?? ($box->family() === 'BCP' ? 'bcp' : 'yape'), 'shifts' => $version?->shifts ?? CashBoxComponents::defaultShifts(),
            'base_capital' => Bolivianos::format($box->base_capital_cents), 'expected_revision' => $box->revision];
    }
}
