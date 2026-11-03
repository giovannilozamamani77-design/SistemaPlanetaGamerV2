<?php

namespace App\Domain\Notificaciones\Data;

use Illuminate\Validation\ValidationException;

class TelegramEventCatalog
{
    public const TEMPLATE_VERSION = 1;

    private const EVENTS = ['CAJAS' => ['CJ_APERTURA' => ['title' => 'Apertura registrada',
        'description' => 'Cada nueva sesión confirmada; incluye diferencias de recepción.',
        'example' => 'Tablet Negra · Turno mañana
Total recibido: Bs 25000 · Faltante: Bs 0 · Sobrante: Bs 0',
        'priority' => 'Información'],
        'CJ_CIERRE' => ['title' => 'Cierre registrado',
            'description' => 'Incluye faltante y liquidación de la misma operación, sin otro aviso.',
            'example' => 'Tablet Negra · Esperado: Bs 25050 · Contado: Bs 25030
Faltante registrado: Bs 20 · Responsable: Lili
Capital conservado: Bs 25000 · Liquidación realizada: Bs 30
Estado: pendiente de autorización administrativa',
            'priority' => 'Según estado'],
        'CJ_LIQUIDACION' => ['title' => 'Liquidación posterior',
            'description' => 'Solo cambios posteriores no incluidos en el cierre.',
            'example' => 'Cierre #123 · Liquidación realizada: Bs 30
Destino: cuenta administrativa de ejemplo',
            'priority' => 'Según estado'],
        'CJ_INCIDENCIA' => ['title' => 'Incidencia económica independiente',
            'description' => 'Solo si no quedó incluida en apertura o cierre.',
            'example' => 'Faltante registrado: Bs 20 · Origen: cierre
Responsable: Lili · Deuda activa; no requiere confirmar el faltante',
            'priority' => 'Según estado'],
        'CJ_REPOSICION' => ['title' => 'Reposición individual',
            'description' => 'Reporte y posterior verificación o rechazo.',
            'example' => 'Reposición reportada: Bs 20 · Incidencia #123
Estado: por verificar · La deuda no se reduce hasta verificar',
            'priority' => 'Revisar / Información'],
        'CJ_REPOSICION_GLOBAL' => ['title' => 'Reposición global',
            'description' => 'Un aviso por reporte global, aunque incluya deudas de Tarjetas.',
            'example' => 'Reposición global #123 · Reportó: Lili
Cajas: Bs 50 · Tarjetas: Bs 30 · Total: Bs 80
Por verificar; no se reduce aún la deuda',
            'priority' => 'Revisar / Información'],
        'CJ_APELACION' => ['title' => 'Apelación de faltante',
            'description' => 'Presentación y resolución económica; excluye horarios.',
            'example' => 'Incidencia #123 · Faltante: Bs 20
Motivo de ejemplo: diferencia de conteo
Estado: apelación por revisar',
            'priority' => 'Revisar / Información'],
        'CJ_CORRECCION' => ['title' => 'Corrección de operación',
            'description' => 'Solicitud y decisión posterior sobre una operación.',
            'example' => 'Tablet Negra · Operación #123
Corrección solicitada: importe registrado incorrecto
Estado: pendiente de revisión',
            'priority' => 'Revisar / Información'],
        'CJ_FONDOS' => ['title' => 'Fondos y capital',
            'description' => 'Ingreso/retiro temporal o ajuste de capital confirmado.',
            'example' => 'Tablet Negra · Ingreso temporal: Bs 500
Capital operativo posterior: Bs 25500',
            'priority' => 'Información'],
        'CJ_ANULACION_INCIDENCIA' => ['title' => 'Resolución administrativa de faltante',
            'description' => 'Perdón, anulación o resolución definitiva registrada.',
            'example' => 'Incidencia #123 · Importe resuelto: Bs 20
Modalidad: perdón administrativo · Pendiente: Bs 0',
            'priority' => 'Información']],
        'PRESTAMOS' => ['PR_ALTA' => ['title' => 'Préstamo registrado',
            'description' => 'Alta y desembolso confirmados.',
            'example' => 'Préstamo #123 · Cliente de ejemplo · Garrafa
Capital: Bs 200 · Interés: Bs 30 · Total: Bs 230
Inicio: 29/10/2026 · Vencimiento: 27/11/2026',
            'priority' => 'Información'],
            'PR_RENOVACION' => ['title' => 'Renovación registrada',
                'description' => 'Mismo préstamo; un aviso por renovación, no otra alta.',
                'example' => 'Préstamo #123 · Cliente de ejemplo · Garrafa
Interés pagado: Bs 15 · Multa: Bs 0
Periodo añadido: 30/10/2026–13/11/2026
Nuevo vencimiento: 13/11/2026',
                'priority' => 'Información'],
            'PR_PAGO_ENTREGA' => ['title' => 'Pago y entrega registrados',
                'description' => 'Un mensaje por pago y entrega, sin repetir movimientos del fondo.',
                'example' => 'Préstamo #123 · Objeto entregado
Capital: Bs 200 · Interés: Bs 30 · Multa: Bs 0
Total cobrado: Bs 230',
                'priority' => 'Información'],
            'PR_ANULACION' => ['title' => 'Préstamo anulado',
                'description' => 'Anulación administrativa ya confirmada.',
                'example' => 'Préstamo #123 · Capital restituido al fondo: Bs 200
Estado: anulado',
                'priority' => 'Información'],
            'PR_VENCIMIENTO' => ['title' => 'Préstamo vencido',
                'description' => 'Una vez por periodo; detector de transición pendiente de integración.',
                'example' => 'Préstamo #123 · Venció: 28/10/2026
Capital e interés: Bs 230 · Multa contractual: Bs 40
Requiere seguimiento',
                'priority' => 'Revisar'],
            'PR_PERDIDA' => ['title' => 'Objeto declarado perdido',
                'description' => 'Cambio definitivo a perdido, no venta.',
                'example' => 'Préstamo #123 · Garrafa
Estado: perdido · Objeto pendiente de venta',
                'priority' => 'Revisar'],
            'PR_GASTO_OBJETO' => ['title' => 'Gasto del objeto registrado',
                'description' => 'Reparación o gasto confirmado del fondo.',
                'example' => 'Préstamo #123 · Reparación: Bs 25
Salida registrada de Yape Préstamos',
                'priority' => 'Información'],
            'PR_VENTA_OBJETO' => ['title' => 'Objeto perdido vendido',
                'description' => 'Venta y recuperación registradas.',
                'example' => 'Préstamo #123 · Precio de venta: Bs 300
Gastos registrados: Bs 25 · Recuperación: Bs 275',
                'priority' => 'Información'],
            'PR_FONDO' => ['title' => 'Capital del fondo modificado',
                'description' => 'Solo aportes/retiros administrativos independientes.',
                'example' => 'Aporte administrativo: Bs 500
Disponible posterior: Bs 1500',
                'priority' => 'Información']],
        'TARJETAS' => ['TJ_APERTURA' => ['title' => 'Apertura de Tarjetas',
            'description' => 'Conteo recibido; diferencias y responsabilidad anterior.',
            'example' => 'Efectivo esperado: Bs 700 · Contado: Bs 690
Faltante registrado: Bs 10 · Responsable anterior: Ana
Stock contado coincide · Sesión abierta',
            'priority' => 'Según estado'],
            'TJ_CIERRE' => ['title' => 'Cierre de Tarjetas',
                'description' => 'Conteo final con diferencias y reservas, sin duplicar incidencias.',
                'example' => 'Efectivo esperado: Bs 800 · Contado: Bs 800
Tigo Bs 10: esperado 100 · contado 99
Faltante registrado al responsable saliente: Lili',
                'priority' => 'Según estado'],
            'TJ_COMPRA' => ['title' => 'Compra de tarjetas',
                'description' => 'Un mensaje por compra, no por corte.',
                'example' => 'Viva · 20 de Bs 10 y 5 de Bs 20
Compra: Bs 279,45 · Efectivo posterior: Bs 720,55',
                'priority' => 'Información'],
            'TJ_RECEPCION' => ['title' => 'Tarjetas recibidas de Administración',
                'description' => 'Entrada de stock sin descontar efectivo.',
                'example' => 'Entel · 50 tarjetas de Bs 10
Recibió: Lili · No descuenta efectivo',
                'priority' => 'Información'],
            'TJ_EFECTIVO' => ['title' => 'Ingreso o depósito de efectivo',
                'description' => 'Movimientos confirmados con Administración.',
                'example' => 'Depósito a Administración: Bs 300
Cuenta: Banco de ejemplo · Operación: opcional
Efectivo posterior: Bs 400',
                'priority' => 'Información'],
            'TJ_VENTA_MAYORISTA' => ['title' => 'Venta mayorista registrada',
                'description' => 'Una venta mixta, un resumen por empresas.',
                'example' => 'Entel: 5 tarjetas de Bs 10 · Tigo: 5 de Bs 10
Total cobrado: Bs 94,50',
                'priority' => 'Información'],
            'TJ_RECARGA' => ['title' => 'Recarga Entel registrada',
                'description' => 'Crédito y paquetes separados en el detalle.',
                'example' => 'Entel · Crédito mayorista
Monto cobrado: Bs 100 · Bonificación al cliente: Bs 8
Total para el cliente: Bs 108
Saldo administrativo de crédito: Bs 900',
                'priority' => 'Información'],
            'TJ_CARGA_DIGITAL' => ['title' => 'Carga digital Entel',
                'description' => 'Saldo inicial o carga posterior confirmada.',
                'example' => 'Entel · Paquetes mayoristas
Carga: Bs 500 · Saldo administrativo: Bs 1000',
                'priority' => 'Información'],
            'TJ_RESERVA' => ['title' => 'Reserva de sobrante resuelta',
                'description' => 'El hallazgo va en el conteo; aviso independiente al resolver.',
                'example' => 'Reserva #123 · Sobrante retenido: Bs 20
Resolución administrativa registrada · Disponible liberado: Bs 20',
                'priority' => 'Información'],
            'TJ_INCIDENCIA' => ['title' => 'Incidencia económica independiente',
                'description' => 'No repetir una diferencia ya informada en el conteo.',
                'example' => 'Sesión #123 · Faltante: Bs 10
Responsable: Lili · Deuda automática activa',
                'priority' => 'Según estado'],
            'TJ_REPOSICION' => ['title' => 'Reposición individual de Tarjetas',
                'description' => 'Reporte y decisión, solo origen Tarjetas.',
                'example' => 'Incidencia de Tarjetas #123 · Reportado: Bs 10
Por verificar; deuda sin reducción todavía',
                'priority' => 'Revisar / Información'],
            'TJ_APELACION' => ['title' => 'Apelación económica de Tarjetas',
                'description' => 'Presentación o decisión posterior de faltante.',
                'example' => 'Incidencia #123 · Motivo de ejemplo: diferencia de conteo
Apelación por revisar',
                'priority' => 'Revisar / Información']],
        'ACCESORIOS' => ['AC_VENTA_TELEFONO' => ['title' => 'Venta de teléfono registrada',
            'description' => 'Una venta con teléfonos y accesorios, un mensaje.',
            'example' => 'Venta #123 · Samsung 6/128 GB · 1 equipo
Lista: Bs 1390 · Rebaja: Bs 20 · Total: Bs 1370
Recibo y garantía registrados',
            'priority' => 'Información'],
            'AC_RECEPCION' => ['title' => 'Productos recibidos',
                'description' => 'Alta con stock o recepción/compra confirmada; un lote, un aviso.',
                'example' => 'Cargador de ejemplo · 10 unidades
Costo total registrado: Bs 100',
                'priority' => 'Información'],
            'AC_STOCK_BAJO' => ['title' => 'Stock mínimo alcanzado',
                'description' => 'Solo accesorios con mínimo configurado; una vez por episodio.',
                'example' => 'Cargador de ejemplo · Disponible: 2 · Mínimo: 3
Requiere reposición',
                'priority' => 'Revisar'],
            'AC_EFECTIVO_DEPOSITO' => ['title' => 'Depósito de efectivo registrado',
                'description' => 'Salida de efectivo de Accesorios a Administración; un aviso por depósito confirmado.',
                'example' => 'Depósito #123 · Depositó: Lili
Cuenta: Administración · Banco: Banco de ejemplo
Importe depositado: Bs 40 · Operación: OP-EJEMPLO
Efectivo disponible posterior: Bs 60
Depósito declarado; sin confirmación bancaria automática.',
                'priority' => 'Información'],
            'AC_EFECTIVO_ANULACION' => ['title' => 'Depósito de efectivo anulado',
                'description' => 'Anulación administrativa; restituye el importe al efectivo actual de Accesorios.',
                'example' => 'Anulación #124 · Depósito #123 · Depositó: Lili
Importe restituido: Bs 40 · Efectivo disponible posterior: Bs 100
Motivo: Registro equivocado
Corrige el registro; no revierte una transferencia bancaria ni registra otro cobro.',
                'priority' => 'Información'],
            'AC_COMISION_PAGO' => ['title' => 'Pago de comisiones registrado',
                'description' => 'Pago parcial/total por Administración, sin descontar cajas.',
                'example' => 'Beneficiaria: Lili · Pagado: Bs 50 · Pendiente: Bs 20
Dinero entregado por Administración fuera de las cajas del sistema',
                'priority' => 'Información'],
            'AC_COMISION_ANULACION' => ['title' => 'Pago de comisiones anulado',
                'description' => 'Importe restituido al pendiente con historial conservado.',
                'example' => 'Pago #123 · Beneficiaria: Lili
Restituido al pendiente: Bs 50 · Pendiente: Bs 70
Motivo registrado: pago cargado por error',
                'priority' => 'Información']]];

