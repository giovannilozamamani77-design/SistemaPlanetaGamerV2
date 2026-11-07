<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Prestamos\Calculators\LoanCalculator;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\HistoricalLoanImport as ImportRecord;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanAnnulment;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanObjectExpense;
use App\Domain\Prestamos\Models\LoanObjectSale;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Domain\Prestamos\Models\LoanSettlement;
use App\Domain\Prestamos\Services\HistoricalLoanImport;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportHistoricalLoanAction
{
    public function execute(User $actor, array $data): Loan
    {
        $v = app(HistoricalLoanImport::class)->prepare($actor, $data, false);
        $confirmation = Validator::make($data, ['review_confirmed' => ['required', 'accepted'], 'expected_fund_revision' => ['required', 'integer', 'min:0'],
            'expected_collection_cents' => ['present', 'nullable', 'integer', 'min:0'], 'expected_display_status' => ['required', 'string']])->validate();
        $payload = $v;
        unset($payload['fund_before_cents'], $payload['fund_after_cents'], $payload['fund_revision'], $payload['display_status'], $payload['late_fee_cents'], $payload['current_collection_cents']);
        $payload['expected_fund_revision'] = (int) $confirmation['expected_fund_revision'];
        $payload['expected_display_status'] = $confirmation['expected_display_status'];
        $payload['expected_collection_cents'] = $confirmation['expected_collection_cents'] === null ? null : (int) $confirmation['expected_collection_cents'];
        try {
            $result = app(LoanCommandRunner::class)->execute($actor, 'importHistorical', 'HISTORICAL_IMPORT', $v['request_key'], $payload, function ($fund, $user) use ($v, $payload, $data): array {
                if ((int) $fund->revision !== $payload['expected_fund_revision']) {
                    throw ValidationException::withMessages(['capital' => 'El fondo cambió. Vuelve a revisar la importación y su saldo resultante.']);
                }
                $v = app(HistoricalLoanImport::class)->prepare($user, $data);
                if (ImportRecord::where('reference_key', $v['reference_key'])->exists()) {
                    throw ValidationException::withMessages(['external_reference' => 'Este préstamo del sistema anterior ya fue importado. Búscalo en el registro antes de repetirlo.']);
                }
                $type = LoanObjectType::whereKey($v['type_id'])->lockForUpdate()->firstOrFail();
                if ($v['current_collection_cents'] !== $payload['expected_collection_cents'] || $type->revision !== (int) $v['type_revision'] || app(LoanCalculator::class)->status($v['due_date'], $v['status']) !== $payload['expected_display_status']) {
                    throw ValidationException::withMessages(['status' => 'El catálogo o el estado por fecha cambió. Revisa nuevamente antes de confirmar.']);
                }
                $after = $fund->balance_cents + $v['fund_delta_cents'];
                if (! $fund->initialized_at || $after < 0 || $after > LoanInput::MAX_CENTS) {
                    throw ValidationException::withMessages(['capital' => 'El ajuste histórico excede el fondo disponible o su límite.']);
                }
                $borrower = Borrower::where('ci', $v['ci'])->lockForUpdate()->first();
                $client = ['ci' => $v['ci'], 'name' => $v['name'], 'phone' => $v['phone'] ?? null];
                $borrower ??= Borrower::create($client);
                $importInfo = ['reference' => $v['external_reference'], 'imported_by_id' => $user->id, 'imported_by' => $user->name,
                    'imported_at' => now()->toIso8601String(), 'original_cashier_id' => $v['original_cashier_id'], 'original_cashier_name' => $v['original_cashier_name'],
                    'expense_cents' => $v['expense_cents'], 'fund_delta_cents' => $v['fund_delta_cents'], 'received_cents' => $v['received_cents'], 'closed_at' => $v['closed_at'], 'closure_reason' => $v['difference_reason'], 'reason' => $v['reason']];
                $object = ['type_name' => $v['type_name'], 'type_revision' => $type->revision, 'description' => $v['description'],
                    'serial' => $v['serial'] ?? null, 'condition' => $v['condition'] ?? null, 'accessories' => $v['accessories'] ?? null];
                $loan = Loan::create(['borrower_id' => $borrower->id, 'object_type_id' => $type->id, 'user_id' => $v['original_cashier_id'] ?? $user->id,
                    'request_key' => $v['request_key'], 'borrower_snapshot' => $client, 'object_snapshot' => $object,
                    'account_snapshot' => $fund->only(['bank', 'number', 'holder', 'qr_path', 'qr_sha256', 'revision']),
                    'cashier_snapshot' => ['id' => $v['original_cashier_id'], 'name' => $v['original_cashier_name']],
                    'capital_cents' => $v['capital_cents'], 'original_rate_bps' => $v['rate_bps'], 'original_days' => $v['days'],
                    'original_interest_cents' => $v['interest_cents'], 'original_total_cents' => $v['capital_cents'] + $v['interest_cents'],
                    'start_date' => substr($v['occurred_at'], 0, 10), 'original_due_date' => $v['due_date'], 'due_date' => $v['due_date'],
                    'terms_snapshot' => ['schedule_mode' => 'MANUAL', 'interest_mode' => 'MANUAL', 'historical' => true, 'capital_debited' => true,
                        'receipt_terms' => config('prestamos.receipt'), 'historical_import' => $importInfo], 'status' => $v['status'],
                    'delivery_method' => $v['delivery_method'], 'created_at' => CarbonImmutable::parse($v['occurred_at'], 'America/La_Paz')->utc()]);
                if ($v['expense_cents'] > 0) {
                    LoanObjectExpense::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'amount_cents' => $v['expense_cents'],
                        'summary' => ['historical_import' => true, 'amount_cents' => $v['expense_cents'], 'description' => $v['expenses_description'], 'source' => 'HISTORICAL_IMPORT',
                            'registered_by' => $user->name.' (importación)', 'registered_at' => CarbonImmutable::parse($v['expenses_at'], 'America/La_Paz')->format('d/m/Y H:i')],
                        'created_at' => CarbonImmutable::parse($v['expenses_at'], 'America/La_Paz')->utc()]);
                }
                if ($v['closed_at']) {
                    $closed = CarbonImmutable::parse($v['closed_at'], 'America/La_Paz');
                    $base = ['historical_import' => true, 'historical_reference' => $v['external_reference'], 'imported_at' => $importInfo['imported_at'], 'imported_by' => $user->name,
                        'borrower' => $client, 'object' => $object, 'capital_cents' => $v['capital_cents'], 'interest_cents' => $v['interest_cents'],
                        'late_fee_cents' => $v['late_fee_cents'], 'days' => $v['days'], 'due_date' => $v['due_date'], 'reference' => $v['external_reference'],
                        'registered_by' => $v['closed_by_name'] ?: 'Responsable histórico no indicado', 'registered_at' => $closed->format('d/m/Y H:i'), 'status' => $v['status'],
                        'historical_difference_reason' => $v['difference_reason']];
                    $event = ['loan_id' => $loan->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'created_at' => $closed->utc()];
                    if ($v['status'] === 'RECOGIDO_PAGADO') {
                        $capitalRecovered = min($v['capital_cents'], $v['received_cents']);
                        $interestRecovered = min($v['interest_cents'], max(0, $v['received_cents'] - $capitalRecovered));
                        $feeRecovered = min($v['late_fee_cents'], max(0, $v['received_cents'] - $capitalRecovered - $interestRecovered));
                        LoanSettlement::create($event + ['summary' => array_replace($base, ['capital_cents' => $capitalRecovered, 'interest_cents' => $interestRecovered,
                            'late_fee_cents' => $feeRecovered, 'paid_cents' => $v['received_cents'], 'revision' => 0, 'payment_confirmed' => true, 'delivery_confirmed' => true,
                            'historical_extra_cents' => $v['received_cents'] - $capitalRecovered - $interestRecovered - $feeRecovered,
                            'historical_unrecovered_capital_cents' => $v['capital_cents'] - $capitalRecovered])]);
                    } elseif ($v['status'] === 'ANULADO') {
                        LoanAnnulment::create($event + ['summary' => $base + ['reason' => $v['reason'], 'previous_status' => 'ACTIVO', 'capital_returned' => true]]);
                    } elseif ($v['status'] === 'VENDIDO') {
                        $minimum = $v['capital_cents'] + $v['interest_cents'] + $v['late_fee_cents'] + $v['expense_cents'];
                        $realGain = $v['received_cents'] - $v['capital_cents'] - $v['expense_cents'];
                        $loanGain = min($v['interest_cents'] + $v['late_fee_cents'], max(0, $realGain));
                        LoanObjectSale::create($event + ['summary' => $base + ['expense_cents' => $v['expense_cents'], 'invested_cents' => $v['capital_cents'] + $v['expense_cents'], 'minimum_cents' => $minimum,
                            'price_cents' => $v['received_cents'], 'loan_profit_cents' => $loanGain,
                            'sale_profit_cents' => $realGain - $loanGain, 'total_profit_cents' => $realGain,
                            'buyer' => null, 'sale_notes' => $v['difference_reason'] ?: $v['reason']]]);
                    }
                }
                LoanMovement::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'kind' => 'HISTORICAL_IMPORT', 'delta_cents' => $v['fund_delta_cents'],
                    'before_cents' => $fund->balance_cents, 'after_cents' => $after, 'reference' => 'Anterior: '.$v['external_reference'], 'created_at' => now()]);
                $fund->update(['balance_cents' => $after, 'revision' => $fund->revision + 1]);
                ImportRecord::create(['loan_id' => $loan->id, 'imported_by' => $user->id, 'external_reference' => $v['external_reference'], 'reference_key' => $v['reference_key'],
                    'fund_delta_cents' => $v['fund_delta_cents'], 'snapshot' => $v, 'created_at' => now()]);

                return ['id' => $loan->id, 'fund_delta_cents' => $v['fund_delta_cents'], 'fund_before_cents' => $fund->balance_cents - $v['fund_delta_cents'], 'fund_after_cents' => $after];
            });
        } catch (UniqueConstraintViolationException $error) {
            if (str_contains($error->getMessage(), 'reference_key')) {
                throw ValidationException::withMessages(['external_reference' => 'La referencia anterior ya fue importada.']);
            }
            throw $error;
        }

        return Loan::findOrFail($result['id']);
    }
}
