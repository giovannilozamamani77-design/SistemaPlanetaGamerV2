<?php

namespace App\Http\Controllers\Accesorios;

use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessoryProductImageController
{
    public function product(Request $request, int $product): StreamedResponse
    {
        $u = User::findOrFail($request->user()->id);
        abort_unless(Gate::forUser($u)->allows('viewAny', AccessoryProduct::class) || Gate::forUser($u)->allows('create', AccessorySale::class) || Gate::forUser($u)->allows('viewAny', AccessorySale::class), 403);

        return $this->serve(AccessoryProduct::findOrFail($product)->image_path);
    }

    public function line(Request $request, int $line): StreamedResponse
    {
        app(AccessorySaleQuery::class)->authorize($request->user(), 'viewAny');

        return $this->serve(AccessorySaleLine::findOrFail($line)->product_image_path);
    }

    private function serve(?string $path): StreamedResponse
    {
        abort_unless($path && preg_match('/\Aaccessories\/(?:products|phones)\/[a-f0-9-]{36}\/[a-f0-9]{64}\.jpg\z/', $path), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
