<div style="display:grid;gap:1rem">
 <strong>Depósito #{{ $deposit->id }} · Bs {{ \App\Support\Bolivianos::formatCompact($deposit->amount_cents) }}</strong>
 <p>Depositó: {{ $deposit->actor_name }} · {{ $deposit->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }}</p>
 <p>{{ $deposit->destination_snapshot['name'] }} · {{ $deposit->destination_snapshot['bank'] }} · {{ $deposit->destination_snapshot['number'] }}</p>
 <p>Titular: {{ $deposit->destination_snapshot['holder'] }}</p>
 <p>Operación: {{ $deposit->operation_reference ?: 'Sin número' }}</p>
 <p>Efectivo antes: Bs {{ \App\Support\Bolivianos::formatCompact($deposit->movement->before_cents) }} · Después: Bs {{ \App\Support\Bolivianos::formatCompact($deposit->movement->after_cents) }}</p>
 @if($deposit->reversal)<div style="border-left:3px solid #f87171;padding:.75rem"><strong>Anulado por {{ $deposit->reversal->actor_name }}</strong><p>{{ $deposit->reversal->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }} · {{ $deposit->reversal->reason }}</p><p>Restituido al efectivo: Bs {{ \App\Support\Bolivianos::formatCompact($deposit->reversal->amount_cents) }}</p></div>@endif
</div>
