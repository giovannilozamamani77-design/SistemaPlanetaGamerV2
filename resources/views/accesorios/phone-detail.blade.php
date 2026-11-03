<div class="pg-product-review">
<h3>{{ $phone->variant->model->brand->name }} {{ $phone->variant->model->name }}</h3>
<p>{{ $phone->variant->ram_gb }} GB RAM / {{ $phone->variant->storage_gb }} GB · {{ $phone->color }} · {{ $phone->condition==='NEW'?'Nuevo':'Usado' }}</p>
<dl>
<div><dt>Stock de este ingreso y estado</dt><dd>{{ $groupStock ?? 1 }} equipos</dd></div>
@foreach($phone->imeis as $imei)<div><dt>IMEI {{ $imei->slot }}</dt><dd>{{ $imei->imei }}</dd></div>@endforeach
<div><dt>Precio · Bs</dt><dd>{{ \App\Support\Bolivianos::formatCompact($phone->sale_price_cents) }}</dd></div>
<div><dt>Estado</dt><dd>{{ $statusLabel }}</dd></div>
<div><dt>Stock disponible de esta variante</dt><dd>{{ $phone->variant->available_units_count }} equipos</dd></div>
<div><dt>Estado físico</dt><dd>{{ $phone->physical_state??'—' }}</dd></div>
<div><dt>Accesorios incluidos</dt><dd>{{ $phone->included_accessories??'—' }}</dd></div>
@if($canManage)
<div><dt>Costo real · Bs</dt><dd>{{ \App\Support\Bolivianos::formatCompact($phone->historical_cost_cents) }}</dd></div>
<div><dt>Compra · {{ $phone->purchase_currency }}</dt><dd>{{ \App\Support\Bolivianos::formatCompact($phone->purchase_amount_cents) }}</dd></div>
@if($phone->purchase_rate_micros)<div><dt>Cambio real de compra</dt><dd>{{ \App\Domain\Accesorios\Data\PricingInput::rateText($phone->purchase_rate_micros) }}</dd></div>@endif
<div><dt>Proveedor / procedencia</dt><dd>{{ $phone->origin??'—' }}</dd></div>
@endif
<div><dt>Registró</dt><dd>{{ $phone->actor_name }}</dd></div>
<div><dt>Fecha de registro</dt><dd>{{ $phone->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }}</dd></div>
</dl></div>
