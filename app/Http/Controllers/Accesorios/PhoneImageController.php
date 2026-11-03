<?php

namespace App\Http\Controllers\Accesorios;

use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\PhoneUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhoneImageController
{
    public function __invoke(Request $request, int $phone): StreamedResponse
    {
        $user = $request->user()->fresh();
        $gate = Gate::forUser($user);
        abort_unless($gate->allows('viewAny', AccessoryProduct::class) || ($gate->allows('create', AccessorySale::class) && $gate->allows('viewAny', AccessorySale::class)), 403);
        $path = PhoneUnit::findOrFail($phone)->image_path;
        abort_unless($path && preg_match('/\Aaccessories\/phones\/[a-f0-9-]{36}\/[a-f0-9]{64}\.jpg\z/', $path), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
