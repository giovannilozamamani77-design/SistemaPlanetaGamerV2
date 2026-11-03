<?php

namespace App\Domain\Prestamos\Services;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Models\LoanCommand;
use App\Domain\Prestamos\Models\LoanFund;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LoanCommandRunner
{
    public function execute(User $actor, string $ability, string $kind, string $key, array $payload, callable $operation): array
    {
        $auth = app(LoanAuthorization::class);
        $auth->authorize($actor, $ability);
        Validator::make(['request_key' => $key], ['request_key' => ['required', 'uuid']])->validate();
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $ability, $kind, $key, $hash, $operation, $auth): array {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $user = $auth->authorize($actor, $ability);
            LoanFund::firstOrCreate(['code' => 'YAPE']);
            $fund = LoanFund::where('code', 'YAPE')->lockForUpdate()->firstOrFail();
            if ($old = LoanCommand::where('request_key', $key)->first()) {
                if ($old->user_id !== $user->id) {
                    throw new AuthorizationException;
                }if ($old->kind !== $kind || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_key' => 'Este envío ya se confirmó con otros datos. Abre un formulario nuevo.']);
                }

                return $old->result;
            }
            $user = $auth->authorize($actor, $ability);
            $result = $operation($fund, $user);
            LoanCommand::create(['user_id' => $user->id, 'kind' => $kind, 'request_key' => $key, 'payload_hash' => $hash, 'result' => $result, 'created_at' => now()]);

            return $result;
        }, 3);
    }
}
