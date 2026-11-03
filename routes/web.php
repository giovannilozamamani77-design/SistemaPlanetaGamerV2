<?php

use App\Http\Controllers\Accesorios\AccessoryDepositQrController;
use App\Http\Controllers\Accesorios\AccessoryProductImageController;
use App\Http\Controllers\Accesorios\AccessoryReceiptController;
use App\Http\Controllers\Accesorios\PhoneImageController;
use App\Http\Controllers\Envios\TransferReceiptController;
use App\Http\Controllers\Liquidaciones\LiquidationAccountQrController;
use App\Http\Controllers\Prestamos\LoanQrController;
use App\Http\Controllers\Prestamos\LoanReceiptController;
use App\Http\Controllers\Prestamos\LoanReceiptPdfController;
use App\Http\Controllers\Prestamos\LoanTermReceiptController;
use App\Http\Controllers\Prestamos\LoanTermReceiptPdfController;
use App\Http\Controllers\Reportes\ModuleReportExportController;
use App\Http\Controllers\Reportes\MonthlyReportExportController;
use App\Http\Controllers\Reportes\ReportRecordsExportController;
use App\Http\Controllers\Reportes\ReportSummaryCsvController;
use App\Http\Controllers\Reportes\ReportSummaryExcelController;
use App\Http\Controllers\Reportes\ReportSummaryPdfController;
use App\Http\Controllers\Tarjetas\CardDepositQrController;
use App\Http\Controllers\Usuarios\OwnProfilePhotoController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/inicio/mi-foto', OwnProfilePhotoController::class)
    ->middleware(Authenticate::class)
    ->name('perfil.foto');

Route::get('/inicio/envios/comprobantes/{event}', TransferReceiptController::class)
    ->whereNumber('event')->middleware(Authenticate::class)->name('envios.comprobante');

Route::get('/inicio/liquidaciones/cuentas/{account}/qr', LiquidationAccountQrController::class)
    ->whereNumber('account')->middleware(Authenticate::class)->name('liquidaciones.cuenta-qr');

Route::get('/inicio/tarjetas/depositos/cuentas/{account}/qr', CardDepositQrController::class)->whereNumber('account')->middleware(Authenticate::class)->name('tarjetas.deposito-qr');

Route::get('/inicio/prestamos/yape/qr', LoanQrController::class)->middleware(Authenticate::class)->name('prestamos.qr');
Route::get('/inicio/prestamos/comprobantes/{loan}', LoanReceiptController::class)->whereNumber('loan')->middleware(Authenticate::class)->name('prestamos.comprobante');
Route::get('/inicio/prestamos/comprobantes/{loan}/pdf', LoanReceiptPdfController::class)->whereNumber('loan')->middleware(Authenticate::class)->name('prestamos.recibo-pdf');

Route::get('/inicio/prestamos/renovaciones/{change}/comprobante', LoanTermReceiptController::class)->whereNumber('change')->middleware(Authenticate::class)->name('prestamos.renovacion-comprobante');
Route::get('/inicio/prestamos/renovaciones/{change}/pdf', LoanTermReceiptPdfController::class)->whereNumber('change')->middleware(Authenticate::class)->name('prestamos.renovacion-pdf');

Route::get('/inicio/accesorios/ventas/{sale}/recibo', AccessoryReceiptController::class)
    ->whereNumber('sale')->middleware(Authenticate::class)->name('accesorios.recibo');
Route::get('/inicio/accesorios/ventas/{sale}/recibo.pdf', AccessoryReceiptController::class)
    ->whereNumber('sale')->middleware(Authenticate::class)->name('accesorios.recibo-pdf');

Route::get('/inicio/accesorios/productos/{product}/imagen', [AccessoryProductImageController::class, 'product'])->whereNumber('product')->middleware(Authenticate::class)->name('accesorios.producto-imagen');
Route::get('/inicio/accesorios/ventas/lineas/{line}/imagen', [AccessoryProductImageController::class, 'line'])->whereNumber('line')->middleware(Authenticate::class)->name('accesorios.venta-imagen');

Route::get('/inicio/accesorios/telefonos/{phone}/imagen', PhoneImageController::class)->whereNumber('phone')->middleware(Authenticate::class)->name('accesorios.telefono-imagen');

Route::get('/inicio/accesorios/depositos/cuentas/{account}/qr', AccessoryDepositQrController::class)->whereNumber('account')->middleware(Authenticate::class)->name('accesorios.deposito-qr');

Route::get('/inicio/reportes/resumen.pdf', ReportSummaryPdfController::class)->middleware(Authenticate::class)->name('reportes.resumen-pdf');

Route::get('/inicio/reportes/resumen.xlsx', ReportSummaryExcelController::class)->middleware(Authenticate::class)->name('reportes.resumen-excel');

Route::get('/inicio/reportes/resumen.csv', ReportSummaryCsvController::class)->middleware(Authenticate::class)->name('reportes.resumen-csv');

Route::get('/inicio/reportes/cierres/{close}/{format}', MonthlyReportExportController::class)->whereNumber('close')->whereIn('format', ['pdf', 'xlsx', 'csv'])->middleware(Authenticate::class)->name('reportes.cierre-export');

Route::get('/inicio/reportes/cierres/{close}/ajustes/{adjustment}/{format}', [MonthlyReportExportController::class, 'adjusted'])->whereNumber('close')->whereNumber('adjustment')->whereIn('format', ['pdf', 'xlsx', 'csv'])->middleware(Authenticate::class)->name('reportes.cierre-ajuste-export');

Route::get('/inicio/reportes/detalle.{format}', ReportRecordsExportController::class)->whereIn('format', ['xlsx', 'csv'])->middleware(Authenticate::class)->name('reportes.detalle-export');

Route::get('/inicio/reportes/cierres/{close}/detalle.{format}', [MonthlyReportExportController::class, 'evidence'])->whereNumber('close')->whereIn('format', ['xlsx', 'csv'])->middleware(Authenticate::class)->name('reportes.cierre-detalle-export');
Route::get('/inicio/reportes/cierres/{close}/ajustes/{adjustment}/detalle.{format}', [MonthlyReportExportController::class, 'adjustedEvidence'])->whereNumber('close')->whereNumber('adjustment')->whereIn('format', ['xlsx', 'csv'])->middleware(Authenticate::class)->name('reportes.cierre-ajuste-detalle-export');

Route::get('/inicio/reportes/modulos/{module}/exportar.{format}', ModuleReportExportController::class)->whereIn('module', ['BOXES', 'CARDS', 'LOANS', 'ACCESSORIES'])->whereIn('format', ['pdf', 'xlsx'])->middleware(Authenticate::class)->name('reportes.modulo-export');
