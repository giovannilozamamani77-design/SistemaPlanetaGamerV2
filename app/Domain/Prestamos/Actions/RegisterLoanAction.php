<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Notificaciones\Actions\RecordLoanTelegramNoticeAction;
use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Calculators\LoanRegistrationTerms;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegisterLoanAction
{
    public function execute(User $actor, array $data): Loan
    {
        app(LoanAuthorization::class)->authorize($actor, 'create');
        $v = Validator::make($data, ['ci' => ['required', 'string', 'max:60'], 'name' => ['required', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'regex:/\A[0-9]{8}\z/'], 'type_id' => ['required', 'integer', 'min:1'], 'type_revision' => ['required', 'integer', 'min:1'], 'description' => ['required', 'string', 'max:2000'], 'condition' => ['nullable', 'string', 'max:2000'], 'accessories' => ['nullable', 'string', 'max:2000'], 'serial' => ['nullable', 'string', 'max:120'], 'capital' => ['required'], 'days' => ['exclude_if:schedule_mode,MANUAL', 'required', 'integer', 'between:1,30'], 'schedule_mode' => ['sometimes', Rule::in(['AUTO', 'MANUAL'])], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'interest_mode' => ['sometimes', Rule::in(['AUTO', 'MANUAL'])], 'manual_interest' => ['sometimes', 'nullable', 'string'], 'start_date' => ['required', 'date_format:Y-m-d'], 'delivery_method' => ['sometimes', Rule::in(['CASH', 'YAPE'])], 'delivery_confirmed' => ['sometimes', 'accepted'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['ci' => LoanInput::normalize($v['ci']), 'name' => trim($v['name']), 'phone' => trim($v['phone'] ?? ''), 'type_id' => (int) $v['type_id'], 'type_revision' => (int) $v['type_revision'], 'description' => trim($v['description']), 'condition' => trim($v['condition'] ?? ''), 'accessories' => trim($v['accessories'] ?? ''), 'serial' => trim($v['serial'] ?? ''), 'capital_cents' => LoanInput::money($v['capital'], 'capital'), 'days' => (int) ($v['days'] ?? 0), 'start_date' => $v['start_date'], 'delivery_method' => $v['delivery_method'] ?? 'CASH'];
        if (($v['schedule_mode'] ?? 'AUTO') === 'MANUAL' || ($v['interest_mode'] ?? 'AUTO') === 'MANUAL') {
            app(LoanAuthorization::class)->authorize($actor, 'manualTerms');
            $payload += ['schedule_mode' => $v['schedule_mode'] ?? 'AUTO', 'due_date' => $v['due_date'] ?? null, 'interest_mode' => $v['interest_mode'] ?? 'AUTO', 'manual_interest' => isset($v['manual_interest']) ? (string) $v['manual_interest'] : null];
        }
        $result = app(LoanCommandRunner::class)->execute($actor, 'create', 'REGISTER', $v['request_key'], $payload, function ($fund, $user) use ($payload, $v): array {
            if (! $fund->initialized_at || $fund->balance_cents < $payload['capital_cents']) {
                throw ValidationException::withMessages(['capital' => 'Capital insuficiente. Solicita a Administración que deposite y registre el aporte en Yape Préstamos.']);
            }
            $today = CarbonImmutable::now('America/La_Paz')->toDateString();
            if ($payload['start_date'] > $today || ($payload['start_date'] !== $today && ! $user->hasRole('administrador'))) {
                throw ValidationException::withMessages(['start_date' => 'Usa la fecha de hoy; un registro anterior requiere Administración.']);
            }
            $type = LoanObjectType::whereKey($payload['type_id'])->lockForUpdate()->firstOrFail();
            if (! $type->is_active || $type->revision !== $payload['type_revision']) {
                throw ValidationException::withMessages(['type_id' => 'El tipo o su tarifa cambió. Selecciónalo nuevamente antes de confirmar.']);
            }
            if (($payload['schedule_mode'] ?? 'AUTO') === 'MANUAL' || ($payload['interest_mode'] ?? 'AUTO') === 'MANUAL') {
                app(LoanAuthorization::class)->authorize($user, 'manualTerms');
            }
            $terms = app(LoanRegistrationTerms::class)->calculate(['capital' => $v['capital']] + $payload, $type->rate_bps);
            $borrower = Borrower::where('ci', $payload['ci'])->lockForUpdate()->first();
            $client = ['ci' => $payload['ci'], 'name' => $payload['name'], 'phone' => $payload['phone'] ?: null];
            $before = $borrower?->toArray();
            if ($borrower) {
                $borrower->update($client);
            } else {
                $borrower = Borrower::create($client);
            }
            $account = $fund->only(['bank', 'number', 'holder', 'qr_path', 'qr_sha256', 'revision']);
            $loan = Loan::create(['borrower_id' => $borrower->id, 'object_type_id' => $type->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'borrower_snapshot' => $client, 'object_snapshot' => ['type_name' => $type->name, 'type_revision' => $type->revision] + array_intersect_key($payload, array_flip(['description', 'condition', 'accessories', 'serial'])), 'account_snapshot' => $account, 'cashier_snapshot' => ['id' => $user->id, 'name' => $user->name], 'capital_cents' => $terms['capital_cents'], 'original_rate_bps' => $terms['rate_bps'], 'original_days' => $terms['days'], 'original_interest_cents' => $terms['interest_cents'], 'original_total_cents' => $terms['total_cents'], 'start_date' => $terms['start_date'], 'original_due_date' => $terms['due_date'], 'due_date' => $terms['due_date'], 'terms_snapshot' => ['schedule_mode' => $terms['schedule_mode'], 'interest_mode' => $terms['interest_mode'], 'historical' => $terms['start_date'] < $today, 'capital_debited' => true, 'receipt_terms' => config('prestamos.receipt')], 'status' => 'ACTIVO', 'delivery_method' => $payload['delivery_method'], 'created_at' => now()]);
            $after = $fund->balance_cents - $terms['capital_cents'];
            LoanMovement::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'kind' => 'DISBURSEMENT', 'delta_cents' => -$terms['capital_cents'], 'before_cents' => $fund->balance_cents, 'after_cents' => $after, 'created_at' => now()]);
            $fund->update(['balance_cents' => $after, 'revision' => $fund->revision + 1]);

            app(RecordLoanTelegramNoticeAction::class)->registration($loan->id);

            return ['id' => $loan->id, 'borrower_before' => $before, 'borrower_after' => $client];
        });

        return Loan::findOrFail($result['id']);
    }
}
