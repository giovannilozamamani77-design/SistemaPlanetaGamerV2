@php($differences = $getState())
<div class="flex flex-col items-start gap-1">
    @if ($differences['shortage_cents'])<x-filament::badge color="danger">Faltante Bs {{ \App\Support\Bolivianos::formatCompact($differences['shortage_cents']) }}</x-filament::badge>@endif
    @if ($differences['cash_surplus_cents'])<x-filament::badge color="warning">Sobrante efectivo Bs {{ \App\Support\Bolivianos::formatCompact($differences['cash_surplus_cents']) }}</x-filament::badge>@endif
    @if ($differences['stock_surplus'])<x-filament::badge color="warning">Sobrante stock {{ $differences['stock_surplus'] }} unid.</x-filament::badge>@endif
    @if (! $differences['shortage_cents'] && ! $differences['cash_surplus_cents'] && ! $differences['stock_surplus'])<x-filament::badge color="gray">Sin diferencias</x-filament::badge>@endif
</div>
