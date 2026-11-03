<x-dynamic-component :component="$getFieldWrapperView()" :field="$field" :has-errors="$getLivewire()->getErrorBag()->has($getStatePath())" :error-message="$getLivewire()->getErrorBag()->first($getStatePath())">
    <div class="pg-purchase-grid" data-company="{{ $company }}">
        @forelse ($cards as $key => $card)
            <article class="pg-purchase-cut" wire:key="purchase-cut-{{ $key }}-{{ $getState()[$key]['product_id'] ?? 0 }}">
                <strong>{{ $card['label'] }}</strong>
                <div class="pg-purchase-inputs">
                    <label for="purchase-qty-{{ $key }}">Cantidad<input id="purchase-qty-{{ $key }}" type="text" inputmode="numeric" wire:model.live.debounce.350ms="{{ $getStatePath() }}.{{ $key }}.quantity" /></label>
                    @if (! $delivery)<label for="purchase-price-{{ $key }}">Precio por unidad (Bs)<input id="purchase-price-{{ $key }}" type="text" inputmode="decimal" wire:model.live.debounce.350ms="{{ $getStatePath() }}.{{ $key }}.unit_price" /></label>@endif
                </div>
                @if (! $delivery)<span>Subtotal: Bs {{ $card['subtotal'] }}</span>@endif
                @foreach (['quantity', 'unit_price'] as $part)
                    @if ($getLivewire()->getErrorBag()->has($getStatePath().'.'.$key.'.'.$part))<p class="pg-sale-error">{{ $getLivewire()->getErrorBag()->first($getStatePath().'.'.$key.'.'.$part) }}</p>@endif
                @endforeach
            </article>
        @empty
            <p>No hay cortes activos para esta empresa. Contacta con Administración.</p>
        @endforelse
    </div>
</x-dynamic-component>
