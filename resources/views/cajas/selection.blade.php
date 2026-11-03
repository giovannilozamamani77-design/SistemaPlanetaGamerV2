<div class="pg-cash-overview" aria-label="Resumen de cajas">

    @foreach (['total' => ['Cajas habilitadas', 'heroicon-o-squares-2x2'], 'open' => ['En operación', 'heroicon-o-building-storefront'], 'closed' => ['Cerradas', 'heroicon-o-lock-closed'], 'pending' => ['No disponibles', 'heroicon-o-clock']] as $key => [$label, $icon])

        <div class="pg-cash-metric pg-cash-metric-{{ $key }}">

            <span class="pg-cash-metric-icon" aria-hidden="true">@svg($icon)</span>

            <div><p>{{ $label }}</p><strong>{{ $summary[$key] }}</strong></div>

        </div>

    @endforeach

</div>

<div class="pg-cash-sections">

    @foreach ($groups as $family => $group)

        <section class="pg-cash-family {{ $family === 'BCP' ? 'pg-cash-family-bcp' : 'pg-cash-family-tigo' }}" aria-labelledby="cash-family-{{ $family }}">

            <header class="pg-cash-family-heading"><span class="pg-cash-family-mark" aria-hidden="true">{{ $family === 'BCP' ? 'BCP' : 'TM' }}</span><h2 id="cash-family-{{ $family }}">{{ $group['title'] }}</h2></header>

            <div class="pg-cash-cards">

        @forelse ($group['cards'] as $card)

            <article class="pg-cash-card" wire:key="cash-card-{{ $card['record']->id }}">

                <div class="pg-cash-card-top">

                    <span class="pg-cash-card-icon" aria-hidden="true">

                        @if ($family === 'BCP')

                            <x-heroicon-o-building-storefront />

                        @else

                            <x-heroicon-o-banknotes />

                        @endif

                    </span>

                    <span class="pg-cash-state pg-cash-state-{{ $card['state'] }}"><span aria-hidden="true"></span>{{ $card['status'] }}</span>

                </div>

                <div class="pg-cash-card-details">

                    <h3>{{ $card['record']->name }}</h3>

                    <p class="pg-cash-card-code">{{ $card['record']->code }}</p>

                    <div class="pg-cash-account"><span>Número de cuenta</span><strong>{{ $card['record']->account_reference ?: 'Sin número configurado' }}</strong></div>

                    <p class="pg-cash-card-description">{{ $card['description'] }}</p>

                </div>

                <div class="pg-cash-card-footer">

                    <span class="pg-cash-card-access"><x-heroicon-o-key aria-hidden="true" /> {{ $card['record']->has_own_pending_close || $card['record']->has_own_open_session ? 'Sesión propia' : 'PIN para abrir' }}</span>

                    {{ (clone $this->getTable()->getAction('ingresar'))->record($card['record'])->label($card['record']->has_own_pending_close ? 'Ingresar' : ($card['record']->has_own_open_session ? 'Continuar' : 'Ingresar'))->link()->icon('heroicon-o-arrow-right')->iconPosition(\Filament\Support\Enums\IconPosition::After) }}

                </div>

            </article>

        @empty

            <p class="pg-cash-empty">Sin cajas activas de este tipo.</p>

        @endforelse

            </div>

        </section>

    @endforeach

    @if ($cardStore)
        @include('tarjetas.cash-box-shortcut', ['status' => $cardStore])
    @endif

</div>

