<div class="grid gap-2" aria-live="polite">
    <label for="{{ $onlyRate ? 'phone-applied-rate' : 'phone-bob-cost' }}" class="flex items-center gap-2 font-medium">
        @include('accesorios.icons.field-image', ['name' => $onlyRate ? 'mode' : 'unit_cost'])
        {{ $onlyRate ? 'Cambio global aplicado' : 'Costo por equipo en bolivianos' }}
    </label>
    <x-filament::input.wrapper prefix="Bs" :suffix="$onlyRate ? '/ USD' : null">
        <x-filament::input
            :id="$onlyRate ? 'phone-applied-rate' : 'phone-bob-cost'"
            type="text"
            :value="$onlyRate ? ($preview['rate'] ?? '') : ($preview['cost'] ?? '0')"
            :placeholder="$onlyRate ? 'Sin referencia válida' : null"
            readonly
            aria-readonly="true"
        />
    </x-filament::input.wrapper>
    @if($preview['error'])<p class="pg-product-error">{{ $preview['error'] }}</p>@endif
</div>
