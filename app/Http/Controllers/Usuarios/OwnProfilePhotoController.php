<?php

namespace App\Http\Controllers\Usuarios;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwnProfilePhotoController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('perfil.ver');
        $path = $user->profile_photo_path;
        abort_if($path === null || ! Storage::disk('profile_photos')->exists($path), 404);

        return Storage::disk('profile_photos')->response($path, headers: [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