    public function connected(string $module, string $code): bool
    {
        return in_array($code, match ($module) {
            'CAJAS' => ['CJ_APERTURA', 'CJ_CIERRE'],
            'PRESTAMOS' => ['PR_ALTA', 'PR_RENOVACION', 'PR_PAGO_ENTREGA'],
            'TARJETAS' => ['TJ_APERTURA', 'TJ_CIERRE', 'TJ_COMPRA', 'TJ_EFECTIVO'],
            'ACCESORIOS' => ['AC_VENTA_TELEFONO', 'AC_COMISION_PAGO', 'AC_COMISION_ANULACION', 'AC_EFECTIVO_DEPOSITO', 'AC_EFECTIVO_ANULACION'],
            default => [],
        }, true);
    }

    public function events(string $module): array
    {
        if (! isset(self::EVENTS[$module])) {
            throw ValidationException::withMessages(['module' => 'Selecciona un módulo válido.']);
        }

        return self::EVENTS[$module];
    }

    public function event(string $module, string $code): array
    {
        $events = $this->events($module);
        if (! isset($events[$code])) {
            throw ValidationException::withMessages(['event_code' => 'Este evento no pertenece al bot o aún no está disponible.']);
        }

        return $events[$code];
    }

    public function preview(string $module, string $code): array
    {
        $event = $this->event($module, $code);

        return $event + ['message' => "EJEMPLO · Datos ficticios · No se envía\n\n".$event['title']."\n".$event['example']."\n\nResponsable de ejemplo: Lili\n29/10/2026 14:00 · Bolivia\nVer registro en el sistema (enlace disponible al integrar el envío)", 'template_version' => self::TEMPLATE_VERSION];
    }
}
