<x-filament-panels::page>
    <div class="pg-envios-summary">
        @foreach (['pending'=>['Pendientes de resolver','heroicon-o-clock'],'held'=>['Fondos separados en cajas','heroicon-o-banknotes'],'completed'=>['Envíos completados','heroicon-o-check-circle']] as $key => [$label,$icon])
            <article><span>@svg($icon)</span><div><p>{{ $label }}</p><strong>{{ $key === 'held' ? 'Bs '.\App\Support\Bolivianos::format($this->overview[$key]) : $this->overview[$key] }}</strong></div></article>
        @endforeach
    </div>
    <section class="pg-envios-rates">
        <div><strong>Tasas finales para nuevas cotizaciones</strong><p>{{ $this->rates['enabled'] ? 'Margen incluido. Cada solicitud conserva su cotización aceptada.' : 'Administración debe configurar y habilitar las tasas antes de cotizar.' }}</p></div>
        @if ($this->rates['enabled'])<div><small>Bolivia → Brasil</small><strong>Bs {{ $this->rates['sell'] }} / R$ 1</strong></div><div><small>Brasil → Bolivia</small><strong>Bs {{ $this->rates['buy'] }} / R$ 1</strong></div>@endif
        @if ($reference = $this->rates['reference'])<p class="pg-envios-reference">Referencia informativa: Bs {{ \App\Filament\Support\Envios\InternationalTransferActions::rateLabel((int) $reference->rate) }} / R$ 1 · {{ \Carbon\CarbonImmutable::parse($reference->quoted_at)->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}. {{ \Carbon\CarbonImmutable::parse($reference->next_update_at)->isPast() ? 'Pendiente de actualizar; no se usa como costo confirmado.' : 'No sustituye el costo de fondeo confirmado.' }} <a href="https://www.exchangerate-api.com" target="_blank" rel="noopener noreferrer">Rates by ExchangeRate-API</a></p>@endif
    </section>
    @if (!config('envios.cash_box_integration_ready'))<x-filament::section heading="Integración financiera pendiente de verificación" description="Puedes revisar la configuración y cotizaciones. Los cobros, entregas y comisiones están bloqueados hasta completar la revisión de Cajas." />@endif
    {{ $this->table }}
</x-filament-panels::page>
