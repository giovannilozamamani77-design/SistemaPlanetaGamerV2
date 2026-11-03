<span class="pg-prepaid-company" data-company="{{ $company }}">
    <img src="{{ asset('images/cajas/components/'.strtolower($company).($company === 'TIGO' ? '.png' : '.jpg')) }}" alt="" width="112" height="112" />
    <span class="pg-prepaid-company-name">{{ $name }}</span>
    <span class="pg-prepaid-company-caption">Tarjetas de recarga</span>
</span>
