<section class="pg-pricing-result" role="status" aria-live="polite">
    <h2>Resultado de la simulación</h2>
    <dl>
        <div><dt>Costo de compra en bolivianos</dt><dd>Bs {{ \App\Domain\Accesorios\Data\PricingInput::signedAmount($result['historical_cents']) }}</dd></div>
        @if($result['replacement_cents'] !== null)<div><dt>Costo al dólar actual</dt><dd>Bs {{ \App\Domain\Accesorios\Data\PricingInput::signedAmount($result['replacement_cents']) }}</dd></div>@endif
        <div><dt>Costo usado para calcular</dt><dd>Bs {{ \App\Domain\Accesorios\Data\PricingInput::signedAmount($result['base_cents']) }}</dd></div>
        <div data-tone="price"><dt>Precio {{ $result['price_mode'] === 'MANUAL' ? 'manual' : 'sugerido' }}</dt><dd>Bs {{ \App\Domain\Accesorios\Data\PricingInput::signedAmount($result['price_cents']) }}</dd></div>
        <div><dt>Ganancia frente a la compra</dt><dd>Bs {{ \App\Domain\Accesorios\Data\PricingInput::signedAmount($result['historic_profit_cents']) }}</dd></div>
        <div><dt>Ganancia al costo actual</dt><dd>Bs {{ \App\Domain\Accesorios\Data\PricingInput::signedAmount($result['base_profit_cents']) }}</dd></div>
        <div><dt>Recargo sobre la base</dt><dd>{{ \App\Domain\Accesorios\Data\PricingInput::signedAmount($result['markup_bps']) }} %</dd></div>
        <div><dt>Margen sobre la venta</dt><dd>{{ \App\Domain\Accesorios\Data\PricingInput::signedAmount($result['margin_bps']) }} %</dd></div>
    </dl>
    @if($result['below_replacement'])<p class="pg-pricing-warning" role="alert">El precio cubre lo que pagaste, pero no alcanza para comprar el mismo equipo al dólar actual. Revisa el precio antes de vender.</p>@endif
    <p class="pg-pricing-note">Configuración revisión {{ $result['settings_revision'] }}. Es una simulación; no se guardó ningún precio de producto ni movimiento de dinero.</p>
</section>
