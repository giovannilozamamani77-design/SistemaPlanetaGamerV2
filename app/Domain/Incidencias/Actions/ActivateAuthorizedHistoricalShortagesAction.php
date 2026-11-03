<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One-off C101 data maintenance, explicitly authorized by the owner on 2026-10-20. */
class ActivateAuthorizedHistoricalShortagesAction
{
    public function execute(string $backupSha256): int
    {
        if (! app()->runningInConsole() || ! preg_match('/\A[a-f0-9]{64}\z/', $backupSha256)) {
            throw ValidationException::withMessages(['c101' => 'Requiere ejecución de mantenimiento y respaldo verificado.']);
        }

        return DB::transaction(function () use ($backupSha256): int {
            $approved = [1 => 1000, 2 => 8000];
            $owners = EconomicIncident::whereKey(array_keys($approved))->orderBy('user_id')->pluck('user_id')->unique();
            foreach ($owners as $id) {
                User::whereKey($id)->lockForUpdate()->firstOrFail();
            }
            $cases = EconomicIncident::whereKey(array_keys($approved))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $pending = [];
            foreach ($approved as $id => $amount) {
                $case = $cases->get($id);
                $close = DB::table('cash_box_closes')->where('economic_incident_id', $id)->lockForUpdate()->first();
                if (! $case || $case->amount_cents !== $amount || $case->source_module !== 'cajas' || ! $close || $close->cancelled_at || $close->user_id !== $case->user_id || $close->difference_cents !== -$amount || $case->source_reference !== 'close:'.$close->id) {
                    throw ValidationException::withMessages(['c101' => 'El registro autorizado cambió o no corresponde a un cierre vigente. No se aplicó la activación.']);
                }
                $key = 'authorized-c101:'.$id;
                if ($event = DB::table('economic_history_events')->where('event_key', $key)->first()) {
                    if ($event->economic_incident_id !== $id || $event->action !== 'FALTANTE_ACTIVADO_C101' || (json_decode($event->details, true)['amount_cents'] ?? null) !== $amount) {
                        throw ValidationException::withMessages(['c101' => 'Auditoría previa incompatible.']);
                    }

                    continue; // Never reset a subsequent repayment or administrative resolution.
                }
                if (! in_array($case->status, ['DETECTADA', 'EN_REVISION'], true) || $case->pending_cents !== 0 || $case->forgiven_cents !== 0 || $case->recoveries()->whereNotNull('confirmed_at')->exists()) {
                    throw ValidationException::withMessages(['c101' => 'El faltante ya cambió o recibió una resolución. Revisa antes de continuar.']);
                }
                $pending[] = $case;
            }
            foreach ($pending as $case) {
                $before = $case->only(['status', 'pending_cents']);
                $case->update(['status' => 'CONFIRMADA', 'pending_cents' => $case->amount_cents]);
                app(EconomicHistoryAudit::class)->record($case->id, null, 'FALTANTE_ACTIVADO_C101', 'authorized-c101:'.$case->id, [
                    'authorization' => 'Autorización explícita del propietario en chat, 20/10/2026: solo IDs1,2 por Bs90, con respaldo y sin alterar Caja ni operaciones.',
                    'amount_cents' => $case->amount_cents, 'before' => $before, 'after' => $case->only(['status', 'pending_cents']), 'backup_sha256' => $backupSha256,
                ]);
            }

            return count($pending);
        });
    }
}
