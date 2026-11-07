<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Préstamo {{ $detail['receipt_number'] }} · Comprobante</title>
    <style>
        @page{size:letter portrait;margin:24pt}*{box-sizing:border-box}body{font-family:DejaVu Sans,sans-serif;font-size:6.5pt;line-height:1.18;color:#172335;margin:0;padding:0}table{border-collapse:collapse;width:100%;table-layout:fixed}td{vertical-align:top;overflow-wrap:break-word;word-wrap:break-word}.copy{border:0.6pt solid #aeb8c7;page-break-inside:avoid}.copy-content{padding:6pt 8pt 0}.cut{height:8pt;font-size:1pt;line-height:8pt}.heading td{padding:0;border:0}.heading h1{font-size:11pt;line-height:1.2;margin:0 0 4pt;color:#152238}.heading p{font-size:6.7pt;margin:2pt 0}.logo-cell{width:100pt;text-align:right}.logo-cell img{width:86pt;height:auto;display:block;margin-left:auto}.original{font-size:6pt;color:#526174}.intro{margin:4pt 0 3pt;line-height:1.25}.receipt-total{color:#08785d}.contract-clause{font-size:6.5pt;line-height:1.25;margin:4pt 0;padding:4pt 6pt;background:#f5f7fa;border-left:1pt solid #71849c}.card{border:0.5pt solid #d4dce7;background:#fbfcfe;padding:4pt 6pt;margin:3pt 0}.card h2{font-size:7.5pt;margin:0 0 3pt;color:#172335}.details td{padding:1.5pt 5pt 1.5pt 0}.label{font-weight:bold;color:#27364b}.object-details{margin:4pt 0 0;font-size:6.7pt;line-height:1.3;word-wrap:break-word}.object-details p{margin:2pt 0}.amounts td{width:33.33%;padding:1pt 5pt;border-right:0.5pt solid #d4dce7}.amounts td:last-child{border:0}.amounts strong{font-size:10.5pt;display:block;margin-top:3pt}.amounts .total{color:#08785d}.literal{border:0.5pt solid #d4dce7;padding:4pt 6pt;margin:4pt 0;font-size:6.8pt}.note{font-size:6.2pt;color:#435168;margin:4pt 0;line-height:1.3}.record{font-size:6pt;color:#435168;margin:3pt 0}.signature-area{height:22pt;vertical-align:bottom;padding:14pt 18pt 4pt}.signatures td{width:44%;text-align:center;padding-top:4pt;border-top:0.5pt solid #5b687b;font-size:6.5pt;line-height:1.185}.signatures .gap{width:12%;border:0}.signatures strong,.signatures span{display:block}.annex{page-break-before:always;font-size:8pt;line-height:1.4}.annex h1{font-size:12pt}.annex p{white-space:pre-wrap;word-wrap:break-word;margin:8pt 0}.annex h2{font-size:9pt;margin-top:12pt}@media screen{body{max-width:750px;margin:12px auto;padding:12px;background:white}}
    </style>
</head>
<body>
@foreach(['Cliente', 'Archivo'] as $copy)

    @if(!$loop->first)<div class="cut"></div>@endif
    @if($import = $detail['terms_snapshot']['historical_import'] ?? null)<p><strong>REGISTRO HISTÓRICO IMPORTADO</strong> · Referencia anterior: {{ $import['reference'] }} · Importado por {{ $import['imported_by'] }} el {{ \Carbon\CarbonImmutable::parse($import['imported_at'])->timezone('America/La_Paz')->format('d/m/Y H:i') }}. Este comprobante conserva un hecho del sistema anterior; no acredita una nueva entrega de dinero ni una transferencia bancaria actual.</p>@endif
    <table class="copy" aria-label="Copia {{ $copy }}"><tbody>
        <tr><td class="copy-content">
            <table class="heading"><tr>
                <td><h1>RECIBO DE PRÉSTAMO</h1><strong>{{ $detail['receipt_number'] }}</strong><p>Copia {{ $copy === 'Cliente' ? 'del cliente' : 'de la tienda' }} - Fecha: {{ $detail['registration_date_literal'] }}</p></td>
                <td class="logo-cell"><img src="{{ $logoData ?? asset('branding/logoReciboPrestamo.png') }}" alt="Planeta Gamer · 77712823 · 75885195"></td>
            </tr></table>
            <p class="intro">Yo, <strong>{{ $detail['borrower']['name'] }}</strong>, con CI <strong>{{ $detail['borrower']['ci'] }}</strong>, en calidad de prestatario, declaro haber recibido de <strong>{{ $detail['receipt_terms']['lender_name'] }}</strong>, mediante Planeta Gamer y por intermedio de <strong>{{ $detail['registered_by'] }}</strong>, la cantidad de <strong>Bs {{ $detail['capital_receipt'] }}</strong> en calidad de préstamo, dejando el objeto descrito a continuación como garantía.</p>
            <section class="card">
                <h2>Detalles del préstamo</h2>
                <table class="details">
                    <tr><td><span class="label">• Objeto en garantía:</span> {{ $detail['object']['type_name'] }}</td><td><span class="label">• Monto prestado:</span> Bs {{ $detail['capital_receipt'] }}</td></tr>
                    <tr><td><span class="label">• Duración:</span> {{ $detail['days'] }} días</td><td><span class="label">• Fecha de pago:</span> <time datetime="{{ $detail['due_date'] }}">{{ $detail['due_date_literal'] }}</time></td></tr>
                    <tr><td><span class="label">• Monto total a pagar:</span> <strong class="receipt-total">Bs {{ $detail['total_receipt'] }}</strong></td><td><span class="label">• Responsable:</span> {{ $detail['registered_by'] }}</td></tr>
                </table>
                <div class="object-details">
                    @if($detail['receipt_has_annex'])
                        <p>Descripción, estado y accesorios: ver anexo de este préstamo.</p>
                    @else
                        <p><strong>Detalle del objeto:</strong> {{ $detail['object']['description'] }}</p>
                        @foreach(['serial' => 'Serie / IMEI', 'condition' => 'Estado recibido', 'accessories' => 'Accesorios recibidos'] as $key => $label)
                            @if(filled($detail['object'][$key] ?? null))<p><strong>{{ $label }}:</strong> {{ $detail['object'][$key] }}</p>@endif
                        @endforeach
                    @endif
                </div>
            </section>
            <p class="note">Interés del periodo: Bs {{ $detail['interest_receipt'] }} · Inicio: <time datetime="{{ $detail['start_date'] }}">{{ \Carbon\CarbonImmutable::parse($detail['start_date'])->format('d/m/Y') }}</time></p>
            <p class="literal"><strong>Monto total en literal:</strong> {{ $detail['total_literal'] }}.</p>
            <p class="contract-clause">Si el prestatario no paga en la fecha acordada, tendrá {{ $detail['receipt_terms']['grace_days'] }} días adicionales para hacerlo y se aplicará una multa de <strong>Bs {{ $detail['late_fee_receipt'] }}</strong>. Pasado ese plazo, el objeto en garantía quedará a favor del prestamista como compensación por el préstamo.</p>
            <p class="note">El prestatario declara haber revisado la información del presente recibo y acepta las condiciones, el plazo y el estado registrado del objeto entregado en garantía.</p>
        </td></tr>
        <tr><td class="signature-area"><table class="signatures"><tr>
            <td><strong>Firma del prestatario</strong><span>{{ $detail['borrower']['name'] }}</span><span>CI {{ $detail['borrower']['ci'] }}</span></td>
            <td class="gap"></td>
            <td><strong>Responsable de la operación</strong><span>{{ $detail['registered_by'] }}</span><span>Firma</span></td>
        </tr></table></td></tr>
    </tbody></table>
@endforeach
@if($detail['receipt_has_annex'])
    @foreach(['Cliente', 'Archivo'] as $copy)
        <section class="annex">
            <h1>Anexo del préstamo {{ $detail['receipt_number'] }} · Copia {{ $copy }}</h1>
            <p>{{ $detail['borrower']['name'] }} · CI {{ $detail['borrower']['ci'] }} · {{ $detail['object']['type_name'] }}</p>
            @foreach(['description' => 'Detalle del objeto', 'serial' => 'Serie / IMEI', 'condition' => 'Estado recibido', 'accessories' => 'Accesorios recibidos'] as $key => $label)
                @if(filled($detail['object'][$key] ?? null))<h2>{{ $label }}</h2><p>{{ $detail['object'][$key] }}</p>@endif
            @endforeach
        </section>
    @endforeach
@endif
@if($autoPrint ?? false)<script>window.addEventListener('load', () => window.print());</script>@endif
</body>
</html>
