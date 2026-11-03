<div class="pg-count-record-meta">
    <strong>{{ $count->kind === 'OPEN' ? 'Apertura' : 'Cierre' }} #{{ $count->id }}</strong>
    <span>{{ $count->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }}</span>
    <span>Registró: {{ $count->user?->name ?? 'Usuario no disponible' }}</span>
</div>
@include('tarjetas.store-count-reconciliation', ['summary' => $summary, 'detail' => $detail])
@if ($count->reason)<p class="pg-count-summary-note"><strong>Observación:</strong> {{ $count->reason }}</p>@endif
