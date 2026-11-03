<section class="pg-count-reconciliation" aria-label="Resumen del conteo de Tarjetas">
    <div class="pg-count-cash-metrics">
        <div><small>Efectivo esperado</small><strong>Bs {{ \App\Support\Bolivianos::formatCompact($summary['cash']['expected']) }}</strong></div>
        <div><small>Efectivo contado</small><strong>Bs {{ \App\Support\Bolivianos::formatCompact($summary['cash']['counted']) }}</strong></div>
        <div data-tone="{{ $summary['cash']['missing'] ? 'missing' : 'balanced' }}"><small>Faltante efectivo</small><strong>Bs {{ \App\Support\Bolivianos::formatCompact($summary['cash']['missing']) }}</strong></div>
        <div data-tone="{{ $summary['cash']['surplus'] ? 'surplus' : 'balanced' }}"><small>Sobrante efectivo</small><strong>Bs {{ \App\Support\Bolivianos::formatCompact($summary['cash']['surplus']) }}</strong></div>
    </div>
    <div class="pg-count-debt"><span>Faltante total · efectivo y tarjetas</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($summary['shortage_cents']) }}</strong></div>
    <p class="pg-count-summary-note">Disponible: Bs {{ \App\Support\Bolivianos::formatCompact($summary['cash']['available']) }} · Reserva: Bs {{ \App\Support\Bolivianos::formatCompact($summary['cash']['reserved']) }}. Los sobrantes no compensan faltantes.</p>
    <h3 class="pg-count-company-title">Stock por empresa · unidades</h3>
    <div class="pg-count-company-grid">
        @forelse ($detail['companies'] as $group)
            <section class="pg-count-company-card" data-count-company="{{ $group['company'] }}" aria-label="{{ \App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES[$group['company']] ?? $group['company'] }} · conteo histórico">
                <header>
                    @if (array_key_exists($group['company'], \App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES))
                        @include('tarjetas.company-logo', ['company' => $group['company'], 'logoClass' => 'pg-count-company-logo'])
                    @endif
                    <strong>{{ \App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES[$group['company']] ?? $group['company'] }}</strong>
                    <span>Tarjetas físicas</span>
                </header>
                <dl class="pg-count-company-totals">
                    <div><dt>Esperado</dt><dd>{{ $group['expected'] }}</dd></div>
                    <div><dt>Contado</dt><dd>{{ $group['counted'] }}</dd></div>
                    <div data-tone="{{ $group['missing'] ? 'missing' : 'balanced' }}"><dt>Faltantes</dt><dd>{{ $group['missing'] }}</dd></div>
                    <div data-tone="{{ $group['surplus'] ? 'surplus' : 'balanced' }}"><dt>Sobrantes</dt><dd>{{ $group['surplus'] }}</dd></div>
                </dl>
                @include('tarjetas.store-count-cut-table', ['rows' => $group['lines']])
                <details class="pg-count-reserve-detail">
                    <summary>Reservas: {{ $group['reserved'] }} · Disponibles: {{ $group['available'] }}</summary>
                    <div class="pg-count-table-scroll"><table class="pg-count-stock-table"><thead><tr><th>Corte</th><th>Reserva</th><th>Disponible</th></tr></thead><tbody>
                        @foreach ($group['lines'] as $line)<tr><th>{{ $line['label'] }}</th><td>{{ $line['reserved'] }}</td><td>{{ $line['available'] }}</td></tr>@endforeach
                    </tbody></table></div>
                </details>
            </section>
        @empty
            <p class="pg-count-summary-note">No había tarjetas con existencias en este conteo.</p>
        @endforelse
    </div>
    @if ($detail['empty_companies'])
        <details class="pg-count-cut-details"><summary>Cortes sin existencias en este conteo</summary>
            @foreach ($detail['empty_companies'] as $group)
                <h4 class="pg-count-company-title">{{ \App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES[$group['company']] ?? $group['company'] }}</h4>
                @include('tarjetas.store-count-cut-table', ['rows' => $group['lines']])
            @endforeach
        </details>
    @endif
</section>
