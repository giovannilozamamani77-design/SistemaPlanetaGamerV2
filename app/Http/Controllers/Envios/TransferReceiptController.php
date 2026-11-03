<?php

namespace App\Http\Controllers\Envios;

use App\Domain\Envios\Queries\InternationalTransferQuery;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransferReceiptController extends Controller
{
    public function __invoke(int $event): StreamedResponse
    {
        abort_unless(config('envios.enabled', false), 403);
        Gate::authorize('envios.ver');
        $entry = DB::table('international_transfer_events')->where('id', $event)->first();
        abort_unless($entry, 404);
        app(InternationalTransferQuery::class)->builder(auth()->user())->where('international_transfers.id', $entry->international_transfer_id)->firstOrFail();
        $path = $entry->receipt_file_path;
        abort_unless($path && str_starts_with($path, 'envios/comprobantes/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }
}
