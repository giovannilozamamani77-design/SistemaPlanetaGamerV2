<section class="pg-closing-counter-card pg-closing-restoration">
    <h2><x-filament::icon icon="heroicon-o-banknotes" />Reposición administrativa</h2>
    <p>Conserva los saldos del conteo original. El aporte se suma por separado; no modifica el capital ni borra el faltante.</p>
    @if ($this->financialAssessment['valid'])
        <p>Capital por reponer: <strong>Bs {{ \App\Support\Bolivianos::format($this->financialAssessment['liquidation']['shortage_cents']) }}</strong></p>
        @if ($this->financialAssessment['restored']['restoration'])
            <p class="pg-closing-summary-warning">Aporte autorizado: Bs {{ \App\Support\Bolivianos::format($this->financialAssessment['restored']['restoration']['amount_cents']) }}. Se registrará junto al cierre; no vuelvas a incluirlo en los saldos originales.</p>
        @endif
    @else<p class="pg-closing-error" role="alert">{{ $this->financialAssessment['message'] }}</p>@endif
    <details><summary>Registrar aporte recibido</summary>
        <p>Solo dinero ya recibido. Si cancelas la preparación, el dinero entregado no se revierte: conserva su referencia y regístralo antes de continuar operando.</p>
        <div class="pg-closing-restoration-fields"><label>Cuenta que recibió el aporte<select wire:model="restorationInput.component">@foreach ($this->context['accounts'] as $code => $account)<option value="{{ $code }}">{{ $account['name'] }}</option>@endforeach</select></label>
        <label>Importe recibido<x-filament::input.wrapper prefix="Bs"><x-filament::input wire:model="restorationInput.amount" inputmode="decimal" /></x-filament::input.wrapper></label>
        <label>Referencia o detalle<x-filament::input.wrapper><x-filament::input wire:model="restorationInput.receipt" maxlength="2000" /></x-filament::input.wrapper></label>
        <label>Administración que autoriza<select wire:model="restorationAdminId"><option value="">Selecciona</option>@foreach ($this->restorationAdministrators as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        <label>PIN de Administración (solo si autoriza otra persona)<x-filament::input.wrapper><x-filament::input type="password" wire:model="restorationAdminPin" inputmode="numeric" maxlength="4" autocomplete="off" /></x-filament::input.wrapper></label>
        <label><input type="checkbox" wire:model="restorationInput.received"> Confirmo que el aporte fue recibido realmente.</label>
        <x-filament::button wire:click="authorizeRestoration" wire:loading.attr="disabled" color="warning">Validar aporte recibido</x-filament::button>
        </div>
    </details>
    @foreach (['restoration', 'amount', 'component', 'receipt', 'received', 'pin', 'admin_id'] as $field)@error($field)<p role="alert" class="pg-closing-error">{{ $message }}</p>@enderror @endforeach
</section>
