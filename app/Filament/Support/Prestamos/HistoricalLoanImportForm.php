<?php

namespace App\Filament\Support\Prestamos;

use App\Domain\Prestamos\Models\LoanObjectType;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class HistoricalLoanImportForm
{
    public static function schema(): array
    {
        $final = fn (Get $get): bool => in_array($get('status'), ['RECOGIDO_PAGADO', 'ANULADO', 'VENDIDO'], true);

        return [
            Section::make('Referencia y cliente')->columns(2)->schema([
                TextInput::make('external_reference')->label('N.º de boleta o referencia del sistema anterior')->required()->maxLength(120)->columnSpanFull()->helperText('Debe identificar un único préstamo antiguo; no se puede importar dos veces.'),
                TextInput::make('ci')->label('CI original del cliente')->required()->maxLength(60),
                TextInput::make('name')->label('Nombre del cliente en el préstamo')->required()->maxLength(160),
                TextInput::make('phone')->label('Teléfono original / WhatsApp')->tel()->maxLength(40),
            ]),
            Section::make('Objeto y cajero original')->columns(2)->schema([
                Select::make('type_id')->label('Categoría del objeto')->required()->searchable()->optionsLimit(0)->extraFieldWrapperAttributes(['class' => 'fi-fixed-positioning-context'])
                    ->options(fn () => app(LoanQuery::class)->types(auth()->user())->orderBy('name')->get()->mapWithKeys(fn ($type): array => [$type->id => $type->name.($type->is_active ? '' : ' (inactivo)')])->all())
                    ->live()->afterStateUpdated(function ($state, Set $set): void {
                        $type = LoanObjectType::find($state);
                        $set('type_revision', $type?->revision);
                        $set('type_name', $type?->name);
                    }),
                Hidden::make('type_revision'),
                TextInput::make('type_name')->label('Tipo de objeto en el contrato antiguo')->required()->maxLength(120),
                Textarea::make('description')->label('Descripción original')->required()->rows(2)->maxLength(2000)->columnSpanFull(),
                TextInput::make('serial')->label('Serie / IMEI')->maxLength(120),
                Textarea::make('condition')->label('Estado físico al recibirlo')->maxLength(2000),
                Textarea::make('accessories')->label('Accesorios entregados')->maxLength(2000),
                Select::make('original_cashier_id')->label('Cajero original si tiene cuenta en este sistema')->searchable()->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->live()->afterStateUpdated(fn ($state, Set $set) => $set('original_cashier_name', User::find($state)?->name))
                    ->helperText('También admite cuentas inactivas. Si no existe, deja vacío y escribe su nombre; no se creará una cuenta.'),
                TextInput::make('original_cashier_name')->label('Nombre del cajero original')->required()->maxLength(255),
            ]),
            Section::make('Fechas e importes originales')->columns(2)->schema([
                DateTimePicker::make('occurred_at')->label('Fecha y hora originales del préstamo · Bolivia')->format('Y-m-d H:i:s')->seconds()->required()->native(),
                DatePicker::make('due_date')->label('Vencimiento original')->native()->required(),
                TextInput::make('capital')->label('Capital prestado')->prefix('Bs')->required()->inputMode('decimal'),
                TextInput::make('interest')->label('Interés original del periodo')->prefix('Bs')->required()->inputMode('decimal'),
                TextInput::make('rate')->label('Tasa original para futuras renovaciones')->suffix('% / 30 días')->required()->inputMode('decimal'),
                Select::make('delivery_method')->label('Medio de entrega original')->options(['CASH' => 'Efectivo', 'YAPE' => 'Yape'])->required(),
                Select::make('status')->label('Situación del préstamo antiguo')->options(['ACTIVO' => 'Pendiente: calcular estado según las fechas originales', 'PERDIDA_TOTAL' => 'Pérdida total según su vencimiento original', 'RECOGIDO_PAGADO' => 'Pagado y objeto devuelto', 'ANULADO' => 'Anulado con capital devuelto', 'VENDIDO' => 'Objeto vendido'])->required()->default('ACTIVO')->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if (in_array($state, ['ACTIVO', 'PERDIDA_TOTAL'], true)) {
                            $set('closed_at', null);
                            $set('received', null);
                            $set('closure_reason', null);

                        }
                        if (! in_array($state, ['PERDIDA_TOTAL', 'VENDIDO'], true)) {
                            $set('historical_expenses', '0');
                            $set('expenses_at', null);
                            $set('expenses_description', null);
                        }
                    })->helperText('Los pendientes aplican multa y pérdida según las reglas actuales desde su vencimiento original.'),
                DateTimePicker::make('closed_at')->label('Fecha y hora originales del pago / cierre · Bolivia')->format('Y-m-d H:i:s')->seconds()->native()->visible($final)->required($final),
                TextInput::make('closed_by_name')->label('Responsable original del pago o cierre, si consta')->maxLength(255)->visible($final)->helperText('Es independiente del cajero que otorgó el préstamo. Si no consta, deja vacío.'),
                TextInput::make('received')->label('Importe real cobrado o capital devuelto en el cierre antiguo')->prefix('Bs')->inputMode('decimal')->visible($final)->required($final),
                Textarea::make('closure_reason')->label('Observaciones del cierre y diferencias de importes')->maxLength(2000)->visible($final)
                    ->helperText('Si el cobro real difiere de capital + interés + multa actual, explica el motivo. Se conservará el importe efectivamente recibido.'),
                Textarea::make('reason')->label('Fuente de los datos / motivo de la carga')->required()->minLength(10)->maxLength(2000)->columnSpanFull(),
                Section::make('Gastos antiguos del objeto perdido o vendido')->collapsible()->collapsed()->columnSpanFull()
                    ->visible(fn (Get $get): bool => in_array($get('status'), ['PERDIDA_TOTAL', 'VENDIDO'], true))->schema([
                        TextInput::make('historical_expenses')->label('Gastos de reparación o recuperación ya pagados')->prefix('Bs')->default('0')->inputMode('decimal'),
                        DateTimePicker::make('expenses_at')->label('Fecha y hora originales del gasto')->format('Y-m-d H:i:s')->seconds()->native(),
                        Textarea::make('expenses_description')->label('Detalle y referencia de los gastos antiguos')->maxLength(500),
                    ]),
                LoanForms::key(),
            ]),
        ];
    }
}
