<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Recibo de venta #{{ $receipt['id'] }}</title>
<style>
/* Keep every printed page within its upper half; overflow continues safely. */
@page{size:letter portrait;margin:8mm 10mm 147.7mm}
*{box-sizing:border-box}
body{margin:0;font-family:"DejaVu Sans",Arial,sans-serif;font-size:8px;line-height:1.15;color:#17232f;background:white}
.receipt{border:1px solid #ced8df;border-top:3px solid #16b7bd;padding:6px}
.heading{width:100%;border-collapse:collapse;margin-bottom:4px}.heading td{vertical-align:top}.heading img{width:72px;height:auto}
.title{font-size:12px;font-weight:bold;color:#102634;margin:0 0 3px}.muted{color:#536879;font-size:8px;margin:3px 0}
.meta{width:100%;border-collapse:collapse;background:#f1f7f9;margin-bottom:4px}.meta td{padding:2px 5px;border:1px solid #dae4e8;width:50%;vertical-align:top;overflow-wrap:break-word}.meta strong{display:block;margin-top:1px;font-size:8px}
.lines{width:100%;border-collapse:collapse;table-layout:fixed}.lines th{text-align:left;background:#143447;color:white;padding:4px;font-size:8px}.lines td{padding:3px 4px;border-bottom:1px solid #dce4e9;vertical-align:top;overflow-wrap:break-word}.lines thead{display:table-header-group}.lines tr{page-break-inside:avoid}.lines .number{text-align:right}.product{font-weight:bold}.description{color:#536879;margin-top:2px;font-size:8px}
.totals{width:48%;margin-left:auto;border-collapse:collapse;margin-top:4px;page-break-inside:avoid}.totals td{padding:2px 5px;border-bottom:1px solid #dce4e9}.totals .grand td{background:#edf9f9;border-top:2px solid #16b7bd;font-weight:bold;font-size:11px}
.warranty{margin-top:4px;padding:4px;border:1px solid #cbd8df;border-left:2px solid #16b7bd;page-break-inside:avoid;overflow-wrap:break-word}.warranty h3{font-size:9px;margin:0 0 3px}.warranty p{font-size:8px;line-height:1.25;margin:3px 0}
.foot{margin:5px 0 0;border-top:1px solid #dce4e9;padding-top:5px;color:#536879;font-size:8px}
.toolbar{max-width:196mm;margin:20px auto;display:flex;gap:12px}.toolbar a,.toolbar button{padding:10px 14px;border:1px solid #a6bbc8;border-radius:6px;background:white;color:#143447;cursor:pointer;text-decoration:none}.screen{max-width:196mm;margin:20px auto}@media print{.toolbar{display:none}.screen{max-width:none;margin:0}}
</style></head><body>
@if (!$pdf)<nav class="toolbar"><button type="button" onclick="window.print()">Imprimir recibo</button><a href="{{ route('accesorios.recibo-pdf', ['sale'=>$receipt['id']]) }}">Ver PDF</a></nav>@endif
<main class="{{ $pdf ? '' : 'screen' }}"><section class="receipt">
<table class="heading"><tr><td><div class="title">RECIBO DE VENTA</div><strong>Venta #{{ $receipt['id'] }}</strong><p class="muted">Accesorios y teléfonos · Planeta Gamer</p></td><td style="text-align:right"><img src="{{ $logoData }}" alt="Planeta Gamer"></td></tr></table>
<table class="meta"><tr><td>Fecha y hora · Bolivia<strong>{{ $receipt['date'] }}</strong></td><td>Atendió<strong>{{ $receipt['actor_name'] }}</strong></td></tr><tr><td>Forma de pago<strong>Efectivo</strong></td><td>Cantidad de productos<strong>{{ $receipt['quantity'] }} {{ $receipt['quantity'] === 1 ? 'unidad' : 'unidades' }}</strong></td></tr></table>
@if($receipt['customer'] ?? null)
<table class="meta"><tr><td>Cliente<strong>{{ $receipt['customer']['name'] }}</strong>CI {{ $receipt['customer']['ci'] }}</td><td>Teléfono / WhatsApp<strong>+591 {{ $receipt['customer']['phone'] }}</strong>@if($receipt['customer']['email'] ?? null)<span>{{ $receipt['customer']['email'] }}</span>@endif @if($receipt['customer']['email2'] ?? null)<br>{{ $receipt['customer']['email2'] }}@endif</td></tr></table>
@endif
<table class="lines"><thead><tr><th style="width:18%">Código</th><th style="width:38%">Producto y detalle</th><th style="width:9%" class="number">Cant.</th><th style="width:16%" class="number">Precio</th><th style="width:19%" class="number">Importe</th></tr></thead><tbody>
@foreach ($receipt['lines'] as $line)<tr><td style="font-size:8.5px">{{ $line['product_code'] }}</td><td><div class="product">{{ $line['product_name'] }}</div>@if(($line['discount_cents'] ?? 0) > 0)<div class="description">Descuento: Bs {{ \App\Support\Bolivianos::formatCompact($line['discount_cents']) }}</div>@endif@if ($line['product_description'])<div class="description">{{ $line['product_description'] }}</div>@endif</td><td class="number">{{ $line['quantity'] }}</td><td class="number">Bs {{ \App\Support\Bolivianos::formatCompact($line['unit_price_cents']) }}</td><td class="number">Bs {{ \App\Support\Bolivianos::formatCompact($line['total_cents']) }}</td></tr>@endforeach
</tbody></table>
<table class="totals">@if(($receipt['discount_cents'] ?? 0)>0)<tr><td>Subtotal</td><td style="text-align:right">Bs {{ \App\Support\Bolivianos::formatCompact($receipt['subtotal_cents']) }}</td></tr>@endif<tr><td>Descuento</td><td style="text-align:right">{{ ($receipt['discount_cents'] ?? 0) > 0 ? 'Bs '.\App\Support\Bolivianos::formatCompact($receipt['discount_cents']) : 'Sin descuento' }}</td></tr><tr class="grand"><td>Total pagado</td><td style="text-align:right">Bs {{ \App\Support\Bolivianos::formatCompact($receipt['total_cents']) }}</td></tr></table>
@foreach($receipt['lines'] as $line)
@if($line['warranty'] ?? null)
<section class="warranty">
<h3>GARANTÍA DEL EQUIPO · {{ $line['warranty']['months'] }} meses</h3>
<strong>{{ $line['product_name'] }}</strong>
<p>IMEI: {{ implode(' / ', $line['phone']['imeis']) }}<br>Condición: {{ $line['phone']['condition'] === 'NEW' ? 'Nuevo' : 'Usado / seminuevo' }} · Color: {{ $line['phone']['color'] }}</p>
<p>Inicio: <strong>{{ \Carbon\CarbonImmutable::parse($line['warranty']['starts_on'])->format('d/m/Y') }}</strong> · Vence: <strong>{{ \Carbon\CarbonImmutable::parse($line['warranty']['expires_on'])->format('d/m/Y') }}</strong></p>
@if($line['phone']['physical_state'])<p>Estado y defectos informados: {{ $line['phone']['physical_state'] }}</p>@endif
@if($line['phone']['included_accessories'])<p>Accesorios entregados: {{ $line['phone']['included_accessories'] }}</p>@endif
<p>{{ $line['warranty']['terms'] }}</p>
</section>
@endif
@endforeach
<p class="foot">Gracias por tu compra. Conserva este recibo como constancia de tu compra.</p>
</section></main>
@if ($autoPrint && !$pdf)<script>window.addEventListener('load', () => window.print(), { once: true });</script>@endif
</body></html>
