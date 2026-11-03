<x-dynamic-component :component="$getFieldWrapperView()" :field="$field" :has-errors="$getLivewire()->getErrorBag()->has($getStatePath())" :error-message="$getLivewire()->getErrorBag()->first($getStatePath())">
    @php
        $allRows = collect($getState() ?? []);
        $rows = ($onlyStocked ?? false) ? $allRows->where('count_in_opening', true) : $allRows;
        $otherRows = ($onlyStocked ?? false) ? $allRows->where('count_in_opening', false) : collect();
    @endphp
    @if ($rows->isNotEmpty())
        @include('tarjetas.store-count-company-cards', ['rows' => $rows])
    @else
        <p class="pg-count-summary-note">No hay tarjetas con existencias para contar.</p>
    @endif
    @if ($otherRows->isNotEmpty())
        <details class="pg-count-cut-details" aria-label="Cortes sin existencias esperadas">
            <summary>Otros cortes encontrados (solo si corresponde)</summary>
            @include('tarjetas.store-count-company-cards', ['rows' => $otherRows])
        </details>
    @endif
</x-dynamic-component>
