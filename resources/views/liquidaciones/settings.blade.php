<x-filament-panels::page>
    <div class="pg-liquidation-settings-top"><p>Registra las cuentas de destino y elige una para cada caja. El origen, el destino y los turnos se aplican a próximas aperturas.</p><x-filament::button color="gray" tag="a" :href="\App\Filament\Resources\Liquidaciones\History\LiquidationHistoryResource::getUrl()" icon="heroicon-o-arrow-left">Volver al historial</x-filament::button></div>
    <div>{{ $this->accountSettingsAction }}</div>
    <section class="pg-liquidation-rules-grid" aria-label="Cuentas de destino">
        @forelse ($this->accounts as $account)
            <article class="pg-liquidation-destination">
                @include('liquidaciones.account', ['account' => $account])
                <x-filament::button color="gray" icon="heroicon-o-pencil-square" wire:click="mountAction('accountSettings', { account_id: {{ $account->id }} })">Editar cuenta</x-filament::button>
            </article>
        @empty
            <p>Agrega una cuenta de destino para poder asignarla a tus cajas.</p>
        @endforelse
    </section>
    @if ($this->demoAccount)<details class="pg-liquidation-demo"><summary>Ver cuenta e imagen de ejemplo</summary>@include('liquidaciones.account', ['account' => $this->demoAccount])</details>@endif
    <section class="pg-liquidation-rules-grid">
        @foreach ($this->boxes as $box)
            <article class="pg-liquidation-rule-card"><h2><x-filament::icon icon="heroicon-o-building-storefront" />{{ $box['name'] }}</h2><dl><div><dt>Descontar desde</dt><dd>{{ $box['source_label'] }}</dd></div><div><dt>Cuenta / referencia</dt><dd>{{ $box['source_reference'] ?: 'Sin referencia' }}</dd></div><div><dt>Enviar a</dt><dd>{{ $box['destination_label'] }}<br>{{ $box['destination_reference'] }}</dd></div><div><dt>Turno mañana</dt><dd>{{ $box['morning'] ? 'Con liquidación' : 'Sin liquidación' }}</dd></div><div><dt>Turno tarde</dt><dd>{{ $box['afternoon'] ? 'Con liquidación' : 'Sin liquidación' }}</dd></div></dl>
            @if ($box['configured'])<x-filament::button color="gray" icon="heroicon-o-cog-6-tooth" wire:click="mountAction('configureBox', { box_id: {{ $box['id'] }} })">Configurar esta caja</x-filament::button>@else<p>Primero registra sus cuentas y turnos en Configuración de cajas.</p>@endif
            </article>
        @endforeach
    </section>
</x-filament-panels::page>
