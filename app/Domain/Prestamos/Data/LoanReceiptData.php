<?php

namespace App\Domain\Prestamos\Data;

use App\Support\Bolivianos;
use Carbon\CarbonImmutable;
use NumberFormatter;

class LoanReceiptData
{
    public function present(array $detail): array
    {
        $total = $detail['total_cents'];
        $formatter = new NumberFormatter('es_BO', NumberFormatter::SPELLOUT);
        $whole = intdiv($total, 100);
        $cents = $total % 100;
        $literal = $this->cardinal($formatter, $whole).' '.($whole === 1 ? 'boliviano' : 'bolivianos');
        if ($cents > 0) {
            $literal .= ' con '.$this->cardinal($formatter, $cents).' '.($cents === 1 ? 'centavo' : 'centavos');
        }

        $receiptTerms = $detail['terms_snapshot']['receipt_terms'] ?? config('prestamos.receipt');

        return $detail + [
            'receipt_terms' => $receiptTerms,
            'capital_receipt' => str_replace('.', ',', Bolivianos::format($detail['capital_cents'])),
            'interest_receipt' => str_replace('.', ',', Bolivianos::format($detail['interest_cents'])),
            'total_receipt' => str_replace('.', ',', Bolivianos::format($detail['total_cents'])),
            'late_fee_receipt' => str_replace('.', ',', Bolivianos::format($receiptTerms['late_fee_cents'])),
            'due_date_literal' => CarbonImmutable::parse($detail['due_date'], 'America/La_Paz')->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'phone_display' => preg_match('/\A[0-9]{8}\z/', $detail['borrower']['phone'] ?? '') ? '+591 '.$detail['borrower']['phone'] : ($detail['borrower']['phone'] ?? ''),
            'receipt_number' => (string) $detail['id'],
            'receipt_has_annex' => mb_strlen(implode(' ', array_map(fn ($key) => $detail['object'][$key] ?? '', ['description', 'serial', 'condition', 'accessories']))) > 650,
            'total_literal' => mb_strtoupper(mb_substr($literal, 0, 1)).mb_substr($literal, 1),
            'registration_date_literal' => CarbonImmutable::createFromFormat('d/m/Y H:i', $detail['created_at'], 'America/La_Paz')->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
        ];
    }

    private function cardinal(NumberFormatter $formatter, int $number): string
    {
        $words = $formatter->format($number);
        if ($words === false) {
            throw new \LogicException('No se pudo representar el importe registrado.');
        }

        return preg_replace(['/veintiuno$/u', '/\buno$/u'], ['veintiún', 'un'], $words);
    }
}
