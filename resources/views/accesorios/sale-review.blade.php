<div class="pg-sale-review pg-sale-checkout pg-sale-checkout-compact">
@if($summary)
    <small>{{ $summary['quantity'] }} {{ $summary['quantity'] === 1 ? 'unidad' : 'unidades' }}</small>
    <div class="pg-sale-checkout-items">
    @foreach($summary['lines'] as $line)
        <article><div><strong>{{ $line['product_name'] }}</strong><span>{{ $line['quantity'] }} × Bs {{ \App\Support\Bolivianos::formatCompact($line['unit_price_cents']) }}</span>
        @if(($line['discount_cents'] ?? 0) > 0)<small>Rebaja: Bs {{ \App\Support\Bolivianos::formatCompact($line['discount_cents']) }}</small>@endif
        </div><strong>Bs {{ \App\Support\Bolivianos::formatCompact($line['total_cents']) }}</strong></article>
        @if(($line['kind'] ?? '') === 'PHONE')
        <details><summary>Garantía {{ $line['warranty']['months'] }} meses · Ver condiciones</summary>
        <p>IMEI: {{ $line['phone_snapshot']['imeis'] ? implode(' / ', $line['phone_snapshot']['imeis']) : 'Se registra al confirmar' }}</p>
        <p>{{ $line['warranty']['starts_on'] }} al {{ $line['warranty']['expires_on'] }}</p>
        <p>Estado: {{ $line['phone_snapshot']['physical_state'] ?: 'Sin observaciones registradas' }} · Accesorios: {{ $line['phone_snapshot']['included_accessories'] ?: 'No registrados' }}</p><small>{{ $line['warranty']['terms'] }}</small>
        </details>
        @elseif($line['product_description'])
        <details><summary>Ver detalle</summary><p>{{ $line['product_description'] }}</p></details>
        @endif
    @endforeach
    </div>
    @if($discountError ?? null)<p class="pg-sale-error" role="alert">{{ $discountError }}</p>@endif
    <p><span>Subtotal</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($summary['subtotal_cents'] ?? $summary['total_cents']) }}</strong></p>
    <p><span>Descuento</span><strong>{{ ($summary['discount_cents'] ?? 0) > 0 ? 'Bs '.\App\Support\Bolivianos::formatCompact($summary['discount_cents']) : 'Sin descuento' }}</strong></p>
    <p class="pg-sale-review-total"><span>Total a cobrar</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($summary['total_cents']) }}</strong></p>
    <p><span>{{ ($summary['commission_pending'] ?? false) ? 'Comisión conocida · hay costos pendientes' : 'Tu comisión por esta venta' }}</span><strong style="color:var(--color-cyan-400,#22d3ee)">Bs {{ \App\Support\Bolivianos::formatCompact($summary['commission_cents'] ?? 0) }}</strong></p>
    <small>Se acumula para {{ auth()->user()->name }}. Confirmar registra el cobro; no retira la comisión del efectivo.</small>
@else
    <p>Revisa primero los productos.</p>
@endif
</div>
