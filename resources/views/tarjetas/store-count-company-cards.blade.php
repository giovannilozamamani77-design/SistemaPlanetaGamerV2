    <div class="pg-store-count-companies">
        @foreach ($rows->groupBy('company', preserveKeys: true) as $company => $lines)
            <section class="pg-store-count-company" data-company="{{ $company }}" aria-label="Conteo {{ \App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES[$company] ?? $company }}">
                <header>@include('tarjetas.company-logo', ['company' => $company, 'logoClass' => 'pg-sale-cut-logo'])<h3>{{ \App\Domain\Tarjetas\Data\CardInventoryInput::COMPANIES[$company] ?? $company }}</h3></header>
                <div class="pg-store-count-grid">
                    @foreach ($lines as $key => $line)
                        <div class="pg-store-count-cut" data-company="{{ $company }}" wire:key="count-cut-{{ $line['product_id'] }}">
                            <strong>{{ $line['label'] }}</strong>
                            <div class="pg-store-count-values">
                                <div><small>Esperado</small><b>{{ $line['expected'] }}</b></div>
                                <label for="store-count-{{ $line['product_id'] }}"><small>Contado</small><input id="store-count-{{ $line['product_id'] }}" type="text" inputmode="numeric" aria-label="Cantidad contada {{ $company }} {{ $line['label'] }}" wire:model.live.debounce.350ms="{{ $getStatePath() }}.{{ $key }}.quantity" /></label>
                            </div>
                            @if ($line['reserved'])<small class="pg-store-count-reserved">Incluye {{ $line['reserved'] }} en reserva</small>@endif
                            @if ($getLivewire()->getErrorBag()->has($getStatePath().'.'.$key.'.quantity'))<p class="pg-sale-error">{{ $getLivewire()->getErrorBag()->first($getStatePath().'.'.$key.'.quantity') }}</p>@endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
