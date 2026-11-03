<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Resumen de Reportes - Planeta Gamer</title>
    <style>
        @page { margin: 24mm 14mm 18mm; }
        body { font-family: "DejaVu Sans", sans-serif; color: #192a3b; font-size: 8.5pt; line-height: 1.3; }
        .brand { position: fixed; top: -18mm; left: 0; right: 0; height: 14mm; border-bottom: 2px solid #0db6be; }
        .brand-title { position: absolute; left: 0; top: 1mm; }
        .brand img { position: absolute; right: 0; top: 0; height: 13mm; } .right { text-align: right; } .muted { color: #596b7a; }
        h1 { font-size: 17pt; margin: 0 0 3mm; } h2 { font-size: 11pt; margin: 2.5mm 0 1mm; color: #126974; page-break-after: avoid; }
        p { margin: 1mm 0; } table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 1.1mm 2mm; border-bottom: 1px solid #dbe3e8; vertical-align: top; word-wrap: break-word; }
        th { background: #edf6f7; font-size: 8pt; text-align: left; } thead { display: table-header-group; }
        tr { page-break-inside: avoid; } .meta { padding: 2mm; background: #f3f6f9; border-left: 3px solid #edc635; }
        .meta td { border: 0; padding: 1mm; } .amount { white-space: nowrap; font-weight: bold; }
        .up { color: #087b52; } .down { color: #b72f38; } .small { font-size: 7.5pt; }
        .notice { padding: 2mm; background: #fff8dd; margin-top: 2mm; }
        body.adjusted { font-size: 8pt; line-height: 1.2; }
        .adjusted th, .adjusted td { padding-top: .7mm; padding-bottom: .7mm; }
        .adjusted .notice { padding: 1.5mm; margin-top: 1mm; }
        .adjusted h2 { margin-top: 2mm; }
        .foot { position: fixed; bottom: -11mm; left: 0; color: #596b7a; font-size: 7pt; }
    </style>
</head>
<body class="{{ isset($report['monthly_adjustment']) ? 'adjusted' : '' }}">

    <h1>{{ isset($report['monthly_adjustment']) ? 'Cierre mensual ajustado · v'.$report['monthly_adjustment']['sequence'] : (isset($report['monthly_close']) ? 'Cierre mensual original' : 'Resumen de Reportes') }}</h1>
    @if(isset($report['monthly_close']))<p class="notice">Cierre #{{ $report['monthly_close']['id'] }} · {{ $report['monthly_close']['month'] }} · Confirmó {{ $report['monthly_close']['actor'] }} · {{ $report['monthly_close']['confirmed_at'] }} Bolivia · {{ $report['monthly_close']['pending_count'] }} pendientes al confirmar.</p>@endif
    @if(isset($report['monthly_adjustment']))<p class="notice">Ajuste v{{ $report['monthly_adjustment']['sequence'] }} · {{ $report['monthly_adjustment']['actor'] }} · {{ $report['monthly_adjustment']['confirmed_at'] }} Bolivia · {{ $report['monthly_adjustment']['pending_count'] }} pendientes observados.<br>Motivo: {{ $report['monthly_adjustment']['reason'] }}</p>@endif
    <div class="meta">
        <table><tr><td><strong>{{ $document['mode_label'] }} consultado</strong><br>{{ str_replace(' — ', ' - ', $report['period']['label']) }}</td><td><strong>Periodo anterior</strong><br>{{ $report['comparison']['available'] ? str_replace(' — ', ' - ', $report['period']['previous_label']) : 'Sin comparación disponible' }}</td></tr>
        <tr><td><strong>Generado por:</strong> {{ $document['actor'] }}</td><td><strong>Generado:</strong> {{ $document['generated_at'] }}<br><span class="muted">Hora de Bolivia</span></td></tr></table>
    </div>
    @if ($report['period']['is_current'])<p class="notice">Periodo en curso: se compara lo registrado con el periodo anterior completo.</p>@endif
    @if(isset($report['monthly_adjustment']))
    <h2>Original, ajustes y resultado</h2><table><thead><tr><th>Indicador</th><th>Original</th><th>Ajustes acumulados</th><th>Resultado v{{ $report['monthly_adjustment']['sequence'] }}</th></tr></thead><tbody>
    @foreach (['sales' => 'Ventas', 'collections' => 'Cobros'] as $metric => $label)<tr><td>{{ $label }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($report['monthly_adjustment']['original_'.$metric.'_cents']) }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($report['monthly_adjustment'][$metric.'_adjustment_cents']) }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($report[$metric.'_cents']) }}</td></tr>@endforeach
    </tbody></table><p class="small muted">Comparación con el mes anterior observado en el cierre original.</p>
    @endif
    <h2>Ventas y cobros</h2>
    <table>
        <thead><tr><th style="width:25%">Indicador</th><th class="right" style="width:22%">Consultado</th><th class="right" style="width:22%">Anterior</th><th style="width:31%">Diferencia</th></tr></thead>
        <tbody>
        @foreach (['sales' => 'Ventas registradas', 'collections' => 'Cobros registrados'] as $metric => $label)
            <tr><td>{{ $label }}</td><td class="right amount">Bs {{ \App\Support\Bolivianos::formatCompact($report[$metric.'_cents']) }}</td><td class="right">{{ $report['comparison'][$metric] === null ? 'No disponible' : 'Bs '.\App\Support\Bolivianos::formatCompact($report['comparison'][$metric]['previous_cents']) }}</td><td class="{{ $report['comparison'][$metric]['direction'] ?? '' }}">{{ $report['comparison'][$metric]['label'] ?? 'Sin comparación disponible' }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <p class="small muted">Ventas ya forma parte de Cobros: no se suman entre sí. Los cobros incluyen capital recuperado de Préstamos; no representan ganancias.</p>
    <h2>Desglose del periodo consultado</h2>
    <table>
        <thead><tr><th style="width:49%">Origen</th><th class="right" style="width:11%">Registros</th><th class="right" style="width:20%">Ventas</th><th class="right" style="width:20%">Cobros</th></tr></thead>
        <tbody>@foreach ($report['rows'] as $row)<tr><td>{{ $row['label'] }}</td><td class="right">{{ $row['operations'] }}</td><td class="right amount">Bs {{ \App\Support\Bolivianos::formatCompact($row['sales_cents']) }}</td><td class="right amount">Bs {{ \App\Support\Bolivianos::formatCompact($row['collections_cents']) }}</td></tr>@endforeach</tbody>
    </table>
    <p class="small muted">Las ventas mixtas se separan por tipo sin duplicar importes; Registros no equivale a tickets únicos.</p>
    @if (($report['balance_scope'] ?? null) === 'excluded_monthly')
    <p class="notice">El cierre mensual no incluye saldos actuales como saldos históricos. Los pendientes se conservan por separado y no se reconocen como pérdidas.</p>
    @else
    <h2>Dinero disponible al generar</h2>
    <p class="small muted">Saldos actuales por fondo, no históricos ni conciliados con el banco. No se suman fondos ni saldos digitales Entel.</p>
    <table>
        <thead><tr><th style="width:36%">Fondo / componente</th><th class="right" style="width:19%">Disponible</th><th style="width:45%">Detalle</th></tr></thead>
        <tbody>@foreach ($report['balances'] as $balance)<tr><td>{{ $balance['label'] }}</td><td class="right amount">{{ $balance['amount_cents'] === null ? 'No disponible' : 'Bs '.\App\Support\Bolivianos::formatCompact($balance['amount_cents']) }}</td><td class="small muted">{{ $balance['note'] }}</td></tr>@endforeach</tbody>
    </table>
    @endif
    <p class="small muted">{{ isset($report['monthly_adjustment']) ? 'Resultado conservado de esta versión. Los cambios posteriores no alteran este archivo.' : (isset($report['monthly_close']) ? 'Datos originales conservados al confirmar. No se recalculan con operaciones posteriores.' : 'Consulta de operaciones vigentes al generar. No constituye cierre mensual.') }}</p>
    <div class="foot">Documento {{ $document['id'] }}</div>
</body>
</html>
