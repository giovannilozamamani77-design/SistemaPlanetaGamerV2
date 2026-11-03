<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Models\PhoneBrand;
use App\Domain\Accesorios\Models\PhoneImei;
use App\Domain\Accesorios\Models\PhoneModel;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PhoneVariant;
use App\Domain\Accesorios\Queries\PhoneInventoryQuery;
use App\Infrastructure\Accesorios\ProductImageStorage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class RegisterPhoneUnitAction
{
    public function execute(User $actor, array $data, mixed $image = null, ?string $expectedReviewHash = null): PhoneUnit
    {
        $q = app(PhoneInventoryQuery::class);
        $q->authorize($actor, 'manage');
        $prior = is_string($data['request_key'] ?? null) ? PhoneUnit::where('request_key', strtolower($data['request_key']))->where('created_by', $actor->id)->first() : null;
        $v = $q->input($actor, $data, $image, $prior?->purchase_rate_micros);
        $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $v, $hash, $image, $q, $expectedReviewHash, &$path): PhoneUnit {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $u = $q->authorize($actor, 'manage');
                if ($old = PhoneUnit::where('request_key', $v['request_key'])->first()) {
                    if ($old->created_by !== $u->id) {
                        throw new AuthorizationException;
                    }
                    if (! hash_equals($old->payload_hash, $hash)) {
                        throw ValidationException::withMessages(['imei1' => 'Este envío ya tiene otros datos. Revisa el registro.']);
                    }

                    return $old;
                }
                $review = $q->prepare($v);
                if ($expectedReviewHash !== null && ! hash_equals($expectedReviewHash, $review['fingerprint'])) {
                    throw ValidationException::withMessages(['sale_price' => 'Los datos o la cotización cambiaron. Vuelve a revisar.']);
                }
                foreach (['imei1', 'imei2'] as $key) {
                    if ($v[$key] && PhoneImei::where('imei', $v[$key])->exists()) {
                        throw ValidationException::withMessages([$key => 'Este IMEI ya está registrado en otro equipo.']);
                    }
                }
                $brand = PhoneBrand::firstOrCreate(['normalized_name' => $q->normalize($v['brand'])], ['name' => $v['brand']]);
                $model = PhoneModel::firstOrCreate(['brand_id' => $brand->id, 'normalized_name' => $q->normalize($v['model'])], ['name' => $v['model']]);
                $variant = PhoneVariant::firstOrCreate(['model_id' => $model->id, 'ram_gb' => $v['ram_gb'], 'storage_gb' => $v['storage_gb']]);
                if ($image !== null) {
                    $path = app(ProductImageStorage::class)->store($image, $v['request_key'], 'phones');
                }
                $p = PhoneUnit::create(['variant_id' => $variant->id, 'condition' => $v['condition'], 'color' => $v['color'], 'physical_state' => $v['physical_state'], 'included_accessories' => $v['included_accessories'], 'origin' => $v['origin'], 'status' => 'AVAILABLE', 'purchase_currency' => $v['condition'] === 'NEW' ? 'USD' : 'BOB', 'purchase_amount_cents' => $v['purchase_amount_cents'], 'purchase_rate_micros' => $v['purchase_rate_micros'], 'expenses_cents' => $v['expenses_cents'], 'historical_cost_cents' => $review['historical_cost_cents'], 'sale_price_cents' => $review['pricing']['price_cents'], 'pricing_snapshot' => $review['pricing'], 'registration_snapshot' => $v, 'image_path' => $path, 'created_by' => $u->id, 'actor_name' => $u->name, 'request_key' => $v['request_key'], 'payload_hash' => $hash]);
                foreach ([1, 2] as $slot) {
                    if ($v['imei'.$slot]) {
                        PhoneImei::create(['unit_id' => $p->id, 'slot' => $slot, 'imei' => $v['imei'.$slot]]);
                    }
                }

                for ($index = 2; $index <= ($v['quantity'] ?? 1); $index++) {
                    $unit = $p->replicate();
                    $unit->request_key = Uuid::uuid5($v['request_key'], 'unit:'.$index)->toString();
                    $unit->save();
                }

                return $p->load('imeis');
            }, 3);
        } catch (\Throwable $e) {
            if ($path) {
                try {
                    if (! PhoneUnit::where('request_key', $v['request_key'])->exists()) {
                        Storage::disk('local')->delete($path);
                    }
                } catch (\Throwable $cleanup) {
                    report($cleanup);
                }
            }
            throw $e;
        }
    }
}
