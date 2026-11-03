<x-filament-panels::page>
@php($discounts=$this->rulesFor('DISCOUNTS'))
@php($commissions=$this->rulesFor('COMMISSIONS'))
<div class="grid gap-4 lg:grid-cols-2">
    @foreach(['DISCOUNTS'=>['Descuentos','heroicon-o-tag',$discounts,'discountsAction'],'COMMISSIONS'=>['Comisiones','heroicon-o-banknotes',$commissions,'commissionsAction']] as $scope=>[$title,$icon,$current,$action])
    <x-filament::section :icon="$icon" icon-color="info">
        <x-slot name="heading">{{ $title }}</x-slot>
        <x-slot name="afterHeader">{{ $this->{$action} }}</x-slot>
        <div class="space-y-3 text-sm">
            <strong>Accesorios · por unidad</strong>
            @foreach($current['rules']['ranges'] as $i=>$range)
            <div class="flex items-center justify-between gap-3 border-b border-gray-500/20 pb-2">
                <span>Desde Bs {{ \App\Support\Bolivianos::formatCompact($range['from_cents']) }}@if(isset($current['rules']['ranges'][$i+1])) hasta Bs {{ \App\Support\Bolivianos::formatCompact($current['rules']['ranges'][$i+1]['from_cents']-1) }}@endif</span>
                <strong>Bs {{ \App\Support\Bolivianos::formatCompact($range['amount_cents']) }}</strong>
            </div>
            @endforeach
            <p><strong>Teléfonos:</strong> {{ $scope==='DISCOUNTS' ? 'máximo Bs '.\App\Support\Bolivianos::formatCompact($current['rules']['phone_max_cents']) : number_format($current['rules']['phone_bps']/100,2,',','.').' % de la ganancia final' }}</p>
            @if($scope==='COMMISSIONS')<p>{{ $current['rules']['enabled'] ? 'Activas para nuevas ventas.' : 'Pausadas. Las comisiones anteriores se conservan.' }}</p>@endif
        </div>
    </x-filament::section>
    @endforeach
</div>
<x-filament::section heading="Cómo se registra" icon="heroicon-o-arrow-path" icon-color="warning">
<p class="text-sm">Precio del catálogo → total final editable → validar descuento y costo → calcular comisión → confirmar una sola venta y acumular la comisión del responsable.</p>
<p class="mt-2 text-sm">Los descuentos de accesorios no reducen su comisión, salvo que la ganancia disponible sea menor. En teléfonos, el descuento reduce la ganancia y su comisión. Los pagos de comisiones se gestionarán en una entrega posterior.</p>
</x-filament::section>
<x-filament::section heading="Últimos cambios" collapsible collapsed>
@forelse($this->changes() as $change)<p class="py-2 text-sm">{{ $change->created_at }} · {{ $change->actor_name }} · {{ $change->scope==='DISCOUNTS'?'Descuentos':'Comisiones' }} · versión {{ $change->previous_revision+1 }}</p>@empty<p class="text-sm">Sin cambios. Se muestran los valores iniciales acordados.</p>@endforelse
</x-filament::section>
</x-filament-panels::page>
