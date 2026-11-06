<x-filament-panels::page>
    <div class="pg-home" lang="es" translate="no" data-home-dashboard="{{ $dashboard['administrative'] ? 'administration' : 'personal' }}">
        <header class="pg-home-welcome">
            <div>
                <p class="pg-home-eyebrow">PLANETA GAMER <span>/</span> INICIO</p>
                <h1>Hola, {{ $dashboard['name'] }}<span class="pg-home-greeting-dot">.</span></h1>
                <p class="pg-home-intro">{{ $dashboard['administrative'] ? 'Tu negocio, de un vistazo. Elige dónde quieres trabajar.' : 'Todo listo para tu jornada. Entra directo a lo que necesitas.' }}</p>
            </div>
            <div class="pg-home-welcome-side">
                <span class="pg-home-role"><span></span>{{ $dashboard['administrative'] ? 'Administración' : 'Mi jornada' }}</span>
                <p>{{ $dashboard['date'] }} <span>· Bolivia</span></p>
            </div>
        </header>

        <section aria-labelledby="pg-home-shortcuts-title">
            <div class="pg-home-section-heading">
                <div><h2 id="pg-home-shortcuts-title">Accesos directos</h2><p>Tu próxima operación, a un clic.</p></div>
                <a href="#pg-home-directory" class="pg-home-text-link">Ver todos los accesos <x-filament::icon icon="heroicon-o-arrow-down" /></a>
            </div>
            <div class="pg-home-module-cards">
                @forelse ($shortcuts as $shortcut)
                    <article class="pg-home-module-card pg-home-tone-{{ $shortcut['tone'] }}" data-home-shortcut="{{ $shortcut['title'] }}">
                        <header><span class="pg-home-module-icon"><x-filament::icon :icon="$shortcut['icon']" /></span><div><span class="pg-home-module-kicker">MÓDULO</span><h3>{{ $shortcut['title'] }}</h3></div><a href="{{ $shortcut['url'] }}" aria-label="Abrir {{ $shortcut['title'] }}">Abrir <x-filament::icon icon="heroicon-o-arrow-up-right" /></a></header>
                        <div class="pg-home-module-body">
                            @if ($shortcut['title'] === 'Cajas' && $dashboard['operations']['boxes'])
                                <p class="pg-home-module-caption">{{ $dashboard['administrative'] ? 'Estado actual de tus cajas' : 'Cajas abiertas a tu cargo' }}</p>
                                @forelse ($dashboard['operations']['boxes']['items'] as $box)
                                    <div class="pg-home-box-row"><div><strong>{{ $box['name'] }}</strong><small>{{ $box['type'] }}</small></div><span class="pg-home-state pg-home-tone-{{ $box['tone'] }}">{{ $box['label'] }}</span></div>
                                @empty
                                    <p class="pg-home-module-message">{{ $dashboard['administrative'] ? 'Todavía no hay cajas activas configuradas.' : 'No tienes una caja abierta. Entra al catálogo para elegir una.' }}</p>
                                @endforelse
                            @elseif ($shortcut['title'] === 'Préstamos' && $dashboard['operations']['loans'])
                                <p class="pg-home-module-caption">{{ $dashboard['administrative'] ? 'Préstamos pendientes por estado' : 'Tus préstamos pendientes por estado' }}</p>
                                <div class="pg-home-loan-stats">@foreach ($dashboard['operations']['loans']['rows'] as $row)<div class="pg-home-tone-{{ $row['tone'] }}"><strong>{{ $row['value'] }}</strong><span>{{ $row['label'] }}</span></div>@endforeach</div>
                                <p class="pg-home-module-message">Revisa vencimientos, renovaciones y pagos desde el módulo.</p>
                            @elseif ($shortcut['title'] === 'Tarjetas' && $dashboard['operations']['cards'])
                                @if ($dashboard['operations']['cards']['initialized'])
                                    <p class="pg-home-module-caption">Stock disponible en tienda</p><strong class="pg-home-module-total">{{ $dashboard['operations']['cards']['total'] }}</strong><p class="pg-home-module-caption">Tarjetas físicas y Cabina Viva</p>
                                    <div class="pg-home-zero-stock">@foreach ($dashboard['operations']['cards']['zero_products'] as $product)<div><span>{{ $product }}</span><strong>Sin stock</strong></div>@endforeach</div>
                                @else
                                    <p class="pg-home-module-caption">Inventario de tienda</p><strong class="pg-home-module-pending">Pendiente de conteo</strong><p class="pg-home-module-message">Las existencias aparecerán después del conteo inicial confirmado.</p>
                                @endif
                            @else
                                @php($moduleActivity = collect($dashboard['chart'])->firstWhere('tone', $shortcut['tone']))
                                <p class="pg-home-module-caption">{{ $dashboard['administrative'] ? 'Cobros registrados hoy' : 'Tus ventas registradas hoy' }}</p><strong class="pg-home-module-total">{{ $moduleActivity['display'] ?? '—' }}</strong><p class="pg-home-module-message">Accesorios y teléfonos, con sus comprobantes e inventario en un mismo lugar.</p>
                            @endif
                        </div>
                        <a href="{{ $shortcut['url'] }}" class="pg-home-module-action">{{ match ($shortcut['title']) { 'Cajas' => 'Ir a cajas', 'Préstamos' => 'Registrar o revisar préstamos', 'Tarjetas' => 'Ir a tarjetas', default => 'Ir a ventas' } }}<x-filament::icon icon="heroicon-o-arrow-right" /></a>
                    </article>
                @empty
                    <div class="pg-home-empty pg-home-empty-wide"><x-filament::icon icon="heroicon-o-lock-closed" /><p>Todavía no tienes acceso a los módulos de operación. Consulta con Administración.</p></div>
                @endforelse
            </div>
        </section>

        <div class="pg-home-operation-charts">
            @foreach (['boxes' => ['Estado de cajas', 'Disponibilidad actual'], 'loans' => ['Préstamos pendientes', 'Seguimiento por estado']] as $key => [$title, $subtitle])
                @if ($dashboard['operations'][$key])
                    <section class="pg-home-panel pg-home-status-chart" aria-label="{{ $title }}">
                        <div class="pg-home-status-heading"><h2>{{ $title }}</h2><p>{{ $dashboard['administrative'] ? $subtitle : 'Solo tus registros' }}</p></div>
                        <div class="pg-home-donut" style="background: {{ $dashboard['operations'][$key]['gradient'] }}" role="img" aria-label="{{ $title }}: {{ collect($dashboard['operations'][$key]['rows'])->map(fn ($row) => $row['label'].': '.$row['value'])->implode(', ') }}"><span><strong>{{ $dashboard['operations'][$key]['total'] }}</strong><small>{{ $key === 'boxes' ? 'cajas' : 'préstamos' }}</small></span></div>
                        <ul class="pg-home-status-legend">@foreach ($dashboard['operations'][$key]['rows'] as $row)<li class="pg-home-tone-{{ $row['tone'] }}"><i aria-hidden="true"></i><span>{{ $row['label'] }}</span><strong>{{ $row['value'] }}</strong></li>@endforeach</ul>
                        @if (! $dashboard['operations'][$key]['total'])<p class="pg-home-footnote">Sin registros en este alcance.</p>@endif
                    </section>
                @endif
            @endforeach
            @if ($dashboard['operations']['cards'])
                <section class="pg-home-panel pg-home-status-chart" aria-label="Tarjetas en tienda por empresa">
                    <div class="pg-home-status-heading"><h2>Tarjetas en tienda</h2><p>Unidades disponibles por empresa</p></div>
                    @if ($dashboard['operations']['cards']['initialized'])
                        @php($maximumStock = max(1, ...array_column($dashboard['operations']['cards']['rows'], 'value')))
                        <div class="pg-home-stock-chart" role="figure" aria-label="Stock por empresa">
                            @foreach ($dashboard['operations']['cards']['rows'] as $row)<div class="pg-home-stock-column"><strong>{{ $row['value'] }}</strong><div class="pg-home-stock-track"><span style="height: {{ $row['value'] * 100 / $maximumStock }}%"></span></div><span>{{ $row['label'] }}</span></div>@endforeach
                        </div>
                        <p class="pg-home-footnote">Tarjetas físicas y Cabina Viva. Las recargas digitales se consultan en su módulo.</p>
                    @else
                        <div class="pg-home-empty"><x-filament::icon icon="heroicon-o-clipboard-document-list" /><p>Pendiente de conteo inicial. No se muestran existencias sin confirmar.</p></div>
                    @endif
                </section>
            @endif
        </div>

        <section aria-labelledby="pg-home-summary-title">
            <div class="pg-home-section-heading">
                <div><h2 id="pg-home-summary-title">{{ $dashboard['administrative'] ? 'El día en números' : 'Tu día en números' }}</h2><p>{{ $dashboard['administrative'] ? 'Resumen de lo registrado hoy en el sistema.' : 'Solo tus registros y tus comisiones personales.' }}</p></div>
                <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="pg-home-refresh" aria-label="Actualizar resumen de inicio"><x-filament::icon icon="heroicon-o-arrow-path" wire:loading.class="animate-spin" /><span>Actualizar</span></button>
            </div>
            @if ($dashboard['metrics'])
                <div class="pg-home-metrics">
                    @foreach ($dashboard['metrics'] as $metric)
                        <article class="pg-home-metric pg-home-tone-{{ $metric['tone'] }}">
                            <div class="pg-home-metric-label"><span>{{ $metric['label'] }}</span><x-filament::icon :icon="$metric['icon']" /></div>
                            <strong>{{ $metric['value'] }}</strong>
                            <p>{{ $metric['note'] }}</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="pg-home-insights">
            <section class="pg-home-panel" aria-labelledby="pg-home-chart-title">
                <div class="pg-home-panel-heading"><div><span class="pg-home-eyebrow">{{ $dashboard['administrative'] ? 'DISTRIBUCIÓN DEL DÍA' : 'TU ACTIVIDAD' }}</span><h2 id="pg-home-chart-title">{{ $dashboard['administrative'] ? 'Cobros por módulo' : 'Tus registros de hoy' }}</h2></div><span class="pg-home-small-badge">Hoy</span></div>
                @if ($dashboard['has_activity'])
                    <div class="pg-home-chart" role="figure" aria-labelledby="pg-home-chart-title" aria-describedby="pg-home-chart-note">
                        <div class="pg-home-chart-total"><strong>{{ $dashboard['chart_total'] }}</strong><span>{{ $dashboard['administrative'] ? 'cobros registrados' : 'registros entre tus módulos' }}</span></div>
                        <div class="pg-home-stacked-bar" aria-hidden="true">
                            @foreach ($dashboard['chart'] as $row)
                                @if ($row['percent'] > 0)<span class="pg-home-tone-{{ $row['tone'] }}" style="flex-grow: {{ $row['value'] }}"></span>@endif
                            @endforeach
                        </div>
                        <ul class="pg-home-chart-legend">
                            @foreach ($dashboard['chart'] as $row)
                                <li class="pg-home-tone-{{ $row['tone'] }}"><span class="pg-home-legend-dot" aria-hidden="true"></span><span>{{ $row['label'] }}</span><strong>{{ $row['display'] }}</strong><small>{{ $row['percent'] }}%</small></li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="pg-home-empty"><span class="pg-home-empty-chart" aria-hidden="true"><i></i><i></i><i></i><i></i></span><h3>{{ $dashboard['administrative'] ? 'El día está por empezar' : 'Tu primera operación te espera' }}</h3><p>{{ $dashboard['administrative'] ? 'Todavía no hay cobros registrados hoy. Aquí aparecerá su distribución por módulo.' : 'Cuando registres operaciones, verás aquí tu actividad de hoy.' }}</p></div>
                @endif
                <p id="pg-home-chart-note" class="pg-home-footnote">{{ $dashboard['administrative'] ? 'Los cobros de Préstamos incluyen capital recuperado. Cobros y ventas no equivalen a utilidad neta.' : 'Cajas: operaciones vigentes. Tarjetas: ventas y recargas. Préstamos: nuevos registros no anulados. Accesorios: tickets de venta.' }}</p>
                @if ($reportsUrl)<a href="{{ $reportsUrl }}" class="pg-home-panel-link">Abrir reportes y ver el detalle <x-filament::icon icon="heroicon-o-arrow-right" /></a>@endif
            </section>

            <section class="pg-home-panel" aria-labelledby="pg-home-work-title">
                <div class="pg-home-panel-heading"><div><span class="pg-home-eyebrow">CONTINÚA DONDE TE QUEDASTE</span><h2 id="pg-home-work-title">Tu espacio de trabajo</h2></div><x-filament::icon icon="heroicon-o-command-line" class="pg-home-panel-decoration" /></div>
                <div class="pg-home-work-items">
                    @foreach ($dashboard['sessions'] as $session)
                        <a href="{{ \App\Filament\Pages\Cajas\CashBoxCatalog::getUrl(['cashBox' => $session['box_id']]) }}" class="pg-home-work-link"><span class="pg-home-work-icon"><x-filament::icon icon="heroicon-o-building-storefront" /></span><span><strong>{{ $session['name'] }}</strong><small>Abierta por ti · {{ $session['opened_at'] }}</small></span><span class="pg-home-open-badge">Continuar</span><x-filament::icon icon="heroicon-o-chevron-right" /></a>
                    @endforeach
                    @if ($boxesUrl && ! $dashboard['sessions'])
                        <a href="{{ $boxesUrl }}" class="pg-home-work-link"><span class="pg-home-work-icon"><x-filament::icon icon="heroicon-o-building-storefront" /></span><span><strong>Elige tu caja</strong><small>No tienes una sesión de caja abierta.</small></span><x-filament::icon icon="heroicon-o-chevron-right" /></a>
                    @elseif ($boxesUrl && $dashboard['owned_boxes'] > 3)
                        <a href="{{ $boxesUrl }}" class="pg-home-text-link">Ver todas tus cajas</a>
                    @endif
                    @if ($cardsUrl && $dashboard['owns_card_store'])
                        <a href="{{ $cardsUrl }}" class="pg-home-work-link"><span class="pg-home-work-icon"><x-filament::icon icon="heroicon-o-credit-card" /></span><span><strong>Tu turno de Tarjetas</strong><small>La tienda está a tu cargo.</small></span><span class="pg-home-open-badge">Continuar</span><x-filament::icon icon="heroicon-o-chevron-right" /></a>
                    @endif
                    @if ($profileUrl)
                        <a href="{{ $profileUrl }}" class="pg-home-work-link"><span class="pg-home-work-icon"><x-filament::icon icon="heroicon-o-user-circle" /></span><span><strong>Mi perfil</strong><small>Comisiones, asistencia y pendientes personales.</small></span><x-filament::icon icon="heroicon-o-chevron-right" /></a>
                    @endif
                </div>
                @if (! $boxesUrl && ! $profileUrl && ! ($cardsUrl && $dashboard['owns_card_store']))<div class="pg-home-empty"><p>Encuentra tus pantallas disponibles en Todos los accesos.</p></div>@endif
                <div class="pg-home-flow" aria-label="Flujo de trabajo: elige un módulo, registra allí la operación y consulta su historial"><span><b>01</b>Elige un módulo</span><x-filament::icon icon="heroicon-o-arrow-right" /><span><b>02</b>Registra</span><x-filament::icon icon="heroicon-o-arrow-right" /><span><b>03</b>Consulta</span></div>
                <p class="pg-home-footnote">Los accesos conservan las validaciones de cada módulo.</p>
            </section>
        </div>

        <section id="pg-home-directory" aria-labelledby="pg-home-directory-title">
            <div class="pg-home-section-heading"><div><h2 id="pg-home-directory-title">Todos los accesos</h2><p>Explora tus herramientas por área.</p></div><span class="pg-home-directory-caption">Según tus permisos</span></div>
            <div class="pg-home-directory">
                @forelse ($directory as $group)
                    <details class="pg-home-directory-group">
                        <summary><span class="pg-home-directory-folder"><x-filament::icon icon="heroicon-o-squares-2x2" /></span><strong>{{ $group['label'] }}</strong><span class="pg-home-directory-count">{{ count($group['links']) }}</span><x-filament::icon icon="heroicon-o-chevron-down" class="pg-home-directory-chevron" /></summary>
                        <div class="pg-home-directory-links">@foreach ($group['links'] as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}<x-filament::icon icon="heroicon-o-arrow-up-right" /></a>@endforeach</div>
                    </details>
                @empty
                    <p class="pg-home-footnote">No tienes otros accesos habilitados. Consulta con Administración.</p>
                @endforelse
            </div>
        </section>
        <footer class="pg-home-footer"><span><span class="pg-home-live-dot"></span>Datos registrados en Planeta Gamer</span><span>Consultado a las {{ $dashboard['checked_at'] }} · Bolivia</span></footer>
    </div>
</x-filament-panels::page>
