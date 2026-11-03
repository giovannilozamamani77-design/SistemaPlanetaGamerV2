<?php

namespace App\Domain\Cajas\Services;

use App\Domain\Cajas\Calculators\CashBoxClosingBalancePreview;
use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Cajas\Queries\CashBoxClosingStartQuery;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** An ephemeral authorization, never a draft or a financial write. */
class ClosingRestoration
{
    public function authorize(User $actor, int $sessionId, array $closing, array $input, int $adminId, #[SensitiveParameter] string $pin): string
    {
        Validator::make($closing, ['balances' => ['required', 'array'], 'period' => ['required', 'string'], 'expected_revision' => ['required', 'integer']])->validate();
        Validator::make(['admin_id' => $adminId], ['admin_id' => ['required', 'integer', 'exists:users,id']], ['admin_id.exists' => 'Selecciona la persona de Administración que autoriza el aporte.'])->validate();
        $context = app(CashBoxClosingStartQuery::class)->execute($actor, $sessionId);
        $counted = app(CashBoxClosingBalancePreview::class)->calculate($closing['balances'], $context['expected_balances']);
        Validator::make($input, ['amount' => ['required', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'], 'component' => ['required', 'string'],
            'receipt' => ['required', 'string', 'min:3', 'max:2000'], 'received' => ['accepted']])->validate();
        if (! $counted['valid'] || $context['revision'] !== (int) $closing['expected_revision'] || ! isset($context['shifts'][$closing['period']])) {
            $this->fail();
        }
        $amount = Bolivianos::cents($input['amount']);
        $reserved = app(ReservedCashBoxFunds::class)->forBox($context['box_id']);
        $maximum = max(0, -$counted['difference_cents'], $context['operating_capital'] - $counted['total_cents'] + array_sum($reserved));
        if ($amount <= 0 || $amount > $maximum || ! array_key_exists($input['component'], $counted['component_cents'])) {
            throw ValidationException::withMessages(['restoration' => 'El aporte debe cubrir un faltante real, sin superar la diferencia o el capital pendiente.']);
        }
        $admin = app(AdministrativeReview::class)->authorize(User::findOrFail($adminId), 'cajas.liberar');
        if ($admin->id !== $actor->id) {
            app(VerifyPersonalPinAction::class)->execute($admin, $pin);
        }
        $grant = ['actor' => $actor->id, 'session' => $sessionId, 'revision' => $context['revision'], 'period' => $closing['period'],
            'counted' => $counted['component_cents'], 'admin' => $admin->id, 'expires' => now()->addMinutes(30)->timestamp,
            'component' => $input['component'], 'amount_cents' => $amount, 'receipt' => trim($input['receipt'])];

        return Crypt::encryptString(json_encode($grant, JSON_THROW_ON_ERROR));
    }

    public function apply(User $actor, int $sessionId, array $data, array $counted, array $context): array
    {
        $balances = $counted['component_cents'];
        if (empty($data['restoration_token'])) {
            return ['balances' => $balances, 'restoration' => null];
        }
        try {
            $grant = json_decode(Crypt::decryptString($data['restoration_token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $this->fail();
        }
        if ($grant['actor'] !== $actor->id || $grant['session'] !== $sessionId || $grant['revision'] !== $context['revision']
            || $grant['period'] !== $data['period'] || $grant['counted'] !== $balances || $grant['expires'] < now()->timestamp) {
            $this->fail();
        }
        app(AdministrativeReview::class)->authorize(User::findOrFail($grant['admin']), 'cajas.liberar');
        $balances[$grant['component']] += $grant['amount_cents'];

        return ['balances' => $balances, 'restoration' => $grant];
    }

    public function administrators(User $actor, int $sessionId): array
    {
        app(CashBoxClosingStartQuery::class)->execute($actor, $sessionId);

        return User::role('administrador')->where('is_active', true)->get()->filter(fn (User $admin): bool => $admin->can('cajas.liberar'))->pluck('name', 'id')->all();
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['restoration' => 'El aporte cambió o su autorización venció. Revisa el resumen y vuelve a autorizarlo.']);
    }
}
