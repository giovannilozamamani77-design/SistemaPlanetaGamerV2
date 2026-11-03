<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\Usuarios\Models\ServiceRating;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

class UsersOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return Gate::allows('viewAny', User::class);
    }

    protected function getStats(): array
    {
        Gate::authorize('viewAny', User::class);
        $users = User::query()->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')->first();
        $ratings = ServiceRating::query()->selectRaw("COUNT(*) as total, SUM(CASE WHEN value = 'buena' THEN 1 ELSE 0 END) as good, SUM(CASE WHEN value = 'regular' THEN 1 ELSE 0 END) as neutral, SUM(CASE WHEN value = 'mala' THEN 1 ELSE 0 END) as bad")->first();

        return [
            Stat::make('Tu equipo', (string) $users->total)->description(($users->active ?? 0).' cuentas activas')->descriptionIcon('heroicon-o-users')->color('primary'),
            Stat::make('Roles de acceso', (string) Role::where('guard_name', 'web')->count())->description('Permisos organizados por función')->descriptionIcon('heroicon-o-shield-check')->color('warning'),
            Stat::make('Atención al cliente', (string) $ratings->total)
                ->description($ratings->total ? $ratings->good.' buenas · '.$ratings->neutral.' regulares · '.$ratings->bad.' malas' : 'Sin valoraciones · tablet pendiente de Cajas')
                ->descriptionIcon('heroicon-o-face-smile')->color('success'),
        ];
    }
}
