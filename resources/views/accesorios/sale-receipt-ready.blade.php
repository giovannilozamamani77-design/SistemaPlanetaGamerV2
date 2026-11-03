<div class="pg-receipt-ready">
    <div class="pg-receipt-ready-success" role="status">
        <span class="pg-receipt-ready-check" aria-hidden="true"><x-filament::icon icon="heroicon-o-check-circle" /></span>
        <h3>Venta registrada correctamente</h3>
        <p>Venta #{{ $receipt['id'] }} · {{ $receipt['receipt_required'] ? 'Recibo y garantía listos' : 'Recibo listo' }}</p>
    </div>
    <div class="pg-receipt-ready-summary">
        <img src="{{ asset('branding/fotoperfilAdmin.jpg') }}" alt="Planeta Gamer" width="48" height="48">
        <div><span>PLANETA GAMER</span>@if($receipt['customer'] ?? null)<strong>{{ $receipt['customer']['name'] }}</strong><small>CI {{ $receipt['customer']['ci'] }}</small>@else<strong>Venta de accesorios</strong><small>Atendió: {{ $receipt['actor_name'] }}</small>@endif</div>
        <span class="pg-receipt-ready-total">Total pagado<strong>Bs {{ \App\Support\Bolivianos::formatCompact($receipt['total_cents']) }}</strong></span>
    </div>
    <p class="pg-receipt-ready-note">@if($receipt['receipt_required'])<x-filament::icon icon="heroicon-o-shield-check" />Entrega el recibo al cliente: incluye los IMEI y la garantía de cada equipo.@else<x-filament::icon icon="heroicon-o-document-check" />Tu recibo está disponible para imprimir o descargar cuando lo necesites.@endif</p>
    <div class="pg-receipt-ready-actions">
        <a class="pg-receipt-ready-print" href="{{ route('accesorios.recibo', ['sale' => $receipt['id'], 'print' => 1]) }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-o-printer" />{{ $receipt['receipt_required'] ? 'Imprimir recibo y garantía' : 'Imprimir recibo' }}</a>
        <a href="{{ route('accesorios.recibo-pdf', ['sale' => $receipt['id']]) }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-o-arrow-down-tray" />Descargar PDF</a>
        <button type="button" wire:click="newSale" wire:loading.attr="disabled" class="pg-receipt-ready-new"><x-filament::icon icon="heroicon-o-plus-circle" />Nueva venta</button>
    </div>
    <p class="pg-receipt-ready-hint">Puedes reimprimirlo sin registrar otro cobro.</p>
</div>
