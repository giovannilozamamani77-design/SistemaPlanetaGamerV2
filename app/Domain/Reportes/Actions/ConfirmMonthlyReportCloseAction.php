<?php

namespace App\Domain\Reportes\Actions;

use App\Domain\Reportes\Models\MonthlyReportClose;
use App\Domain\Reportes\Queries\MonthlyReportSnapshotQuery;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class ConfirmMonthlyReportCloseAction
{
    public function execute(User $actor, array $input, #[SensitiveParameter] string $pin): MonthlyReportClose
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        $data = Validator::make($input, [
            'month' => ['required', 'string'], 'request_uuid' => ['required', 'uuid'],
            'preview_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'accept_pending' => ['required', 'boolean'],
        ])->validate();
        app(MonthlyReportSnapshotQuery::class)->month($data['month']);
        $data['accept_pending'] = (bool) $data['accept_pending'];
        $requestHash = hash('sha256', json_encode([$actor->id, $data], JSON_THROW_ON_ERROR));
        $pinAuditId = app(RetryReportConfirmationAction::class)->execute(fn (): int => app(VerifyPersonalPinAction::class)->verifyAndReturnAudit($actor, $pin));
        try {
            return app(RetryReportConfirmationAction::class)->execute(fn (): MonthlyReportClose => DB::transaction(function () use ($actor, $data, $requestHash, $pinAuditId): MonthlyReportClose {
                Gate::forUser($actor)->authorize('viewDailyReports');
                if ($existing = $this->existing($data, $requestHash)) {
                    return $existing;
                }
                $capture = app(MonthlyReportSnapshotQuery::class)->execute($actor, $data['month']);
                if (! hash_equals($capture['hash'], $data['preview_hash'])) {
                    throw ValidationException::withMessages(['preview' => 'La información cambió. Pulsa Revisar mes y vuelve a confirmar los datos actualizados.']);
                }
                $pendingCount = count($capture['snapshot']['pending']);
                if ($pendingCount > 0 && ! $data['accept_pending']) {
                    throw ValidationException::withMessages(['acceptPending' => 'Acepta la advertencia de pendientes antes de confirmar. No se resolverán ni se contarán como pérdidas.']);
                }
                Gate::forUser($actor)->authorize('viewDailyReports');

                return MonthlyReportClose::create([
                    'month' => $data['month'], 'request_uuid' => $data['request_uuid'], 'request_hash' => $requestHash,
                    'user_id' => $actor->id, 'actor_name' => User::findOrFail($actor->id)->name, 'pin_audit_id' => $pinAuditId,
                    'schema_version' => 1, 'snapshot' => $capture['snapshot'], 'snapshot_hash' => $capture['hash'],
                    'source_count' => count($capture['snapshot']['records']), 'pending_count' => $pendingCount,
                    'accepted_pending' => $data['accept_pending'], 'confirmed_at' => now('UTC'),
                ]);
            }));
        } catch (UniqueConstraintViolationException $exception) {
            Gate::forUser($actor)->authorize('viewDailyReports');
            if ($existing = $this->existing($data, $requestHash)) {
                return $existing;
            }
            throw $exception;
        }
    }

    private function existing(array $data, string $requestHash): ?MonthlyReportClose
    {
        $request = MonthlyReportClose::where('request_uuid', $data['request_uuid'])->first();
        if ($request) {
            if (! hash_equals($request->request_hash, $requestHash)) {
                throw ValidationException::withMessages(['preview' => 'Esta solicitud ya se utilizó con otros datos. Revisa el mes nuevamente.']);
            }

            return $request;
        }

        return MonthlyReportClose::where('month', $data['month'])->first();
    }
}
