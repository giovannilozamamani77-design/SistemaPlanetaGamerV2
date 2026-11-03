<div class="pg-loan-record-parties">
    <section class="pg-loan-record-card"><h3>Cliente</h3><dl class="pg-loan-record-fields"><div><dt>Nombre</dt><dd>{{ $borrower['name'] }}</dd></div><div><dt>CI</dt><dd>{{ $borrower['ci'] }}</dd></div></dl></section>
    <section class="pg-loan-record-card"><h3>Objeto en garantía</h3><dl class="pg-loan-record-fields"><div><dt>Objeto</dt><dd>{{ $object['type_name'] }}</dd></div><div><dt>Detalle</dt><dd>{{ $object['description'] }}</dd></div></dl></section>
</div>
<p class="pg-loan-record-note">Capital conservado: Bs {{ \App\Support\Bolivianos::formatCompact($capital_cents) }} · Vencimiento registrado: {{ \Carbon\CarbonImmutable::parse($current['due_date'])->format('d/m/Y') }} · Periodo: {{ $current['days'] }} días.</p>
<p role="status" class="pg-loan-record-note">Antes de renovar, cobra el interés pendiente del periodo actual. El nuevo plazo se cuenta desde el último vencimiento registrado, no desde hoy.</p>
