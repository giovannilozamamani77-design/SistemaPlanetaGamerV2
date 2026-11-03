@php($entry = $getRecord())
<div class="pg-sale-register-products">
    @if ($entry->entry_type === 'RECHARGE')
        <div class="pg-sale-register-product">@include('tarjetas.company-logo', ['company' => 'ENTEL', 'logoClass' => 'pg-sale-cut-logo'])<span>Entel · {{ $entry->mode === 'PACKAGES' ? 'Paquetes' : 'Crédito' }}<small>Monto libre</small></span><strong>1 recarga</strong></div>
    @else
        @foreach ($entry->companyLines() as $line)
            @php($snapshot = $line->product_snapshot)
            <div class="pg-sale-register-product">@include('tarjetas.company-logo', ['company' => $snapshot['company'], 'logoClass' => 'pg-sale-cut-logo'])<span>{{ \App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES[$snapshot['company']] ?? $snapshot['company'] }} · {{ isset($snapshot['label']) ? \Illuminate\Support\Str::after($snapshot['label'], ' · ') : 'Bs '.\App\Support\Bolivianos::formatCompact($snapshot['face_value_cents']) }}</span><strong>× {{ $line->quantity }}</strong></div>
        @endforeach
    @endif
</div>
