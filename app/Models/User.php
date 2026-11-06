<?php

namespace App\Models;

use App\Domain\Usuarios\Models\ServiceRating;
use App\Domain\Usuarios\Support\Username;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'username'])]
#[Hidden(['password', 'remember_token', 'pin_hash', 'profile_photo_path'])]
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (! $user->exists && blank($user->username)) {
                $user->username = Username::availableFromName($user->name);
            }
            if ($user->isDirty('username') || ! $user->exists) {
                $user->username = filled($user->username) ? trim($user->username) : null;
                if ($user->username !== null && ! preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/', $user->username)) {
                    throw ValidationException::withMessages(['username' => 'Usa letras, números, punto, guion o guion bajo; comienza con una letra.']);
                }
                $user->username_key = Username::key($user->username);
            }
        });
    }

    public function getFilamentAvatarUrl(): ?string
    {
        if (! $this->profile_photo_path || auth()->id() !== $this->id) {
            return null;
        }

        return route('perfil.foto', ['v' => substr(hash('sha256', $this->profile_photo_path), 0, 12)]);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'inicio' && $this->can('panel.acceder');
    }

    /** @return BelongsToMany<Permission, $this> */
    public function restrictedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission_restrictions')->withTimestamps();
    }

    /** @return HasMany<ServiceRating, $this> */
    public function serviceRatings(): HasMany
    {
        return $this->hasMany(ServiceRating::class, 'cashier_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'salary_pay_day' => 'integer', 'is_active' => 'boolean',
            'pin_changed_at' => 'datetime',
        ];
    }
}
