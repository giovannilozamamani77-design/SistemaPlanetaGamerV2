<?php

namespace App\Http\Controllers\Liquidaciones;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiquidationAccountQrController extends Controller
{
    public function __invoke(Request $request, int $account): StreamedResponse
    {
        Gate::forUser(User::findOrFail($request->user()->id))->authorize('cajas.operar');
        $record = DB::table('cash_box_commission_accounts')->where('id', $account)->first();
        $path = $record->qr_path ?? null;
        abort_unless($path && preg_match('~\Acash-box-commission-qr/[0-9]+/[a-zA-Z0-9_.-]+\z~', $path) && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);
        abort_unless(hash_file('sha256', Storage::disk('local')->path($path)) === $record->qr_sha256, 404);

        return Storage::disk('local')->response($path, headers: ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
