<?php

namespace App\Http\Controllers\Prestamos;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Models\LoanFund;
use Illuminate\Support\Facades\Storage;

class LoanQrController
{
    public function __invoke()
    {
        app(LoanAuthorization::class)->authorize(auth()->user(), 'viewAny');
        $fund = LoanFund::where('code', 'YAPE')->firstOrFail();
        abort_unless($fund->qr_path && Storage::disk('local')->exists($fund->qr_path), 404);
        abort_if(request()->filled('revision') && (int) request('revision') !== $fund->revision, 409);
        $content = Storage::disk('local')->get($fund->qr_path);
        abort_unless(hash_equals($fund->qr_sha256 ?? '', hash('sha256', $content)), 404);

        return response($content)->header('Content-Type', Storage::disk('local')->mimeType($fund->qr_path))->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; sandbox");
    }
}
