<?php

namespace App\Domain\Reportes\Actions;

use App\Domain\Reportes\Models\MonthlyReportAdjustment;
use App\Domain\Reportes\Queries\MonthlyReportAdjustmentQuery;
use App\Domain\Reportes\Queries\MonthlyReportCloseQuery;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class ConfirmMonthlyReportAdjustmentAction
{
    public function execute(User $actor, array $input, #[SensitiveParameter] string $pin): MonthlyReportAdjustment
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        $data = Validator::make($input, [
            'close_id' => ['required', 'integer', 'min:1'], 'base_sequence' => ['required', 'integer', 'min:0'],
            'request_uuid' => ['required', 'uuid'], 'preview_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', 'max:1000'], 'accept_pending' => ['required', 'boolean'],
        ])->validate();
        $data['reason'] = trim($data['reason']);
        if (mb_strlen($data['reason']) < 3) {
            throw ValidationException::withMessages(['reason' => 'Explica el motivo del ajuste.']);
        }
        $data['close_id'] = (int) $data['close_id'];
        $data['base_sequence'] = (int) $data['base_sequence'];
        $data['accept_pending'] = (bool) $data['accept_pending'];
        app(MonthlyReportCloseQuery::class)->detail($actor, $data['close_id']);
        $hash = hash('sha256', json_encode([$actor->id, $data], JSON_THROW_ON_ERROR));
        $retry = app(RetryReportConfirmationAction::class);
        $pinAuditId = $retry->execute(fn (): int => app(VerifyPersonalPinAction::class)->verifyAndReturnAudit($actor, $pin));
        try {
            return $retry->execute(fn (): MonthlyReportAdjustment => DB::transaction(function () use ($actor, $data, $hash, $pinAuditId): MonthlyReportAdjustment {
                Gate::forUser($actor)->authorize('viewDailyReports');
                if ($existing = $this->existing($data, $hash)) {
                    return $existing;
                }
                $quote = app(MonthlyReportAdjustmentQuery::class)->preview($actor, $data['close_id']);
                if ($quote['base_sequence'] !== $data['base_sequence']) {
                    throw ValidationException::withMessages(['adjustment' => 'Ya existe otra versión. Revisa los cambios nuevamente.']);
                }
                if ($quote['changes'] === []) {
                    throw ValidationException::withMessages(['adjustment' => 'No hay cambios pendientes en los registros de origen.']);
                }
                if (! hash_equals($quote['preview_hash'], $data['preview_hash'])) {
                    throw ValidationException::withMessages(['adjustment' => 'Los datos cambiaron. Revisa los cambios antes de confirmar.']);
                }
                $pending = count($quote['snapshot']['pending']);
                if ($pending > 0 && ! $data['accept_pending']) {
                    throw ValidationException::withMessages(['adjustmentPending' => 'Acepta la advertencia de pendientes del resultado ajustado.']);
                }
                Gate::forUser($actor)->authorize('viewDailyReports');

                return MonthlyReportAdjustment::create([
                    'monthly_close_id' => $data['close_id'], 'sequence' => $quote['base_sequence'] + 1, 'previous_adjustment_id' => $quote['previous_id'],
                    'request_uuid' => $data['request_uuid'], 'request_hash' => $hash, 'preview_hash' => $data['preview_hash'],
                    'user_id' => $actor->id, 'actor_name' => User::findOrFail($actor->id)->name, 'pin_audit_id' => $pinAuditId, 'reason' => $data['reason'],
                    'before_hash' => $quote['base_hash'], 'snapshot' => $quote['snapshot'], 'snapshot_hash' => $quote['snapshot_hash'], 'changes' => $quote['changes'],
                    'sales_delta_cents' => $quote['sales_delta_cents'], 'collections_delta_cents' => $quote['collections_delta_cents'],
                    'pending_count' => $pending, 'accepted_pending' => $data['accept_pending'], 'confirmed_at' => now('UTC'),
                ]);
            }));
        } catch (UniqueConstraintViolationException $exception) {
            Gate::forUser($actor)->authorize('viewDailyReports');
            if ($existing = $this->existing($data, $hash)) {
                return $existing;
            }
            throw ValidationException::withMessages(['adjustment' => 'Otra solicitud registró una versión. Revisa el cierre nuevamente.']);
        }
    }

    private function existing(array $data, string $hash): ?MonthlyReportAdjustment
    {
        $request = MonthlyReportAdjustment::where('request_uuid', $data['request_uuid'])->first();
        if ($request) {
            if (! hash_equals($request->request_hash, $hash)) {
                throw ValidationException::withMessages(['adjustment' => 'Esta solicitud ya se utilizó con otros datos. Revisa los cambios nuevamente.']);
            }

            return $request;
        }

        return MonthlyReportAdjustment::where('monthly_close_id', $data['close_id'])->where('sequence', $data['base_sequence'] + 1)->where('preview_hash', $data['preview_hash'])->first();
    }
}
