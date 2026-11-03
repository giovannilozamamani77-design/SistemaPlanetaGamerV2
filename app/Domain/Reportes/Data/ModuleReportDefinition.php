<?php

namespace App\Domain\Reportes\Data;

use Illuminate\Validation\ValidationException;

class ModuleReportDefinition
{
    public const MODULES = [
        'BOXES' => ['label' => 'Cajas', 'sources' => ['BOX_FEES'], 'description' => 'Comisiones operativas cobradas. El dinero transferido y el capital de caja no son ganancias.', 'margin_label' => 'Comisiones operativas', 'note' => 'Antes de sueldos, gastos y pérdidas. Los faltantes pendientes no se descuentan como pérdidas confirmadas.'],
        'CARDS' => ['label' => 'Tarjetas', 'sources' => ['CARDS', 'CREDIT', 'PACKAGES'], 'description' => 'Tarjetas, Cabina Viva y recargas Entel. Venta menos el costo promedio conservado al vender.', 'margin_label' => 'Margen bruto conocido', 'note' => 'Los registros sin costo quedan pendientes; no se rellenan con precios actuales. Antes de gastos y comisiones. Bonos externos Entel excluidos.'],
        'LOANS' => ['label' => 'Préstamos', 'sources' => ['TERM_PAYMENT', 'SETTLEMENT', 'OBJECT_SALE'], 'description' => 'Intereses y multas realmente cobrados, y resultado de objetos vendidos. El capital recuperado se separa.', 'margin_label' => 'Resultado cobrado conocido', 'note' => 'No incluye intereses por cobrar, préstamos entregados ni aportes como ganancia. El resultado del objeto ya resta capital y reparación guardados. Antes de gastos generales y pérdidas no resueltas.'],
        'ACCESSORIES' => ['label' => 'Accesorios y teléfonos', 'sources' => ['ACCESSORY', 'PHONE'], 'description' => 'Ventas finales con descuentos menos el costo conservado. Comisiones de venta devengadas separadas.', 'margin_label' => 'Margen bruto conocido', 'note' => 'El pago de una comisión no vuelve a restarse: se muestra lo ganado por la cajera en la venta. Antes de gastos generales.'],
    ];

    public function get(string $module): array
    {
        if (! isset(self::MODULES[$module])) {
            throw ValidationException::withMessages(['module' => 'Selecciona un módulo de Reportes válido.']);
        }

        return self::MODULES[$module];
    }
}
