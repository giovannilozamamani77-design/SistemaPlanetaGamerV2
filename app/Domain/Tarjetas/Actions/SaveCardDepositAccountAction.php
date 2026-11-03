<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveCardDepositAccountAction
{
    public function execute(User $actor, array $data): CardDepositAccount
    {
        app(CardInventoryAuthorization::class)->authorize($actor, 'configureDeposits');
        $v = Validator::make($data, ['account_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:120'], 'bank' => ['required', 'string', 'max:120'], 'number' => ['required', 'string', 'max:120'], 'holder' => ['required', 'string', 'max:120'], 'qr_path' => ['nullable', 'string', 'max:255'], 'is_active' => ['required', 'boolean'], 'expected_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = collect($v)->except('request_key')->all();
        $result = app(CardInventoryCommandRunner::class)->execute($actor, 'configureDeposits', 'DEPOSIT_ACCOUNT_SAVE', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload): array {
            $account = filled($payload['account_id'] ?? null) ? CardDepositAccount::whereKey($payload['account_id'])->lockForUpdate()->firstOrFail() : null;
            if (($account?->revision ?? 0) !== (int) $payload['expected_revision']) {
                throw ValidationException::withMessages(['name' => 'Esta cuenta cambió. Vuelve a abrirla antes de guardar.']);
            }
            $path = $payload['qr_path'] ?? null;
            $hash = null;
            if ($path) {
                if (! str_starts_with($path, 'card-deposit-qr/') || str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
                    throw ValidationException::withMessages(['qr_path' => 'Selecciona una imagen QR válida.']);
                }
                $content = Storage::disk('local')->get($path);
                $image = @getimagesizefromstring($content);
                if (strlen($content) > 4194304 || ! $image || ! in_array($image['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    throw ValidationException::withMessages(['qr_path' => 'El QR debe ser una imagen PNG, JPEG o WebP de hasta 4 MB.']);
                }
                $hash = hash('sha256', $content);
            }
            if ($payload['is_active'] && ! $path) {
                throw ValidationException::withMessages(['qr_path' => 'Sube el QR para habilitar esta cuenta.']);
            }
            if ($account && ($account->number !== $payload['number'] || $account->bank !== $payload['bank'] || $account->holder !== $payload['holder']) && $path && $hash === $account->qr_sha256) {
                throw ValidationException::withMessages(['qr_path' => 'Si cambias banco, número o titular, sube el QR correspondiente.']);
            }
            $before = $account ? $account->snapshot() + ['is_active' => $account->is_active] : null;
            $values = collect($payload)->only(['name', 'bank', 'number', 'holder', 'is_active'])->all() + ['qr_path' => $path, 'qr_sha256' => $hash, 'revision' => ($account?->revision ?? 0) + 1];
            if ($account) {
                $account->update($values);
            } else {
                $account = CardDepositAccount::create($values + ['created_by' => $user->id]);
            }

            return ['account_id' => $account->id, 'before' => $before, 'after' => $account->fresh()->snapshot() + ['is_active' => $account->is_active]];
        });

        return CardDepositAccount::findOrFail($result['account_id']);
    }
}
