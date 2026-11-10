<div class="pg-sale-review pg-sale-history-detail">
    <h3><x-filament::icon icon="heroicon-o-receipt-percent" />Venta #{{ $sale['id'] }} · {{ $sale['date'] }}</h3><p>Atendió: {{ $sale['actor_name'] }} · Efectivo</p>
    @if($sale['customer'] ?? null)<p>Cliente: {{ $sale['customer']['name'] }} · CI {{ $sale['customer']['ci'] }} · +591 {{ $sale['customer']['phone'] }}</p>@endif
    @foreach ($sale['lines'] as $line)<p><span>{{ $line['product_name'] }} · {{ $line['product_code'] }} · {{ $line['quantity'] }} × Bs {{ \App\Support\Bolivianos::formatCompact($line['unit_price_cents']) }}</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($line['total_cents']) }}</strong></p>@endforeach
    @foreach($sale['lines'] as $line)@if($line['warranty'] ?? null)<p>IMEI {{ implode(' / ', $line['phone']['imeis']) }} · Garantía {{ $line['warranty']['months'] }} meses · {{ $line['warranty']['starts_on'] }} al {{ $line['warranty']['expires_on'] }}</p>@endif@endforeach
    @if(($sale['discount_cents'] ?? 0) > 0)<p><span>Descuento aplicado</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($sale['discount_cents']) }}</strong></p>@endif
    <p class="pg-sale-review-total"><span><x-filament::icon icon="heroicon-o-banknotes" />Total cobrado</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($sale['total_cents']) }}</strong></p>
    @if (array_key_exists('cost_cents', $sale))<p>Costo aplicado: {{ $sale['cost_cents'] === null ? 'Pendiente' : 'Bs '.\App\Support\Bolivianos::formatCompact($sale['cost_cents']) }}</p><p>Ganancia: {{ $sale['profit_cents'] === null ? 'Pendiente' : 'Bs '.\App\Support\Bolivianos::formatCompact($sale['profit_cents']) }}</p>@endif
    <p><a href="{{ route('accesorios.recibo', ['sale'=>$sale['id']]) }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-o-printer" />Ver o imprimir recibo</a></p>
</div>
