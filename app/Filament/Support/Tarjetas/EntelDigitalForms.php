<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Tarjetas\Calculators\EntelFundingCalculator;
use App\Support\Bolivianos;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;

class EntelDigitalForms
{
    public static function funding(bool $initial): array
    {
        $fields = [];
        if (! $initial) {
            $fields[] = CardInventoryForms::money('paid_amount', 'Monto pagado a Entel')->helperText('Mínimo Bs 5000. Registra un pago ya realizado por Administración.')->live(debounce: 350);
        }
        $fields[] = CardInventoryForms::money('credited_amount', $initial ? 'Saldo real disponible' : 'Saldo acreditado por Entel')->helperText($initial ? 'Puedes registrar 0. No es una compra ni genera bonificación.' : 'Escribe el importe acreditado real, incluida la bonificación del negocio.')->live(debounce: 350);
        if (! $initial) {
            $fields[] = Text::make(function (Get $get): string {
                $preview = app(EntelFundingCalculator::class)->preview($get('paid_amount'), $get('credited_amount'));

                return $preview === null ? 'Completa los importes válidos para ver la bonificación real.' : 'Bonificación del negocio: Bs '.Bolivianos::formatCompact($preview['bonus_cents']).' · '.Bolivianos::format($preview['bonus_rate_bps']).' %. No es ganancia realizada ni el bono del cliente.';
            })->color('primary')->columnSpanFull();
        }
        $fields[] = TextInput::make('reference')->label('Referencia de la carga o saldo inicial (opcional)')->maxLength(200)->columnSpanFull();

        return [Section::make($initial ? 'Registro inicial único' : 'Carga ya acreditada')->columns(2)->schema($fields),
            Text::make('Crédito y Paquetes conservan libros separados. Este registro no descuenta efectivo de tienda ni realiza pagos bancarios. No copies aquí un saldo que ya estés contabilizando en otra caja.'), CardInventoryForms::key()];
    }
}
