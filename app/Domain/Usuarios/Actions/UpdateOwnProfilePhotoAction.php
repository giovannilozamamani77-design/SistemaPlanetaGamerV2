<?php

namespace App\Domain\Usuarios\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class UpdateOwnProfilePhotoAction
{
    public function execute(User $actor, mixed $photo = null, bool $remove = false): User
    {
        $current = User::findOrFail($actor->id);
        Gate::forUser($current)->authorize('perfil.ver');
        Gate::forUser($current)->authorize('perfil.actualizar');
        Validator::make(['photo' => $photo], [
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ])->validate();

        if ($photo === null && ! $remove) {
            return $current;
        }

        $newPath = null;
        $oldPath = null;
        try {
            if ($photo instanceof UploadedFile) {
                $newPath = $photo->storeAs((string) $current->id, Str::uuid().'.'.$photo->guessExtension(), 'profile_photos');
            }
            $saved = DB::transaction(function () use ($current, $newPath, &$oldPath): User {
                $user = User::whereKey($current->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($user)->authorize('perfil.ver');
                Gate::forUser($user)->authorize('perfil.actualizar');
                $oldPath = $user->profile_photo_path;
                if ($oldPath === $newPath) {
                    return $user;
                }
                $user->profile_photo_path = $newPath;
                $user->save();
                DB::table('user_audits')->insert([
                    'actor_user_id' => $user->id, 'subject_user_id' => $user->id,
                    'action' => 'profile_photo_updated',
                    'changes' => json_encode(['had_photo' => $oldPath !== null, 'has_photo' => $newPath !== null], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return $user;
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk('profile_photos')->delete($newPath);
            }
            throw $exception;
        }
        if ($oldPath !== null && $oldPath !== $newPath) {
            DB::afterCommit(fn () => Storage::disk('profile_photos')->delete($oldPath));
        }
        $actor->refresh();

        return $saved;
    }
}
