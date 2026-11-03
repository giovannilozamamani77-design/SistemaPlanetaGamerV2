<x-filament-panels::page>
    <section class="pg-loan-overview" aria-label="Resumen de préstamos">
        <div class="pg-loan-overview-card" data-tone="capital">
            <x-filament::icon icon="heroicon-o-banknotes" class="pg-loan-overview-icon" />
            <div><span>Capital disponible</span><strong>{{ $summary['initialized'] ? 'Bs '.\App\Support\Bolivianos::formatCompact($summary['available_cents']) : 'Sin saldo inicial' }}</strong><small>Disponible para prestar</small></div>
        </div>
        <div class="pg-loan-overview-card" data-tone="active">
            <x-filament::icon icon="heroicon-o-check-circle" class="pg-loan-overview-icon" />
            <div><span>Activos</span><strong>{{ $summary['active'] }}</strong><small>Dentro del plazo</small></div>
        </div>
        <div class="pg-loan-overview-card" data-tone="late">
            <x-filament::icon icon="heroicon-o-clock" class="pg-loan-overview-icon" />
            <div><span>Vencidos</span><strong>{{ $summary['late'] }}</strong><small>Entre 1 y 7 días de atraso</small></div>
        </div>
        <div class="pg-loan-overview-card" data-tone="lost">
            <x-filament::icon icon="heroicon-o-archive-box-x-mark" class="pg-loan-overview-icon" />
            <div><span>Objetos perdidos</span><strong>{{ $summary['lost'] }}</strong><small>Desde el día 8 de atraso</small></div>
        </div>
        <div class="pg-loan-overview-card" data-tone="total">
            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="pg-loan-overview-icon" />
            <div><span>Total registrados</span><strong>{{ $summary['total'] }}</strong><small>Todos los estados del historial</small></div>
        </div>
    </section>
    {{ $this->table }}
</x-filament-panels::page>
