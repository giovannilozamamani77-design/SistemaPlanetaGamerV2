<x-filament-panels::page>
    @php($o=$this->overview)
    @php($ref=$o['reference'])
    @php($p=$o['current'])
    @php($preview=$this->preview)
    <nav class="flex flex-wrap gap-3" aria-label="Tasas por moneda"><x-filament::button color="gray" tag="a" :href="\App\Filament\Pages\Envios\UsdExchangeRates::getUrl()">Dólar · USD</x-filament::button><x-filament::button tag="a" :href="\App\Filament\Pages\Envios\BrazilExchangeRates::getUrl()" aria-current="page">Brasil · BRL</x-filament::button></nav>
    <div class="pg-usd-heading"><span class="pg-usd-symbol">R$</span><div><h2>Brasil · Real brasileño</h2><p>Márgenes incluidos en compra y venta. Cotizaciones anteriores conservan sus valores.</p></div><span class="pg-usd-pill">BRL / BOB</span></div>
    @if($this->referenceError)<div class="pg-usd-alert" role="alert">{{ $this->referenceError }}</div>@endif
    <div class="pg-usd-summary">
        <article><p>Referencia BRL / BOB</p><strong>{{ $ref ? 'Bs '.\App\Filament\Support\Envios\InternationalTransferActions::rateLabel((int)$ref->rate) : 'Sin referencia' }}</strong><small>Por R$ 1 · No es un costo de fondos confirmado</small>
            @if($ref)<small>{{ \Carbon\CarbonImmutable::parse($ref->quoted_at)->setTimezone('America/La_Paz')->format('d/m/Y H:i') }} · Bolivia</small><span class="pg-usd-pill">{{ \Carbon\CarbonImmutable::parse($ref->next_update_at)->isPast() ? 'Pendiente de actualizar' : 'Último dato disponible' }}</span><x-filament::button color="gray" size="sm" wire:click="useReference" wire:loading.attr="disabled">Usar como borrador de base</x-filament::button>@endif
            <a href="https://www.exchangerate-api.com" target="_blank" rel="noopener noreferrer">Rates by ExchangeRate-API</a>
        </article>
        <article><p>Compra vigente · Brasil → Bolivia</p><strong>{{ $p ? 'Bs '.\App\Filament\Support\Envios\InternationalTransferActions::rateLabel($p->buy_rate) : 'Sin configurar' }}</strong><small>Por R$ 1 · Base menos margen.</small></article>
        <article><p>Venta vigente · Bolivia → Brasil</p><strong>{{ $p ? 'Bs '.\App\Filament\Support\Envios\InternationalTransferActions::rateLabel($p->sell_rate) : 'Sin configurar' }}</strong><small>Por R$ 1 · Base más margen.</small></article>
    </div>
    <div class="pg-usd-layout"><form wire:submit="save" class="pg-usd-form">{{ $this->form }}<div class="pg-usd-footer"><p>Guardar crea una versión. No registra comisiones ni movimientos de caja.</p><x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="save">Guardar tasas de Brasil</x-filament::button></div></form>
        <aside class="pg-usd-preview" aria-live="polite"><span class="pg-usd-pill">Simulación</span><h3>Vista previa</h3>
            @if($preview['valid'])
                @foreach(['out'=>['Bolivia → Brasil','Venta',$preview['sell']],'in'=>['Brasil → Bolivia','Compra',$preview['buy']]] as $key=>[$label,$kind,$rate])
                    <div><h4>{{ $label }}</h4><small>{{ $kind }}: Bs {{ \App\Filament\Support\Envios\InternationalTransferActions::rateLabel($rate) }} por real</small><p>R$ {{ \App\Support\Bolivianos::format($preview[$key]['brl_cents']) }} ↔ Bs {{ \App\Support\Bolivianos::format($preview[$key]['bob_cents']) }}</p><small>Margen estimado incluido</small><strong>Bs {{ \App\Support\Bolivianos::format($preview[$key]['margin_cents']) }}</strong></div>
                @endforeach
                <p>Depende de que el costo base confirmado sea real. La comisión se reconoce solo al completar el envío o pago según el circuito vigente.</p>
            @else<p>Completa base, márgenes e importe para ver ambas conversiones.</p><small>{{ $preview['message'] }}</small>@endif
            <hr><p>La referencia diaria de la API no tiene compra y venta separadas. Actualizarla no cambia tus tasas guardadas.</p>
        </aside>
    </div>
    <section class="pg-usd-history"><header><h3>Últimos cambios de Brasil</h3><p>Últimas cinco versiones · Horario de Bolivia</p></header>
        @forelse($o['history'] as $v)<article><div><strong>{{ $v->created_at->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</strong><small>{{ $v->actor_name }} · #{{ $v->id }} · {{ $v->enabled ? 'Habilitado' : 'Deshabilitado' }}</small></div><div><small>Base / Compra / Venta por R$ 1</small><strong>{{ \App\Filament\Support\Envios\InternationalTransferActions::rateLabel($v->base_rate) }} / {{ \App\Filament\Support\Envios\InternationalTransferActions::rateLabel($v->buy_rate) }} / {{ \App\Filament\Support\Envios\InternationalTransferActions::rateLabel($v->sell_rate) }}</strong></div><p>{{ $v->cost_source }}</p></article>@empty<p class="pg-usd-empty">Aún no hay tasas de Brasil configuradas.</p>@endforelse
    </section>
</x-filament-panels::page>
