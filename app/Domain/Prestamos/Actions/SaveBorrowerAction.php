<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveBorrowerAction
{
    public function execute(User $actor, array $data, ?Borrower $target = null): Borrower
    {
        $auth = app(LoanAuthorization::class);
        $auth->authorize($actor, 'create');
        $auth->authorize($actor, 'viewAny');
        if ($target) {
            app(LoanQuery::class)->clients($actor)->whereKey($target->id)->firstOrFail();
        }
        $v = Validator::make($data, ['ci' => ['required', 'string', 'max:60'], 'name' => ['required', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:40'], 'expected_version' => ['nullable', 'string', 'size:64'], 'request_key' => ['required', 'uuid']])->validate();
        $profile = ['ci' => LoanInput::normalize($v['ci']), 'name' => trim($v['name']), 'phone' => trim($v['phone'] ?? '') ?: null];
        $payload = ['id' => $target?->id, 'profile' => $profile, 'expected_version' => $v['expected_version'] ?? null];
        $result = app(LoanCommandRunner::class)->execute($actor, 'create', 'CLIENT_SAVE', $v['request_key'], $payload, function ($fund, $user) use ($target, $profile, $payload): array {
            app(LoanAuthorization::class)->authorize($user, 'viewAny');
            $client = $target ? app(LoanQuery::class)->clients($user)->whereKey($target->id)->lockForUpdate()->firstOrFail() : Borrower::where('ci', $profile['ci'])->lockForUpdate()->first();
            $before = $client?->only(['ci', 'name', 'phone']);
            if ($target) {
                if ($client->ci !== $profile['ci']) {
                    throw ValidationException::withMessages(['ci' => 'El CI identifica al cliente y no se cambia desde esta edición.']);
                }
                if (! hash_equals($client->profileVersion(), $payload['expected_version'] ?? '')) {
                    throw ValidationException::withMessages(['name' => 'Los datos cambiaron. Vuelve a abrir la edición antes de guardar.']);
                }
                $client->update($profile);
            } elseif ($client) {
                if ($before !== $profile) {
                    throw ValidationException::withMessages(['ci' => 'Este CI ya está registrado. Busca al cliente y usa Editar para actualizar sus datos.']);
                }
            } else {
                $client = Borrower::create($profile);
            }

            return ['id' => $client->id, 'before' => $before, 'after' => $client->fresh()->only(['ci', 'name', 'phone']), 'reused' => ! $target && $before !== null];
        });

        return Borrower::findOrFail($result['id']);
    }
}
