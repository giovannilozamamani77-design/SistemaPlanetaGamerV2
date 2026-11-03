<?php

namespace App\Filament\Navigation;

use App\Filament\Pages\Usuarios\EditOwnProfile;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;

final class PanelNavigation
{
    /** @return array<NavigationGroup> */
    public static function groups(): array
    {
        return [
            NavigationGroup::make('Cajas')->icon(Heroicon::OutlinedBuildingStorefront)->collapsible(),
            NavigationGroup::make('Tarjetas')->icon(Heroicon::OutlinedRectangleStack)->collapsible(),
            NavigationGroup::make('Accesorios')->icon(Heroicon::OutlinedShoppingBag)->collapsible(),
            NavigationGroup::make('Reposiciones de caja')->icon(Heroicon::OutlinedBanknotes)->collapsible(),
            NavigationGroup::make('Personal')->icon(Heroicon::OutlinedUsers)->collapsible(),
            NavigationGroup::make('Reportes')->icon(Heroicon::OutlinedChartBarSquare)->collapsible(),
            NavigationGroup::make('Administración')->icon(Heroicon::OutlinedShieldCheck)->collapsible(),
            NavigationGroup::make('Configuraciones')->icon(Heroicon::OutlinedCog6Tooth)->collapsible(),
        ];
    }

    /** @return array<NavigationItem> */
    public static function items(): array
    {
        return [
            NavigationItem::make('Mi perfil')
                ->icon(Heroicon::OutlinedUserCircle)
                ->url(fn (): string => EditOwnProfile::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.inicio.auth.profile'))
                ->visible(fn (): bool => auth()->user()?->can('perfil.ver') ?? false)
                ->sort(1),
        ];
    }
}
