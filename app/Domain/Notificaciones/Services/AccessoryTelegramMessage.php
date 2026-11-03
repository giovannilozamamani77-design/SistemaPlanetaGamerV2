<?php

namespace App\Domain\Notificaciones\Services;

use App\Domain\Accesorios\Calculators\SaleDiscountCalculator;
use App\Domain\Accesorios\Models\AccessoryCashDeposit;
use App\Domain\Accesorios\Models\AccessoryCashDepositReversal;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Models\CommissionPaymentReversal;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;

class AccessoryTelegramMessage
{
    public function sale(AccessorySale $sale): string
    {
        $sale->loadMissing('lines');
        $rows = $sale->lines->sortBy(fn ($line) => $line->kind === 'PHONE' ? 0 : 1)->values()->map(fn ($line) => $line->only(['kind', 'product_name', 'quantity', 'unit_price_cents', 'total_cents']))->all();
        $amounts = app(SaleDiscountCalculator::class)->summary($rows);
        $lines = $this->header('Venta de teléfonos registrada', $sale->actor_name, $sale->created_at);
        $lines[] = 'Venta #'.$sale->id;
        $lines[] = 'Teléfonos: '.$sale->lines->where('kind', 'PHONE')->sum('quantity');
        $lines[] = 'Accesorios: '.$sale->lines->where('kind', 'ACCESSORY')->sum('quantity');
        $lines[] = '';
        $lines[] = '🛍 PRODUCTOS VENDIDOS';
        foreach (array_slice($rows, 0, 8) as $row) {
            $discount = app(SaleDiscountCalculator::class)->amounts($row)['discount_cents'];
            $lines[] = ($row['kind'] === 'PHONE' ? 'Teléfono' : 'Accesorio').' · '.$this->line($row['product_name'], 100).' × '.$row['quantity'].' · final '.$this->money($row['total_cents']).' · descuento '.$this->money($discount);
        }
        if (count($rows) > 8) {
            $lines[] = '… y '.(count($rows) - 8).' líneas más en el recibo; los totales incluyen toda la venta.';
        }
        $lines[] = '';
        $lines[] = '💰 TOTAL DE LA VENTA';
        $lines[] = 'Descuento total: '.$this->money($amounts['discount_cents']);
        $lines[] = 'Total cobrado: '.$this->money($sale->total_cents);
        $lines[] = 'Venta confirmada · Recibo y garantías registrados.';

        return $this->finish($lines, 'accesorios.recibo', ['sale' => $sale->id], 'Ver venta y recibo');
    }

    public function payment(CommissionPayment $payment): string
    {
        $lines = $this->header('Pago de comisiones registrado', $payment->registered_by_name, $payment->created_at);
        $lines[] = 'Pago #'.$payment->id;
        $lines[] = 'Beneficiaria: '.$this->line($payment->payee_name);
        $lines[] = '';
        $lines[] = '💵 PAGO Y PENDIENTE';
        $lines[] = 'Importe pagado: '.$this->money($payment->amount_cents);
        $lines[] = 'Pendiente tras este pago: '.$this->money($payment->after_pending_cents);
        $lines[] = 'Entrega externa de Administración; no se descontó de las cajas ni fondos del sistema.';
        $lines[] = 'No modifica las comisiones ganadas ni su historial.';

        return $this->finish($lines, 'filament.inicio.pages.commission-dashboard', [], 'Ver pagos de comisiones');
    }

    public function reversal(CommissionPaymentReversal $reversal): string
    {
        $reversal->loadMissing('payment');
        $lines = $this->header('Pago de comisiones anulado', $reversal->voided_by_name, $reversal->created_at);
        $lines[] = 'Anulación #'.$reversal->id.' · Pago #'.$reversal->payment_id;
        $lines[] = 'Beneficiaria: '.$this->line($reversal->payment->payee_name);
        $lines[] = '';
        $lines[] = '↩ ANULACIÓN Y RESTITUCIÓN';
        $lines[] = 'Importe restituido: '.$this->money($reversal->amount_cents);
        $lines[] = 'Pendiente tras esta anulación: '.$this->money($reversal->after_pending_cents);
        $lines[] = 'Motivo: '.$this->line($reversal->reason, 140);
        $lines[] = 'Corrige el registro de pago; conserva las comisiones ganadas y el historial.';
        $lines[] = 'No representa un cobro ni un movimiento de cajas o fondos del sistema.';

        return $this->finish($lines, 'filament.inicio.pages.commission-dashboard', [], 'Ver pagos y anulaciones');
    }

