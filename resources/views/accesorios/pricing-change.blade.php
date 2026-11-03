<div class="pg-pricing-change">
    @foreach(['before' => 'Anterior', 'after' => 'Nueva'] as $key => $title)
        <section><h3>{{ $title }} · revisión {{ $event->{$key}['revision'] }}</h3>
            <p>{{ $event->{$key}['exchange_mode'] === 'PHYSICAL' ? 'Dólar físico manual' : 'Referencia + ajuste' }}</p>
            <p>Físico: {{ \App\Domain\Accesorios\Data\PricingInput::rateText($event->{$key}['physical_rate_micros']) ?: 'Sin configurar' }} · Ajuste: {{ \App\Domain\Accesorios\Data\PricingInput::rateText($event->{$key}['adjustment_micros']) }}</p>
            @foreach(\App\Domain\Accesorios\Data\PricingInput::KINDS as $kind => $label)<p>{{ $label }}: {{ $event->{$key}['rules'][$kind]['mode'] === 'MANUAL' ? 'Manual' : \App\Support\Bolivianos::formatCompact($event->{$key}['rules'][$kind]['markup_bps']).' % sobre costo' }}</p>@endforeach
        </section>
    @endforeach
</div>
