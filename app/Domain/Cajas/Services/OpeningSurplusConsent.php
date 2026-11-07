<?php

namespace App\Domain\Cajas\Services;

use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Queries\CashBoxWorkspaceQuery;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class OpeningSurplusConsent
{
    public function issue(User $actor, int $accessId, array $data): array
    {
        $workspace = app(CashBoxWorkspaceQuery::class)->execute($actor, $accessId);
        if ($workspace['session_id'] || $workspace['opening_reference_below_capital']) {
            throw ValidationException::withMessages(['opening_total' => 'La entrega anterior está por debajo del capital operativo. Administración debe rectificarla antes de abrir; completarla no se considerará un sobrante.']);
        }
        $access = CashBoxAccess::findOrFail($accessId);
        $balances = [];
        foreach ($access->expected_balances as $code => $value) {
            $balances[$code] = Bolivianos::cents(($data['balances'][$code] ?? '') ?: '0');
        }
        ksort($balances);
        $surplus = array_sum($balances) - $workspace['opening_required_total'];
        if ($surplus <= 0) {
            throw ValidationException::withMessages(['opening_total' => 'No hay un sobrante válido para confirmar.']);
        }
        $payload = ['purpose' => 'opening-surplus', 'user' => $actor->id, 'access' => $accessId, 'request' => $data['request_key'],
            'balances' => $balances, 'reference' => $workspace['opening_required_total'], 'floor' => $workspace['opening_capital_floor'], 'expires' => now()->addMinutes(10)->timestamp];

        return ['token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'surplus' => $surplus, 'total' => array_sum($balances), 'reference' => $workspace['opening_required_total']];
    }

    public function verify(?string $token, User $actor, int $accessId, string $requestKey, array $balances, int $reference, int $floor): void
    {
        try {
            $payload = filled($token) ? json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (DecryptException|\JsonException) {
            $payload = null;
        }
        ksort($balances);
        if (! is_array($payload) || ($payload['purpose'] ?? null) !== 'opening-surplus' || ($payload['user'] ?? null) !== $actor->id
            || ($payload['access'] ?? null) !== $accessId || ($payload['request'] ?? null) !== $requestKey || ($payload['balances'] ?? null) !== $balances
            || ($payload['reference'] ?? null) !== $reference || ($payload['floor'] ?? null) !== $floor || ($payload['expires'] ?? 0) <= now()->timestamp) {
            throw ValidationException::withMessages(['opening_surplus_consent' => 'Confirma el sobrante en la ventana de advertencia. Si cambiaste los importes o la entrega, vuelve a revisar y confirmar.']);
        }
    }
}
