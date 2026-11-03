<div style="display:grid;gap:1rem">
 <div style="padding:.8rem 1rem;border-radius:.7rem;background:rgba(45,212,191,.08);border:1px solid rgba(45,212,191,.25)"><span>Efectivo disponible de Accesorios</span><strong style="display:block;font-size:1.6rem;color:#2dd4bf">Bs {{ \App\Support\Bolivianos::formatCompact($cash_cents) }}</strong></div>
 @if($destination)
 <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
 @if($qr_url)<img src="{{ $qr_url }}" alt="QR de {{ $destination['bank'] }}" width="160" height="160" style="border-radius:.5rem;background:white" />@endif
 <div><strong>{{ $destination['name'] }}</strong><p>{{ $destination['bank'] }}</p><p>Cuenta: {{ $destination['number'] }}</p><p>Titular: {{ $destination['holder'] }}</p></div>
 </div>
 @else <p>Selecciona una cuenta habilitada. Administración configura las cuentas y QR en Tarjetas → Inventario → Ajustes → Cuentas para depósitos.</p> @endif
 <p style="font-size:.8rem;color:#a5b4cf">Deposita primero y registra después. Solo se descontará efectivo de Accesorios.</p>
</div>
