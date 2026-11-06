<?php

namespace App\Providers\Filament;

use App\Filament\Navigation\PanelNavigation;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Livewire\Navigation\Sidebar;
use App\Livewire\Navigation\Topbar;
use Filament\Enums\ThemeMode;
use Filament\Enums\UserMenuPosition;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class InicioPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('inicio')
            ->path('inicio')
            ->login()
            ->brandName('Planeta Gamer')
            ->brandLogo(fn () => view('filament.branding.logo'))
            ->brandLogoHeight('2.5rem')
            ->defaultThemeMode(ThemeMode::Dark)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('230px')
            ->collapsedSidebarWidth('76px')
            ->sidebarLivewireComponent(Sidebar::class)
            ->topbarLivewireComponent(Topbar::class)
            ->userMenu(position: UserMenuPosition::Sidebar)
            ->navigationGroups(PanelNavigation::groups())
            ->navigationItems(PanelNavigation::items())
            ->viteTheme('resources/css/filament/inicio/theme.css')
            ->renderHook(PanelsRenderHook::BODY_END, function () {
                session()->forget(['cajas.close_draft', 'cajas.recovery_login', 'cajas.clear_close_draft']);

                return view('cajas.retired-storage-cleanup');
            })
            ->profile(EditOwnProfile::class, isSimple: false)
            ->colors([
                'primary' => Color::Violet,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
