<x-filament-panels::page>
    <nav class="flex flex-wrap gap-3" aria-label="Tasas por moneda"><x-filament::button tag="a" :href="\App\Filament\Pages\Envios\UsdExchangeRates::getUrl()" aria-current="page">Dólar · USD</x-filament::button><x-filament::button color="gray" tag="a" :href="\App\Filament\Pages\Envios\BrazilExchangeRates::getUrl()">Brasil · BRL</x-filament::button></nav>
    @php($overview=$this->overview)
    @php($reference=$overview['reference'])
    @php($current=$overview['current'])
    @php($preview=$this->preview)
    <div class="pg-usd-heading"><span class="pg-usd-symbol">$</span><div><h2>Dólar estadounidense</h2><p>Referencia de mercado y precios de tu negocio, en un solo lugar.</p></div><span class="pg-usd-pill">USD / BOB</span></div>
    @if($this->referenceError)<div role="alert" class="pg-usd-alert">{{ $this->referenceError }}</div>@endif
    <div class="pg-usd-summary">
        <article><p>Referencia USD / BOB</p><strong>{{ $reference ? 'Bs '.app(\App\Domain\Envios\Calculators\UsdRateCalculator::class)->label((int)$reference->rate) : 'Sin referencia' }}</strong><small>Por USD 1 · No es compra ni venta</small>
            @if($reference)<small>Actualizada: {{ \Carbon\CarbonImmutable::parse($reference->quoted_at)->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</small><span class="pg-usd-pill">{{ \Carbon\CarbonImmutable::parse($reference->next_update_at)->isPast() ? 'Pendiente de actualizar' : 'Último dato disponible' }}</span>@endif
            <a href="https://www.exchangerate-api.com" target="_blank" rel="noopener noreferrer">Rates by ExchangeRate-API</a>
        </article>
        <article><p>Compra vigente</p><strong>{{ $current ? 'Bs '.app(\App\Domain\Envios\Calculators\UsdRateCalculator::class)->label($current->buy_rate) : 'Sin configurar' }}</strong><small>Pagamos al cliente por USD 1.</small></article>
        <article><p>Venta vigente</p><strong>{{ $current ? 'Bs '.app(\App\Domain\Envios\Calculators\UsdRateCalculator::class)->label($current->sell_rate) : 'Sin configurar' }}</strong><small>Cobramos al cliente por USD 1.</small></article>
    </div>
    <div class="pg-usd-layout">
        <form wire:submit="save" class="pg-usd-form">
            {{ $this->form }}
            <div class="pg-usd-footer"><p>Guardar crea una versión nueva. No cambia las tasas de Brasil ni mueve dinero.</p><x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="save">Guardar tasas</x-filament::button></div>
        </form>
        <aside class="pg-usd-preview" aria-live="polite"><span class="pg-usd-pill">Simulación</span><h3>Vista previa</h3><p>Así se aplicarán los precios que estás escribiendo.</p>
            @if($preview['valid'])
                <div><small>Si recibimos USD {{ \App\Support\Bolivianos::format($preview['usd_cents']) }}</small><p>Pagamos al cliente</p><strong>Bs {{ \App\Support\Bolivianos::format($preview['buy_total']) }}</strong></div>
                <div><small>Si entregamos USD {{ \App\Support\Bolivianos::format($preview['usd_cents']) }}</small><p>Cobramos al cliente</p><strong>Bs {{ \App\Support\Bolivianos::format($preview['sell_total']) }}</strong></div>
                <p>No es una operación ni una ganancia registrada. Los costos reales determinan el margen.</p>
            @else<p>Completa compra, venta e importe para ver la simulación.</p><small>{{ $preview['message'] }}</small>@endif
            <hr><p>La API aporta una referencia diaria, sin compra y venta separadas. Tú decides los precios y estos se mantienen hasta que Administración los cambie.</p>
        </aside>
    </div>
    <section class="pg-usd-history"><header><h3>Últimos cambios</h3><p>Las cinco versiones más recientes · Horario de Bolivia</p></header>
        @forelse($overview['history'] as $version)
            <article><div><strong>{{ $version->created_at->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</strong><small>{{ $version->creator?->name }} · Versión #{{ $version->id }}</small></div><div><small>Compra / Venta por USD 1</small><strong>Bs {{ app(\App\Domain\Envios\Calculators\UsdRateCalculator::class)->label($version->buy_rate) }} / {{ app(\App\Domain\Envios\Calculators\UsdRateCalculator::class)->label($version->sell_rate) }}</strong></div><p>{{ $version->reason }}</p></article>
        @empty<p class="pg-usd-empty">Todavía no se han configurado precios del dólar.</p>@endforelse
    </section>
</x-filament-panels::page>
