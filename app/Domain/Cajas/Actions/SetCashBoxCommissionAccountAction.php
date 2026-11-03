<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SetCashBoxCommissionAccountAction
{
    public function execute(User $actor, #[\SensitiveParameter] array $data): int
    {
        app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.gestionar');
        $v = Validator::make($data, ['name' => ['sometimes', 'required', 'string', 'max:120', 'regex:/\S/u'], 'account_id' => ['nullable', 'integer', 'min:1'], 'holder_name' => ['nullable', 'string', 'max:120'], 'qr_path' => ['nullable', 'string', 'max:255'], 'destination' => ['required', 'string', 'min:3', 'max:120'], 'reason' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\S/u'], 'pin' => ['required', 'string'], 'request_key' => ['required', 'uuid']])->validate();
        app(VerifyPersonalPinAction::class)->execute($actor, $v['pin']);

        $v['name'] = trim($v['name'] ?? 'Yape de comisiones');
        $v['account_id'] = isset($v['account_id']) ? (int) $v['account_id'] : null;
        $v['holder_name'] = isset($v['holder_name']) && trim($v['holder_name']) !== '' ? trim($v['holder_name']) : null;
        $path = $v['qr_path'] ?? null;
        $v['qr_path'] = $path;
        $v['qr_sha256'] = null;
        if ($path) {
            $known = DB::table('cash_box_commission_accounts')->where('qr_path', $path)->first();
            if (! preg_match('~\Acash-box-commission-qr/[0-9]+/[a-zA-Z0-9_.-]+\z~', $path) || str_contains($path, '..') || (! $known && ! str_starts_with($path, 'cash-box-commission-qr/'.$actor->id.'/')) || ($known && $known->destination !== $v['destination']) || ! Storage::disk('local')->exists($path)) {
                throw ValidationException::withMessages(['qr_path' => 'Sube un QR nuevo de esta cuenta o retira la imagen.']);
            }
            $file = new File(Storage::disk('local')->path($path));
            Validator::make(['qr_path' => $file], ['qr_path' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096']])->validate();
            $v['qr_sha256'] = hash_file('sha256', $file->getPathname());
            if ($known && $known->qr_sha256 !== $v['qr_sha256']) {
                throw ValidationException::withMessages(['qr_path' => 'La imagen cambió. Sube un archivo nuevo.']);
            }
        }

        return DB::transaction(function () use ($actor, $v, $data): int {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.gestionar');
            $original = $v['account_id'] ? DB::table('cash_box_commission_accounts')->where('id', $v['account_id'])->lockForUpdate()->first() : null;
            if ($v['account_id'] && (! $original || $original->is_demo)) {
                throw ValidationException::withMessages(['name' => 'Selecciona una cuenta real existente.']);
            }
            if ($prior = DB::table('cash_box_commission_accounts')->where('request_key', $v['request_key'])->first()) {
                if ($prior->created_by !== $actor->id || $prior->name !== $v['name'] || ($original && $prior->account_key !== $original->account_key) || $prior->destination !== $v['destination'] || $prior->holder_name !== $v['holder_name'] || $prior->reason !== $v['reason'] || $prior->qr_path !== $v['qr_path'] || $prior->qr_sha256 !== $v['qr_sha256']) {
                    throw ValidationException::withMessages(['reason' => 'Solicitud ya utilizada con otros datos.']);
                }

                return $prior->id;
            }

            if ($original && DB::table('cash_box_commission_accounts')->where('account_key', $original->account_key)->where('is_demo', false)->where('id', '>', $original->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'La cuenta cambió. Vuelve a abrir el formulario.']);
            }
            $key = $original?->account_key ?? ($v['name'] === 'Yape de comisiones' && ! array_key_exists('name', $data) ? 'legacy' : (string) Str::uuid());

            return DB::table('cash_box_commission_accounts')->insertGetId(['name' => $v['name'], 'account_key' => $key, 'holder_name' => $v['holder_name'], 'qr_path' => $v['qr_path'], 'qr_sha256' => $v['qr_sha256'], 'destination' => $v['destination'], 'reason' => $v['reason'], 'created_by' => $actor->id, 'request_key' => $v['request_key'], 'created_at' => now()]);
        });
    }
}
