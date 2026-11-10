<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessoryProductRegistration;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Domain\Accesorios\Queries\AccessoryRegistrationQuery;
use App\Infrastructure\Accesorios\ProductImageStorage;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RegisterAccessoryProductAction
{
    public function execute(User $actor, array $data, mixed $image = null): AccessoryProduct
    {
        $q = app(AccessoryRegistrationQuery::class);
        $input = $q->input($actor, $data, $image);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $imagePath = null;
        $wrote = false;
        try {
            return DB::transaction(function () use ($actor, $input, $hash, $image, &$imagePath, &$wrote): AccessoryProduct {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $u = app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
                if ($old = AccessoryProductRegistration::where('request_key', $input['request_key'])->first()) {
                    if ($old->user_id !== $u->id) {
                        throw new AuthorizationException;
                    }
                    if (! hash_equals($old->payload_hash, $hash)) {
                        throw ValidationException::withMessages(['code' => 'Este envío ya se confirmó con otros datos. Revisa el inventario.']);
                    }

                    return AccessoryProduct::findOrFail($old->product_id);
                }
                $review = app(AccessoryRegistrationQuery::class)->prepare($u, $input);
                if (AccessoryProduct::where('request_key', $input['request_key'])->exists()) {
                    throw ValidationException::withMessages(['code' => 'Ese identificador pertenece a un alta anterior. Abre un formulario nuevo.']);
                }
                $product = app(CreateAccessoryProductAction::class)->execute($u, ['code' => $input['code'], 'name' => $input['name'], 'category' => $review['category_name'], 'description' => $input['description'], 'minimum_stock' => $input['minimum_stock'], 'sale_price' => Bolivianos::format($input['sale_price_cents']), 'request_key' => $input['request_key']]);
                if ($input['unit_cost_cents'] === null) {
                    $product->update(['cost_pending' => true]);
                }
                if ($input['quantity'] > 0) {
                    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $product->id, 'quantity' => $input['quantity'], 'unit_cost' => $input['unit_cost_cents'] === null ? null : Bolivianos::format($input['unit_cost_cents']), 'kind' => 'INITIAL', 'expected_revision' => 0, 'request_key' => $input['request_key']]);
                }
                if ($image !== null) {
                    $imagePath = app(ProductImageStorage::class)->store($image, $input['request_key']);
                    $wrote = true;
                }
                $product->update(['category_id' => $input['category_id'], 'reference_cost_cents' => $input['unit_cost_cents'], 'image_path' => $imagePath]);
                AccessoryProductRegistration::create(['product_id' => $product->id, 'user_id' => $u->id, 'actor_name' => $u->name, 'request_key' => $input['request_key'], 'payload_hash' => $hash, 'snapshot' => $review + ['image_path' => $imagePath], 'created_at' => now()]);

                return $product->fresh();
            }, 3);
        } catch (\Throwable $e) {
            if ($wrote && $imagePath) {
                try {
                    if (! AccessoryProductRegistration::where('request_key', $input['request_key'])->exists()) {
                        Storage::disk('local')->delete($imagePath);
                    }
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }
}
