<?php

namespace App\Domain\Notificaciones\Services;

use App\Domain\Tarjetas\Calculators\CardStoreCountSummary;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Models\User;
use App\Support\Bolivianos;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class CardTelegramMessage
{
    public function count(CardStoreCount $count, string $actor): string
    {
        $summary = app(CardStoreCountSummary::class)->make($count->cash_expected_cents, $count->cash_counted_cents, $count->cash_reserved_counted_cents, $count->cash_available_counted_cents, $count->shortage_cents, $count->lines);
        $cash = $summary['cash'];
        $stock = $summary['stock'];
        $lines = $this->header($count->kind === 'OPEN' ? 'Apertura registrada' : 'Cierre registrado', $actor, $count->created_at);
        $lines[] = 'Conteo #'.$count->id.' · Sesión #'.$count->card_store_session_id;
        $lines[] = '';
        $lines[] = '💵 EFECTIVO';
        $lines[] = 'Efectivo esperado: '.$this->money($cash['expected']);
        $lines[] = 'Efectivo contado: '.$this->money($cash['counted']);
        $lines[] = 'Faltante de efectivo: '.$this->money($cash['missing']);
        $lines[] = 'Sobrante de efectivo: '.$this->money($cash['surplus']);
        $lines[] = 'Efectivo reservado: '.$this->money($cash['reserved']);
        $lines[] = 'Efectivo disponible: '.$this->money($cash['available']);
        $lines[] = '';
        $lines[] = '🎟 STOCK DE TARJETAS';
        $lines[] = 'Tarjetas esperadas: '.$stock['expected'].' · Contadas: '.$stock['counted'];
        $lines[] = 'Tarjetas faltantes: '.$stock['missing'].' · Sobrantes: '.$stock['surplus'];
        $lines[] = 'Tarjetas reservadas: '.$stock['reserved'].' · Disponibles: '.$stock['available'];
        $lines[] = '';
        $lines[] = '📋 REVISIÓN DEL CONTEO';
        $lines[] = 'Faltante total registrado: '.$this->money($count->shortage_cents);
        if ($count->shortage_cents > 0 && $count->responsible_user_id) {
            $owner = User::findOrFail($count->responsible_user_id);
            $lines[] = ($count->kind === 'OPEN' ? 'Responsable anterior/inicial: ' : 'Responsable del turno: ').$this->line($owner->name);
            $lines[] = 'Deuda automática registrada; no requiere confirmar el faltante.';
        }
        $differences = [];
        foreach ($summary['companies'] as $group) {
            foreach ($group['lines'] as $row) {
                if ($row['missing'] || $row['surplus']) {
                    $differences[] = $this->line($group['company']).' · '.$this->line($row['label'], 60).' · esperado '.$row['expected'].' · contado '.$row['counted'].' · faltan '.$row['missing'].' · sobran '.$row['surplus'];
                }
            }
        }
        $lines = [...$lines, ...$this->detailLines($differences)];
        if (! $differences) {
            $lines[] = 'Sin diferencias de stock.';
        }
        if ($cash['reserved'] || $stock['reserved']) {
            $lines[] = 'Revisar reservas: sobrantes retenidos según su registro. No compensa faltantes ni aumenta el disponible.';
        }
        $lines[] = 'Estado confirmado: '.($count->kind === 'OPEN' ? 'Sesión abierta' : 'Sesión cerrada');

        return $this->finish($lines, 'filament.inicio.pages.card-store-count-history', 'Ver historial de conteos');
    }

    public function purchase(CardInventoryCommand $command, Collection $movements, string $actor): string
    {
        $lines = $this->header('Compra registrada', $actor, $command->created_at);
        $lines[] = 'Compra #'.$command->id;
        $lines[] = '';
        $lines[] = '📦 COMPRA RECIBIDA';
        $lines[] = 'Empresa: '.$this->line($movements->first()->product_snapshot['company']);
        $lines[] = 'Tarjetas compradas: '.$movements->sum('quantity');
        $details = [];
        foreach ($movements as $movement) {
            $product = new CardProduct($movement->product_snapshot);
            $details[] = $this->line($product->company).' · '.$product->cutLabel().' × '.$movement->quantity.' · pagado '.$this->money($movement->total_cost_cents);
        }
        $lines = [...$lines, ...$this->detailLines($details)];
        $lines[] = '';
        $lines[] = '💰 PAGO Y DISPONIBLE';
        $lines[] = 'Total pagado: '.$this->money((int) $movements->sum('total_cost_cents'));
        $lines[] = 'Efectivo disponible posterior: '.$this->money($movements->last()->cash_after_cents);
        $lines[] = 'Stock recibido y compra pagada registrados.';

        return $this->finish($lines, 'filament.inicio.pages.inventory-movements', 'Ver movimientos de tienda');
    }

    public function deposit(CardInventoryMovement $movement, string $actor): string
    {
        $account = $movement->destination_snapshot;
        $lines = $this->header('Depósito a Administración registrado', $actor, $movement->created_at);
        $lines[] = 'Movimiento #'.$movement->id;
        $lines[] = 'Sentido: tienda → Administración';
        $lines[] = '';
        $lines[] = '💸 DEPÓSITO A ADMINISTRACIÓN';
        $lines[] = 'Importe depositado: '.$this->money(-$movement->cash_delta_cents);
        $lines[] = 'Cuenta: '.$this->line($account['name']);
        $lines[] = 'Banco: '.$this->line($account['bank']);
        $lines[] = 'Operación: '.($movement->reference ? $this->line($movement->reference) : 'Sin número de operación');
        $lines[] = 'Efectivo disponible posterior: '.$this->money($movement->cash_after_cents);
        $lines[] = 'Depósito declarado en el sistema; sin confirmación bancaria automática.';

        return $this->finish($lines, 'filament.inicio.pages.card-cash-deposits', 'Ver depósitos a Administración');
    }

    private function header(string $title, string $actor, CarbonInterface $at): array
    {
        $icon = match ($title) {
            'Apertura registrada' => '🟢', 'Cierre registrado' => '🔴', 'Compra registrada' => '🛒', default => '🏦',
        };

        return [$icon.' TARJETAS · '.$title, 'Responsable: '.$this->line($actor), 'Fecha de operación: '.$at->copy()->timezone('America/La_Paz')->format('d/m/Y H:i').' · Bolivia'];
    }

    private function detailLines(array $details): array
    {
        $visible = array_slice($details, 0, 8);
        if (count($details) > 8) {
            $visible[] = '… y '.(count($details) - 8).' cortes más en el historial; los totales incluyen todos los cortes.';
        }

        return $visible;
    }

    private function finish(array $lines, string $route, string $label): string
    {
        $root = rtrim((string) config('app.url'), ' /');
        $url = parse_url($root);
        if (strlen($root) <= 512 && filter_var($root, FILTER_VALIDATE_URL) && in_array($url['scheme'] ?? '', ['http', 'https'], true) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])) {
            $lines[] = $label.': '.$root.route($route, [], false);
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
