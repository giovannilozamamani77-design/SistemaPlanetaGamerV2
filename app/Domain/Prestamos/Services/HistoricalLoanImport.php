<?php

namespace App\Domain\Prestamos\Services;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Calculators\LoanCalculator;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HistoricalLoanImport
{
    public function prepare(User $actor, array $data, bool $checkCurrent = true): array
    {
        app(LoanAuthorization::class)->authorize($actor, 'importHistorical');
        $v = Validator::make($data, [
            'external_reference' => ['required', 'string', 'max:120'], 'ci' => ['required', 'string', 'max:60'], 'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'], 'type_id' => ['required', 'integer', Rule::exists('loan_object_types', 'id')],
            'type_revision' => ['required', 'integer', 'min:1'], 'type_name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:2000'], 'serial' => ['nullable', 'string', 'max:120'], 'condition' => ['nullable', 'string', 'max:2000'], 'accessories' => ['nullable', 'string', 'max:2000'],
            'original_cashier_id' => ['nullable', 'integer', Rule::exists('users', 'id')], 'original_cashier_name' => ['required', 'string', 'max:255'],
            'occurred_at' => ['required', 'date_format:Y-m-d H:i:s', 'after_or_equal:1970-01-01 00:00:00'], 'due_date' => ['required', 'date_format:Y-m-d'],
            'capital' => ['required'], 'interest' => ['required'], 'rate' => ['required'],
            'status' => ['required', Rule::in(['ACTIVO', 'PERDIDA_TOTAL', 'RECOGIDO_PAGADO', 'ANULADO', 'VENDIDO'])],
            'closed_at' => ['nullable', 'required_unless:status,ACTIVO,PERDIDA_TOTAL', 'date_format:Y-m-d H:i:s'],
            'closed_by_name' => ['nullable', 'string', 'max:255'], 'historical_expenses' => ['nullable'], 'expenses_at' => ['nullable', 'date_format:Y-m-d H:i:s'], 'expenses_description' => ['nullable', 'string', 'max:500'],
            'received' => ['nullable', 'required_unless:status,ACTIVO,PERDIDA_TOTAL'], 'closure_reason' => ['nullable', 'string', 'max:2000'],
            'delivery_method' => ['required', Rule::in(['CASH', 'YAPE'])], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'request_key' => ['required', 'uuid'],
        ])->validate();
        foreach (['external_reference', 'ci', 'name', 'type_name', 'description', 'original_cashier_name', 'reason'] as $field) {
            $v[$field] = trim($v[$field]);
            if ($v[$field] === '') {
                throw ValidationException::withMessages([$field => 'Este dato no puede estar vacío.']);
            }
        }
        if (mb_strlen($v['reason']) < 10) {
            throw ValidationException::withMessages(['reason' => 'Describe la fuente de los datos con al menos 10 caracteres, sin contar espacios exteriores.']);
        }
        $v['ci'] = LoanInput::normalize($v['ci']);
        $v['reference_key'] = hash('sha256', Str::lower(preg_replace('/\s+/u', ' ', $v['external_reference'])));
        if ($checkCurrent && \App\Domain\Prestamos\Models\HistoricalLoanImport::where('reference_key', $v['reference_key'])->exists()) {
            throw ValidationException::withMessages(['external_reference' => 'Esta referencia del sistema anterior ya fue importada. Búscala en el registro permanente.']);
        }
        $origin = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $v['occurred_at'], 'America/La_Paz');
        $due = CarbonImmutable::createFromFormat('!Y-m-d', $v['due_date'], 'America/La_Paz');
        if ($origin > now() || $due->toDateString() < $origin->toDateString()) {
            throw ValidationException::withMessages(['occurred_at' => 'La fecha original no puede ser futura ni posterior al vencimiento.']);
        }
        $v['capital_cents'] = LoanInput::money($v['capital'], 'capital');
        $v['interest_cents'] = LoanInput::money($v['interest'], 'interest', true);
        $v['rate_bps'] = LoanInput::money($v['rate'], 'rate');
        if ($v['rate_bps'] > 999999 || $v['capital_cents'] + $v['interest_cents'] > LoanInput::MAX_CENTS) {
            throw ValidationException::withMessages(['capital' => 'Revisa los importes y la tasa original; superan el límite permitido.']);
        }
        $v['days'] = (int) $origin->startOfDay()->diffInDays($due, false) + 1;
        $v['original_cashier_id'] = filled($v['original_cashier_id'] ?? null) ? (int) $v['original_cashier_id'] : null;
        $v['closed_at'] = null;
        $v['received_cents'] = 0;
        $v['late_fee_cents'] = 0;
        $v['difference_reason'] = trim($v['closure_reason'] ?? '');
        $final = in_array($v['status'], ['RECOGIDO_PAGADO', 'ANULADO', 'VENDIDO'], true);
        if ($final) {
            $closed = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $data['closed_at'], 'America/La_Paz');
            if ($closed < $origin || $closed > now()) {
                throw ValidationException::withMessages(['closed_at' => 'El cierre histórico debe estar entre la entrega original y el momento actual.']);
            }
            $v['closed_at'] = $closed->format('Y-m-d H:i:s');
            $v['received_cents'] = LoanInput::money($v['received'], 'received', $v['status'] === 'ANULADO');
            if ($v['received_cents'] > LoanInput::MAX_CENTS) {
                throw ValidationException::withMessages(['received' => 'El cobro supera el límite permitido.']);
            }
            $v['late_fee_cents'] = $v['status'] !== 'ANULADO' && $closed->toDateString() > $v['due_date'] ? (int) config('prestamos.receipt.late_fee_cents', 4000) : 0;
            $expected = $v['capital_cents'] + ($v['status'] === 'ANULADO' ? 0 : $v['interest_cents'] + $v['late_fee_cents']);
            if ($v['status'] === 'ANULADO' && $v['received_cents'] !== $v['capital_cents']) {
                throw ValidationException::withMessages(['received' => 'Un préstamo anulado debe conservar la devolución íntegra del capital. Si no se recuperó, no corresponde marcarlo anulado.']);
            }
            if ($checkCurrent && ($v['status'] === 'RECOGIDO_PAGADO' && $v['received_cents'] !== $expected || $v['status'] === 'VENDIDO' && $v['received_cents'] < $expected) && mb_strlen($v['difference_reason']) < 10) {
                throw ValidationException::withMessages(['closure_reason' => 'Explica por qué el importe histórico cobrado difiere del capital, interés y multa actuales. Se conservará el cobro real, sin inventar importes.']);
            }
        } elseif (filled($data['closed_at'] ?? null) || filled($data['received'] ?? null)) {
            throw ValidationException::withMessages(['status' => 'Un préstamo pendiente o perdido no puede incluir un cobro final.']);
        }
        $type = LoanObjectType::findOrFail($v['type_id']);
        if ($checkCurrent && $type->revision !== (int) $v['type_revision']) {
            throw ValidationException::withMessages(['type_id' => 'El catálogo cambió. Revisa la categoría nuevamente.']);
        }
        $v['closed_by_name'] = trim($v['closed_by_name'] ?? '');
        $v['expense_cents'] = LoanInput::money(filled($v['historical_expenses'] ?? null) ? $v['historical_expenses'] : '0', 'historical_expenses', true);
        $v['expenses_at'] = filled($v['expenses_at'] ?? null) ? $v['expenses_at'] : null;
        $v['expenses_description'] = trim($v['expenses_description'] ?? '');
        if ($v['status'] === 'PERDIDA_TOTAL' && app(LoanCalculator::class)->status($v['due_date']) !== 'PERDIDA_TOTAL') {
            throw ValidationException::withMessages(['status' => 'Por el vencimiento original aún no corresponde pérdida total. Selecciona pendiente para aplicar las reglas actuales.']);
        }
        if ($v['expense_cents'] > 0) {
            if (! in_array($v['status'], ['PERDIDA_TOTAL', 'VENDIDO'], true) || $v['expense_cents'] > LoanInput::MAX_CENTS || ! $v['expenses_at'] || $v['expenses_description'] === '') {
                throw ValidationException::withMessages(['historical_expenses' => 'Los gastos históricos requieren un objeto perdido o vendido, importe permitido, fecha original y descripción.']);
            }
            $expenseAt = CarbonImmutable::parse($v['expenses_at'], 'America/La_Paz');
            $end = $v['closed_at'] ? CarbonImmutable::parse($v['closed_at'], 'America/La_Paz') : now();
            if ($expenseAt < $origin || $expenseAt > $end) {
                throw ValidationException::withMessages(['expenses_at' => 'El gasto debe estar entre el préstamo y su venta, o el momento actual.']);
            }
            $minimum = $v['capital_cents'] + $v['interest_cents'] + $v['late_fee_cents'] + $v['expense_cents'];
            if ($checkCurrent && $v['status'] === 'VENDIDO' && $v['received_cents'] < $minimum && mb_strlen($v['difference_reason']) < 10) {
                throw ValidationException::withMessages(['closure_reason' => 'Explica la diferencia entre la venta antigua y el mínimo que incluye sus gastos.']);
            }
        }
        $v['fund_delta_cents'] = $v['received_cents'] - $v['capital_cents'] - $v['expense_cents'];
        if (abs($v['fund_delta_cents']) > LoanInput::MAX_CENTS) {
            throw ValidationException::withMessages(['capital' => 'El ajuste supera el límite permitido.']);
        }
        $fund = LoanFund::where('code', 'YAPE')->first();
        $after = (int) ($fund?->balance_cents ?? 0) + $v['fund_delta_cents'];
        if ($checkCurrent && (! $fund?->initialized_at || $after < 0 || $after > LoanInput::MAX_CENTS)) {
            throw ValidationException::withMessages(['capital' => 'El ajuste histórico no cabe en el fondo disponible. Revisa el saldo o registra primero el aporte real correspondiente.']);
        }
        $v['display_status'] = app(LoanCalculator::class)->status($v['due_date'], $v['status']);
        $v['current_collection_cents'] = match ($v['display_status']) {
            'ACTIVO' => $v['capital_cents'] + $v['interest_cents'],
            'CON_MULTA' => $v['capital_cents'] + $v['interest_cents'] + (int) config('prestamos.receipt.late_fee_cents', 4000),
            'RECOGIDO_PAGADO', 'ANULADO', 'VENDIDO' => 0,
            default => null,
        };
        $v['fund_before_cents'] = (int) ($fund?->balance_cents ?? 0);
        $v['fund_after_cents'] = $after;
        $v['fund_revision'] = (int) ($fund?->revision ?? 0);

        return $v;
    }
}
