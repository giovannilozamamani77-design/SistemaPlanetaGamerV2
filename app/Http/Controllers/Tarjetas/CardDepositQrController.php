<?php

namespace App\Http\Controllers\Tarjetas;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use Illuminate\Support\Facades\Storage;

class CardDepositQrController
{
    public function __invoke(int $account)
    {
        app(CardInventoryAuthorization::class)->authorize(auth()->user(), 'deposit');
        $a = CardDepositAccount::findOrFail($account);
        abort_unless($a->is_active && $a->qr_path && Storage::disk('local')->exists($a->qr_path), 404);
        abort_if(request()->filled('revision') && (int) request('revision') !== $a->revision, 409);
        $content = Storage::disk('local')->get($a->qr_path);
        abort_unless(hash_equals($a->qr_sha256 ?? '', hash('sha256', $content)), 404);

        return response($content)->header('Content-Type', Storage::disk('local')->mimeType($a->qr_path))->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; sandbox");
    }
}
