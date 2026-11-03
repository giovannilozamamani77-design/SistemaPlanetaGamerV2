@php
    use Filament\Support\Facades\FilamentView;
    use Filament\Support\Icons\Heroicon;
    use Filament\View\PanelsRenderHook;
    $activeGroup = collect(filament()->getNavigation())->first(fn ($group) => $group->isActive());
@endphp
<div class="fi-topbar-ctn pg-topbar-ctn" x-data="{}" x-bind:class="{ 'pg-sidebar-expanded': $store.sidebar.isOpen }">
    <nav class="pg-topbar" aria-label="Cabecera del sistema">
        {{ FilamentView::renderHook(PanelsRenderHook::TOPBAR_START) }}
        <div class="pg-topbar-start">
            <button
                type="button"
                class="pg-sidebar-toggle"
                aria-controls="fi-main-sidebar"
                x-bind:aria-expanded="$store.sidebar.isOpen"
                x-bind:aria-label="$store.sidebar.isOpen ? 'Contraer menú' : 'Expandir menú'"
                x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
            >
                {{ \Filament\Support\generate_icon_html(Heroicon::OutlinedBars3) }}
            </button>
            <span class="pg-topbar-product">Planeta Gamer</span>
            <span class="pg-topbar-divider" aria-hidden="true">/</span>
            <span class="pg-topbar-location">{{ $activeGroup?->getLabel() ?: 'General' }}</span>
        </div>
        <div class="pg-topbar-end">
            {{ FilamentView::renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE) }}
            @if (filament()->isGlobalSearchEnabled())
                @livewire(\Filament\Livewire\GlobalSearch::class)
            @endif
            {{ FilamentView::renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER) }}
            <time class="pg-topbar-date" datetime="{{ now('America/La_Paz')->toDateString() }}">{{ now('America/La_Paz')->locale('es')->isoFormat('D MMM YYYY') }}</time>
            @if (filament()->auth()->check() && filament()->hasDatabaseNotifications())
                @livewire(filament()->getDatabaseNotificationsLivewireComponent(), [
                    'lazy' => filament()->hasLazyLoadedDatabaseNotifications(),
                ])
            @endif
        </div>
        {{ FilamentView::renderHook(PanelsRenderHook::TOPBAR_END) }}
    </nav>
    <x-filament-actions::modals />
</div>
