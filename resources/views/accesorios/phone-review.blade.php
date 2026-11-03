@if($review)
<div class="pg-product-review">
<h3>{{ $review['values']['brand'] }} {{ $review['values']['model'] }}</h3>
<p>{{ $review['values']['ram_gb'] }} GB RAM · {{ $review['values']['storage_gb'] }} GB · {{ $review['values']['color'] }} · {{ $review['values']['condition']==='NEW'?'Nuevo':'Usado' }}</p>
<dl>
<div><dt>Stock a ingresar</dt><dd>{{ $review['values']['quantity'] ?? 1 }} unidades</dd></div>
<div><dt>Costo real · Bs</dt><dd>{{ \App\Support\Bolivianos::formatCompact($review['historical_cost_cents']) }}</dd></div>
<div><dt>Precio de venta · Bs</dt><dd>{{ \App\Support\Bolivianos::formatCompact($review['pricing']['price_cents']) }}</dd></div>
<div><dt>Ganancia histórica · Bs</dt><dd>{{ \App\Support\Bolivianos::formatCompact($review['pricing']['historic_profit_cents']) }}</dd></div>
@if($review['pricing']['replacement_cents']!==null)<div><dt>Costo de reposición · Bs</dt><dd>{{ \App\Support\Bolivianos::formatCompact($review['pricing']['replacement_cents']) }}</dd></div>@endif
</dl>
@if($review['pricing']['below_replacement'])<p class="pg-product-error">El precio cubre el costo histórico, pero no la reposición al cambio actual.</p>@endif
<p>Verifica el modelo, las características, el stock y el estado físico. El IMEI se registrará al vender. Se registrará el stock indicado; no se descontará dinero del fondo.</p>
</div>
@endif
