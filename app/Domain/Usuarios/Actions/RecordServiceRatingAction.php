<?php

namespace App\Domain\Usuarios\Actions;

use App\Domain\Usuarios\Enums\ServiceRatingValue;
use App\Domain\Usuarios\Models\ServiceRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordServiceRatingAction
{
    /**
     * Internal contract only. The future Cajas connector must resolve the active
     * cashier from its authenticated device session, never from client input.
     */
    public function execute(User $cashier, ServiceRatingValue $value, string $submissionId): ServiceRating
    {
        Validator::make(['submission_id' => $submissionId], ['submission_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($cashier, $value, $submissionId): ServiceRating {
            $cashier = User::whereKey($cashier->id)->lockForUpdate()->firstOrFail();
            abort_unless($cashier->is_active && $cashier->hasRole('cajera') && $cashier->can('panel.acceder'), 403);
            $rating = ServiceRating::firstOrCreate(['submission_id' => $submissionId], [
                'cashier_user_id' => $cashier->id, 'value' => $value, 'recorded_at' => now(),
            ]);
            if ($rating->cashier_user_id !== $cashier->id || $rating->value !== $value) {
                throw ValidationException::withMessages(['submission_id' => 'Esta referencia ya corresponde a otra calificación.']);
            }

            return $rating;
        });
    }
}
