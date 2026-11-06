<?php

namespace App\Filament\Pages;

use App\Domain\Inicio\Queries\HomeDashboardQuery;
use App\Filament\Pages\Accesorios\AccessoryInventory;
use App\Filament\Pages\Accesorios\AccessoryQuickSale;
use App\Filament\Pages\Cajas\CashBoxCatalog;
use App\Filament\Pages\Prestamos\LoanList;
use App\Filament\Pages\Prestamos\RegisterLoan;
use App\Filament\Pages\Reportes\DailyReport;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Filament\Pages\Tarjetas\StoreInventory;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;

class Dashboard extends BaseDashboard
{
    protected string $view = 'inicio.dashboard';

    protected static ?string $title = 'Inicio';

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('panel.acceder');
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getViewData(): array
    {
        $dashboard = app(HomeDashboardQuery::class)->execute(auth()->user());
        $groups = [];
        $available = [];
        foreach (Filament::getNavigation() as $group) {
            $links = $this->navigationLinks($group->getItems());
            if ($links === []) {
                continue;
            }
            $groups[] = ['label' => $group->getLabel() ?: 'Tu cuenta', 'links' => $links];
            foreach ($links as $link) {
                $available[$link['url']] = true;
            }
        }
        $shortcuts = [];
        foreach ([
            ['title' => 'Cajas', 'subtitle' => 'Apertura, operaciones y cierre', 'icon' => 'heroicon-o-building-storefront', 'tone' => 'violet', 'pages' => [CashBoxCatalog::class]],
            ['title' => 'Tarjetas', 'subtitle' => 'Ventas, recargas e inventario', 'icon' => 'heroicon-o-credit-card', 'tone' => 'cyan', 'pages' => [QuickCardSale::class, StoreInventory::class]],
            ['title' => 'Préstamos', 'subtitle' => 'Registro, pagos y seguimiento', 'icon' => 'heroicon-o-document-text', 'tone' => 'amber', 'pages' => [RegisterLoan::class, LoanList::class]],
            ['title' => 'Accesorios', 'subtitle' => 'Ventas de productos y teléfonos', 'icon' => 'heroicon-o-shopping-bag', 'tone' => 'rose', 'pages' => [AccessoryQuickSale::class, AccessoryInventory::class]],
        ] as $shortcut) {
            foreach ($shortcut['pages'] as $page) {
                $url = $page::getUrl();
                if (isset($available[$url])) {
                    unset($shortcut['pages']);
                    $shortcuts[] = [...$shortcut, 'url' => $url];
                    break;
                }
            }
        }

        return [
            'dashboard' => $dashboard,
            'shortcuts' => $shortcuts,
            'directory' => $groups,
            'profileUrl' => isset($available[EditOwnProfile::getUrl()]) ? EditOwnProfile::getUrl() : null,
            'reportsUrl' => $dashboard['administrative'] ? DailyReport::getUrl() : null,
            'boxesUrl' => $dashboard['can_operate_boxes'] ? CashBoxCatalog::getUrl() : null,
            'cardsUrl' => isset($available[QuickCardSale::getUrl()]) ? QuickCardSale::getUrl() : null,
        ];
    }

    /** @param iterable<NavigationItem> $items */
    private function navigationLinks(iterable $items): array
    {
        $links = [];
        foreach ($items as $item) {
            if (! $item->isVisible()) {
                continue;
            }
            $url = $item->getUrl();
            if ($url && $url !== static::getUrl()) {
                $links[] = ['label' => $item->getLabel(), 'url' => $url];
            }
            array_push($links, ...$this->navigationLinks($item->getChildItems()));
        }

        return $links;
    }
}
