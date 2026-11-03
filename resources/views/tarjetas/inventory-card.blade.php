@php($product = $getRecord())
<div class="pg-inventory-cut" data-company="{{ $product->company }}">
    <div class="pg-inventory-cut-face"><small class="pg-inventory-cut-brand">@include('tarjetas.company-logo', ['company' => $product->company, 'logoClass' => 'pg-sale-cut-logo']) Corte</small><strong>{{ $product->cutLabel() }}</strong>@if (! $product->is_active)<small>Inactivo</small>@endif</div>
    <div class="pg-inventory-cut-stock"><span>Stock actual</span><strong>{{ $product->stock?->quantity ?? 0 }}</strong><span>unidades</span><span class="sr-only">tarjetas en stock</span></div>
</div>
