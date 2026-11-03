<div class="pg-deposit-preview">
 <div class="pg-purchase-cash"><div><span>Efectivo disponible de Tarjetas</span><strong>{{ $cash_cents === null ? 'Sin conteo inicial' : 'Bs '.\App\Support\Bolivianos::formatCompact($cash_cents) }}</strong></div></div>
 @if ($destination)
 <div class="pg-deposit-destination">
  @if ($qr_url)<img src="{{ $qr_url }}?revision={{ $destination['revision'] }}" alt="QR de {{ $destination['bank'] }} a nombre de {{ $destination['holder'] }}" width="192" height="192" />@endif
  <div><strong>{{ $destination['name'] }}</strong><span>{{ $destination['bank'] }}</span><span>Cuenta: {{ $destination['number'] }}</span><span>Titular: {{ $destination['holder'] }}</span></div>
 </div>
 @else <p>Selecciona una cuenta habilitada. Si no hay cuentas, contacta con Administración para configurarlas en Tarjetas → Cuentas de depósitos.</p> @endif
 <p>Deposita primero y registra después. Se descontará únicamente del efectivo de Tarjetas; no es una comisión ni una compra.</p>
</div>
