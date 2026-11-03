<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveLoanAccountAction
{
    public function execute(User $actor, array $data): void
    {
        app(LoanAuthorization::class)->authorize($actor, 'manageFund');
        $v = Validator::make($data, ['bank' => ['required', 'string', 'max:120'], 'number' => ['required', 'string', 'max:120'], 'holder' => ['required', 'string', 'max:120'], 'qr_path' => ['nullable', 'string', 'max:255'], 'expected_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        app(LoanCommandRunner::class)->execute($actor, 'manageFund', 'ACCOUNT', $v['request_key'], $v, function ($fund, $user) use ($v): array {
            if ($fund->revision !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['bank' => 'La cuenta o fondo cambió. Vuelve a abrir la configuración.']);
            }
            $path = $v['qr_path'] ?? null;
            $hash = null;
            if ($path) {
                if (! str_starts_with($path, 'loan-qr/') || str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
                    throw ValidationException::withMessages(['qr_path' => 'Selecciona un QR válido.']);
                }$content = Storage::disk('local')->get($path);
                $image = @getimagesizefromstring($content);
                if (strlen($content) > 4194304 || ! $image || ! in_array($image['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    throw ValidationException::withMessages(['qr_path' => 'Usa PNG, JPEG o WebP de hasta 4 MB.']);
                }$hash = hash('sha256', $content);
            }
            if ($fund->number && ($fund->bank !== $v['bank'] || $fund->number !== $v['number'] || $fund->holder !== $v['holder']) && $hash && $hash === $fund->qr_sha256) {
                throw ValidationException::withMessages(['qr_path' => 'Al cambiar cuenta sube su QR correspondiente o retira el anterior.']);
            }
            $before = $fund->only(['bank', 'number', 'holder', 'qr_path', 'qr_sha256']);
            $fund->update(array_intersect_key($v, array_flip(['bank', 'number', 'holder'])) + ['qr_path' => $path, 'qr_sha256' => $hash, 'revision' => $fund->revision + 1]);

            return ['before' => $before, 'after' => $fund->fresh()->only(['bank', 'number', 'holder', 'qr_path', 'qr_sha256'])];
        });
    }
}