    public function deposit(AccessoryCashDeposit $deposit): string
    {
        $deposit->loadMissing('movement');
        $account = $deposit->destination_snapshot;
        $lines = $this->header('Depósito de efectivo registrado', $deposit->actor_name, $deposit->created_at);
        $lines[] = 'Depósito #'.$deposit->id;
        $lines[] = 'Sentido: Accesorios → Administración';
        $lines[] = '';
        $lines[] = '💸 DEPÓSITO A ADMINISTRACIÓN';
        $lines[] = 'Cuenta: '.$this->line($account['name']);
        $lines[] = 'Banco: '.$this->line($account['bank']);
        $lines[] = 'Importe depositado: '.$this->money($deposit->amount_cents);
        $lines[] = 'Operación: '.($deposit->operation_reference ? $this->line($deposit->operation_reference, 100) : 'Sin número de operación');
        $lines[] = 'Efectivo disponible posterior: '.$this->money($deposit->movement->after_cents);
        $lines[] = 'Depósito declarado; sin confirmación bancaria automática.';

        return $this->finish($lines, 'filament.inicio.pages.accessory-inventory', [], 'Ver historial de depósitos en Inventario');
    }

    public function depositReversal(AccessoryCashDepositReversal $reversal): string
    {
        $reversal->loadMissing(['deposit', 'movement']);
        $account = $reversal->deposit->destination_snapshot;
        $lines = $this->header('Depósito de efectivo anulado', $reversal->actor_name, $reversal->created_at);
        $lines[] = 'Anulación #'.$reversal->id.' · Depósito #'.$reversal->deposit_id;
        $lines[] = 'Depositó: '.$this->line($reversal->deposit->actor_name);
        $lines[] = '';
        $lines[] = '↩ RESTITUCIÓN AL EFECTIVO';
        $lines[] = 'Cuenta: '.$this->line($account['name']);
        $lines[] = 'Banco: '.$this->line($account['bank']);
        $lines[] = 'Importe restituido: '.$this->money($reversal->amount_cents);
        $lines[] = 'Efectivo disponible posterior: '.$this->money($reversal->movement->after_cents);
        $lines[] = 'Motivo: '.$this->line($reversal->reason, 140);
        $lines[] = 'Corrige el registro; no revierte una transferencia bancaria ni registra otro cobro.';

        return $this->finish($lines, 'filament.inicio.pages.accessory-inventory', [], 'Ver depósitos y anulaciones en Inventario');
    }

    private function header(string $title, string $actor, CarbonImmutable $at): array
    {
        $icon = match ($title) {
            'Venta de teléfonos registrada' => '📱', 'Pago de comisiones registrado' => '💵', 'Depósito de efectivo registrado' => '🏦', default => '🔴',
        };

        return [$icon.' ACCESORIOS · '.$title, 'Responsable: '.$this->line($actor), 'Fecha de operación: '.$at->timezone('America/La_Paz')->format('d/m/Y H:i').' · Bolivia'];
    }

    private function finish(array $lines, string $route, array $parameters, string $label): string
    {
        $root = rtrim((string) config('app.url'), ' /');
        $url = parse_url($root);
        if (strlen($root) <= 512 && filter_var($root, FILTER_VALIDATE_URL) && in_array($url['scheme'] ?? '', ['http', 'https'], true) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])) {
            $lines[] = $label.': '.$root.route($route, $parameters, false);
        }

        return implode("\n", $lines);
    }

    private function money(int $cents): string
    {
        return 'Bs '.Bolivianos::formatCompact($cents);
    }

    private function line(string $value, int $limit = 80): string
    {
        $value = preg_replace('~https?://\S+|tg://\S+|www\.\S+~iu', '[enlace omitido]', $value) ?? '';
        $value = preg_replace('/[\p{C}\s]+/u', ' ', $value) ?? '';

        return mb_substr(trim(str_replace('@', '', $value)), 0, $limit);
    }
}
