<div>
    <livewire:notificaciones.inbox-updates />
    @php
        use Filament\Support\Facades\FilamentView;
        use Filament\Support\Icons\Heroicon;
        use Filament\View\PanelsRenderHook;
        $navigation = array_values(filament()->getNavigation());
    @endphp
    <aside
        id="fi-main-sidebar"
        class="fi-sidebar fi-main-sidebar pg-sidebar"
        x-data="{}"
        x-init="if (! Array.isArray($store.sidebar.collapsedGroups)) $store.sidebar.collapsedGroups = []"
        x-bind:class="{ 'fi-sidebar-open': $store.sidebar.isOpen }"
        x-on:keydown.escape.window="if (window.innerWidth < 1024) $store.sidebar.close()"
        x-cloak
        aria-label="Menú principal"
    >
        {{ FilamentView::renderHook(PanelsRenderHook::SIDEBAR_START) }}
        <header class="pg-sidebar-brand">
            {{ FilamentView::renderHook(PanelsRenderHook::SIDEBAR_LOGO_BEFORE) }}
            <a {{ \Filament\Support\generate_href_html(filament()->getHomeUrl()) }} aria-label="Planeta Gamer — Inicio">
                <x-filament-panels::logo />
            </a>
            {{ FilamentView::renderHook(PanelsRenderHook::SIDEBAR_LOGO_AFTER) }}
            <button type="button" class="pg-mobile-close" aria-label="Cerrar menú" x-on:click="$store.sidebar.close()">
                {{ \Filament\Support\generate_icon_html(Heroicon::OutlinedXMark) }}
            </button>
        </header>
        <nav
            class="fi-sidebar-nav pg-sidebar-nav"
            aria-label="Navegación del sistema"
            data-sidebar-scroll
            data-scroll-key="pg-sidebar-scroll:{{ filament()->getId() }}:{{ filament()->auth()->id() }}"
            x-data="{
                restoring: true,
                init() {
                    this.$nextTick(() => {
                        try {
                            const saved = Number(sessionStorage.getItem(this.$el.dataset.scrollKey));
                            if (Number.isFinite(saved) &amp;&amp; saved >= 0) {
                                this.$el.scrollTop = Math.min(saved, Math.max(0, this.$el.scrollHeight - this.$el.clientHeight));
                            }
                        } catch (error) {}
                        this.restoring = false;
                    });
                },
                remember() {
                    if (this.restoring) return;
                    try {
                        sessionStorage.setItem(this.$el.dataset.scrollKey, String(this.$el.scrollTop));
                    } catch (error) {}
                }
            }"
            x-on:scroll.debounce.150ms="remember()"
            x-on:click.capture="remember()"
            x-on:pagehide.window="remember()"
            x-on:livewire:navigating.document="remember()"
        >
            {{ FilamentView::renderHook(PanelsRenderHook::SIDEBAR_NAV_START) }}
            @foreach ($navigation as $group)
                @php
                    $groupLabel = $group->getLabel();
                    $groupText = trim(strip_tags($groupLabel instanceof \Illuminate\Contracts\Support\Htmlable ? $groupLabel->toHtml() : ($groupLabel ?? '')));
                    $groupId = 'pg-group-'.substr(hash('sha256', $groupText), 0, 12);
                @endphp
                @if (blank($groupText))
                    <div class="pg-nav-heading" x-show="$store.sidebar.isOpen">General</div>
                    @foreach ($group->getItems() as $item)
                        @include('filament.navigation.item', ['item' => $item])
                    @endforeach
                @else
                    @if ($loop->first || blank($navigation[$loop->index - 1]->getLabel()))
                        <div class="pg-nav-heading" x-show="$store.sidebar.isOpen">Gestión</div>
                    @endif
                    <section class="pg-nav-group" aria-label="{{ $groupText }}">
                        <div x-show="$store.sidebar.isOpen">
                            <button
                                type="button"
                                @class(['pg-group-button', 'pg-active' => $group->isActive()])
                                aria-controls="{{ $groupId }}"
                                x-bind:aria-expanded="! $store.sidebar.groupIsCollapsed(@js($groupText))"
                                x-on:click="$store.sidebar.toggleCollapsedGroup(@js($groupText))"
                            >
                                {{ \Filament\Support\generate_icon_html($group->getIcon() ?? Heroicon::OutlinedFolder) }}
                                <span>{{ $groupLabel }}</span>
                                <span class="pg-group-chevron" x-bind:class="{ 'pg-rotated': $store.sidebar.groupIsCollapsed(@js($groupText)) }">
                                    {{ \Filament\Support\generate_icon_html(Heroicon::OutlinedChevronUp) }}
                                </span>
                            </button>
                            <div id="{{ $groupId }}" class="pg-group-items" x-show="! $store.sidebar.groupIsCollapsed(@js($groupText))">
                                @foreach ($group->getItems() as $item)
                                    @include('filament.navigation.item', ['item' => $item])
                                @endforeach
                            </div>
                        </div>
                        <div x-show="! $store.sidebar.isOpen" x-cloak class="pg-compact-group">
                            <x-filament::dropdown placement="right-start" teleport>
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        @class(['pg-group-button', 'pg-active' => $group->isActive()])
                                        aria-label="{{ $groupText }}"
                                        x-tooltip="{ content: @js($groupText), placement: 'right', theme: $store.theme }"
                                    >
                                        {{ \Filament\Support\generate_icon_html($group->getIcon() ?? Heroicon::OutlinedFolder) }}
                                    </button>
                                </x-slot>
                                <div class="pg-nav-flyout">
                                    <div class="pg-flyout-heading">{{ $groupLabel }}</div>
                                    @foreach ($group->getItems() as $item)
                                        @include('filament.navigation.item', ['item' => $item])
                                    @endforeach
                                </div>
                            </x-filament::dropdown>
                        </div>
                    </section>
                @endif
            @endforeach
            {{ FilamentView::renderHook(PanelsRenderHook::SIDEBAR_NAV_END) }}
        </nav>
        <footer class="pg-sidebar-footer">
            @if (filament()->auth()->check() && filament()->hasUserMenu())
                <x-filament-panels::user-menu />
            @endif
            {{ FilamentView::renderHook(PanelsRenderHook::SIDEBAR_FOOTER) }}
        </footer>
    </aside>
    <x-filament-actions::modals />
</div>
