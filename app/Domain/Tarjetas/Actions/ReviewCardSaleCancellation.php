<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardSaleCancellationRequest;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Queries\CardSaleRegisterQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReviewCardSaleCancellation
{
    public function canRequest(User $actor, int $saleId): bool
    {
        return app(CardSaleRegisterQuery::class)->currentSessionEntries($actor)
            ->where('entry_type', 'CARD')->where('source_id', $saleId)
            ->where('user_id', $actor->id)->whereNull('cancelled_at')->exists()
            && ! CardSaleCancellationRequest::where('card_sale_id', $saleId)->exists();
    }

    public function request(User $actor, int $saleId, string $reason): void
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:10|max:2000'])->validate();
        DB::transaction(function () use ($actor, $saleId, $reason): void {
            $sale = CardSale::findOrFail($saleId);
            CardStore::whereKey($sale->card_store_id)->lockForUpdate()->firstOrFail();
            if (! $this->canRequest($actor, $saleId)) {
                throw ValidationException::withMessages(['reason' => 'Solo puedes solicitar una vez la anulación de una venta propia de tu turno abierto.']);
            }
            CardSaleCancellationRequest::create(['card_sale_id' => $saleId, 'requested_by' => $actor->id, 'reason' => $reason]);
        });
    }

    public function decide(User $actor, int $requestId, bool $approve): void
    {
        DB::transaction(function () use ($actor, $requestId, $approve): void {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(CardInventoryAuthorization::class)->authorize($actor, 'cancelSale');
            $request = CardSaleCancellationRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'PENDIENTE') {
                return;
            }
            if ($approve) {
                app(CancelCardSaleAction::class)->execute($actor, $request->card_sale_id, [
                    'reason' => $request->reason,
                    'confirmed' => true,
                    'request_key' => (string) Str::uuid(),
                ]);
            }
            $request->update([
                'status' => $approve ? 'APROBADA' : 'NEGADA',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
        });
    }
}
