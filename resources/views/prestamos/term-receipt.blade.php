<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Préstamo {{ $event->loan_id }} · Operación {{ $event->id }}</title>
    <style>
        @page{size:letter portrait;margin:24pt}body{font:6.7pt DejaVu Sans,sans-serif;line-height:1.2;color:#172335;margin:0}table{width:100%;border-collapse:collapse;table-layout:fixed}td{vertical-align:top;word-wrap:break-word}.copy{border:0.6pt solid #aeb8c7;padding:7pt;margin-bottom:7pt;page-break-inside:avoid}.heading td{padding:0}.heading h1{font-size:10pt;margin:0 0 4pt}.logo{width:100pt;text-align:right}.logo img{width:86pt;height:auto}.meta{font-size:6.5pt;color:#526174;margin:3pt 0}.card{padding:4pt 6pt;margin:4pt 0;border:0.5pt solid #d4dce7;background:#fbfcfe}.card h2{font-size:7.5pt;margin:0 0 2pt}.card p{margin:3pt 0}.fields td{padding:2pt 6pt 2pt 0}.money td{padding:4pt 6pt}.money span{font-size:6.5pt;display:block}.money strong{font-size:10pt;display:block;margin-top:4pt}.paid{color:#08785d}.note{font-size:6.5pt;color:#435168;margin:3pt 0}.signatures{margin-top:12pt}.signatures td{width:44%;text-align:center;border-top:0.5pt solid #5b687b;padding-top:5pt;font-size:6.7pt}.signatures .gap{width:12%;border:0}.signatures strong,.signatures span{display:block}@media screen{body{max-width:760px;margin:15px auto;padding:12px}}
    </style>
</head>
<body>
@foreach(['Cliente', 'Archivo'] as $copy)
    <section class="copy">
        <table class="heading"><tr>
            <td><h1>COMPROBANTE DE {{ $event->kind === 'RENEW' ? 'RENOVACIÓN' : 'AMPLIACIÓN' }}</h1><strong>Préstamo {{ $event->loan_id }} · Operación {{ $event->id }}</strong><p class="meta">Copia {{ $copy === 'Cliente' ? 'del cliente' : 'de la tienda' }} · {{ $event->summary['registered_at'] }} (Bolivia)</p></td>
            <td class="logo"><img src="{{ $logoData ?? asset('branding/logoReciboPrestamo.png') }}" alt="Planeta Gamer · 77712823 · 75885195"></td>
        </tr></table>
        <div class="card">
            <h2>Cliente y objeto en garantía</h2>
            <p><strong>{{ $event->summary['borrower']['name'] }}</strong> · CI {{ $event->summary['borrower']['ci'] }}</p>
            <p><strong>{{ $event->summary['object']['type_name'] }}</strong> · {{ $event->summary['object']['description'] }}</p>
        </div>
        <p class="note">Interés cobrado: Bs {{ \App\Support\Bolivianos::formatCompact($event->summary['interest_paid_cents'] ?? $event->summary['paid_cents']) }} · Multa cobrada: Bs {{ \App\Support\Bolivianos::formatCompact($event->summary['late_fee_cents'] ?? 0) }}.</p>
        <div class="card">
            <h2>Condiciones de la operación registrada</h2>
            <table class="fields">
                <tr><td><strong>Periodo confirmado:</strong> {{ $event->summary['days'] }} días</td><td><strong>Cantidad de periodos:</strong> {{ $event->summary['periods'] }}</td></tr>
                <tr><td><strong>Días añadidos:</strong> {{ $event->summary['added_days'] }} días</td><td><strong>Capital pendiente conservado:</strong> Bs {{ \App\Support\Bolivianos::formatCompact($event->summary['capital_cents']) }}</td></tr>
                <tr><td><strong>Vencimiento anterior:</strong> <time datetime="{{ $event->summary['previous_due_date'] }}">{{ \Carbon\CarbonImmutable::parse($event->summary['previous_due_date'])->format('d/m/Y') }}</time></td><td><strong>Nuevo vencimiento:</strong> <time datetime="{{ $event->summary['due_date'] }}">{{ \Carbon\CarbonImmutable::parse($event->summary['due_date'])->format('d/m/Y') }}</time></td></tr>
            </table>
        </div>
        <div class="card">
            <h2>Pago confirmado y saldo del periodo</h2>
            <table class="money"><tr>
                <td><span>Pago registrado en Yape</span><strong class="paid">Bs {{ \App\Support\Bolivianos::formatCompact($event->summary['paid_cents']) }}</strong></td>
                <td><span>Interés total del periodo vigente</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($event->summary['interest_cents']) }}</strong></td>
                <td><span>Interés pendiente al nuevo vencimiento</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($event->summary['pending_cents']) }}</strong></td>
            </tr></table>
            <p class="note">Abonado a este periodo: Bs {{ \App\Support\Bolivianos::formatCompact($event->summary['credited_cents']) }}.</p>
        </div>
        <p class="note">Registrado por {{ $event->summary['registered_by'] }} · Referencia: {{ $event->summary['reference'] ?: 'Sin referencia' }}.</p>
        <p class="note">El nuevo vencimiento se cumple al finalizar el día indicado, hora de Bolivia. Este comprobante conserva la operación confirmada; su reimpresión no registra otro cobro.</p>
        <table class="signatures"><tr>
            <td><strong>Firma del prestatario</strong><span>{{ $event->summary['borrower']['name'] }}</span></td><td class="gap"></td><td><strong>Responsable de la operación</strong><span>{{ $event->summary['registered_by'] }}</span></td>
        </tr></table>
    </section>
@endforeach
</body>
</html>
