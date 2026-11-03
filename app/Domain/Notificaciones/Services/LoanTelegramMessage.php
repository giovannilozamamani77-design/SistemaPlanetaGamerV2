<?php

namespace App\Domain\Notificaciones\Services;

use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanSettlement;
use App\Domain\Prestamos\Models\LoanTermChange;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;

class LoanTelegramMessage
{
    public function registration(Loan $loan): string
    {
        $lines = $this->header('Préstamo registrado', $loan->id, $loan->borrower_snapshot, $loan->object_snapshot, $loan->cashier_snapshot['name'], $loan->created_at);
        $lines[] = '';
        $lines[] = '💰 IMPORTES DEL PRÉSTAMO';
        $lines[] = 'Capital: '.$this->money($loan->capital_cents);
        $lines[] = 'Interés: '.$this->money($loan->original_interest_cents);
        $lines[] = 'Total a pagar: '.$this->money($loan->original_total_cents);
        $lines[] = '';
        $lines[] = '📆 FECHAS Y PLAZO';
        $lines[] = 'Plazo registrado: '.$loan->original_days.' días';
        $lines[] = 'Inicio: '.$loan->start_date->format('d/m/Y');
        $lines[] = 'Vencimiento: '.$loan->original_due_date->format('d/m/Y');
        if (($loan->terms_snapshot['schedule_mode'] ?? 'AUTO') === 'MANUAL' || ($loan->terms_snapshot['interest_mode'] ?? 'AUTO') === 'MANUAL') {
            $lines[] = 'Fechas o interés manuales: importes del contrato guardado.';
        }
        $lines[] = 'Desembolso confirmado · Alta registrada.';

        return $this->finish($lines, $loan->id);
    }

    public function renewal(LoanTermChange $change): string
    {
        $s = $change->summary;
        $lines = $this->header($change->kind === 'EXTEND' ? 'Ampliación registrada' : 'Renovación registrada', $change->loan_id, $s['borrower'], $s['object'], $s['registered_by'], $change->created_at);
        $lines[] = 'Operación #'.$change->id.' · Mismo préstamo, sin nuevo desembolso.';
        $lines[] = '';
        $lines[] = '💰 IMPORTES DE LA OPERACIÓN';
        $lines[] = 'Capital conservado: '.$this->money((int) $s['capital_cents']);
        $lines[] = 'Interés cobrado: '.$this->money((int) $s['interest_paid_cents']);
        $lines[] = 'Multa cobrada: '.$this->money((int) $s['late_fee_cents']);
        $lines[] = 'Total cobrado: '.$this->money((int) $s['paid_cents']);
        $lines[] = '';
        $lines[] = '📆 PERIODO RENOVADO';
        $lines[] = 'Plazo seleccionado: '.$s['days'].' días · Periodos: '.$s['periods'];
        $lines[] = 'Días añadidos: '.$s['added_days'];
        $lines[] = 'Vencimiento anterior: '.$this->date($s['previous_due_date']);
        $lines[] = 'Nuevo vencimiento: '.$this->date($s['due_date']);
        $lines[] = 'Interés pendiente: '.$this->money((int) $s['pending_cents']);
        $lines[] = 'Fecha de pago indicada arriba; el periodo añadido parte del vencimiento anterior.';

        return $this->finish($lines, $change->loan_id);
    }

    public function settlement(LoanSettlement $settlement): string
    {
        $s = $settlement->summary;
        $lines = $this->header('Pago y entrega registrados', $settlement->loan_id, $s['borrower'], $s['object'], $s['registered_by'], $settlement->created_at);
        $lines[] = 'Operación #'.$settlement->id;
        $lines[] = '';
        $lines[] = '💰 PAGO CONFIRMADO';
        $lines[] = 'Capital cobrado: '.$this->money((int) $s['capital_cents']);
        $lines[] = 'Interés cobrado: '.$this->money((int) $s['interest_cents']);
        $lines[] = 'Multa cobrada: '.$this->money((int) $s['late_fee_cents']);
        $lines[] = 'Total cobrado: '.$this->money((int) $s['paid_cents']);
        $lines[] = 'Vencimiento del periodo pagado: '.$this->date($s['due_date']);
        $lines[] = 'Estado: Recogido y pagado · Objeto entregado.';

        return $this->finish($lines, $settlement->loan_id);
    }

    private function header(string $title, int $loanId, array $borrower, array $object, string $actor, CarbonImmutable $at): array
    {
        $icon = match ($title) {
            'Préstamo registrado' => '🟢',
            'Renovación registrada', 'Ampliación registrada' => '🔄',
            default => '✅',
        };

        return [$icon.' PRÉSTAMOS · '.$title, 'Préstamo #'.$loanId, 'Cliente: '.$this->line($borrower['name']), 'Objeto: '.$this->line($object['type_name']), 'Detalle: '.$this->line($object['description'], 140), 'Responsable: '.$this->line($actor), 'Fecha de operación: '.$at->timezone('America/La_Paz')->format('d/m/Y H:i').' · Bolivia'];
    }

    private function finish(array $lines, int $loanId): string
    {
        $root = rtrim((string) config('app.url'), ' /');
        $url = parse_url($root);
        if (strlen($root) <= 512 && filter_var($root, FILTER_VALIDATE_URL) && in_array($url['scheme'] ?? '', ['http', 'https'], true) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])) {
            $lines[] = 'Ver préstamo e historial: '.$root.route('filament.inicio.pages.prestamos.{loan}.detalle', ['loan' => $loanId], false);
        }

        return implode("\n", $lines);
    }

    private function date(string $date): string
    {
        return CarbonImmutable::parse($date, 'America/La_Paz')->format('d/m/Y');
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
