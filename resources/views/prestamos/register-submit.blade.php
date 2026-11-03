@if($capitalStatus['insufficient'])
    <div class="pg-loan-capital-alert" role="alert">
        <strong>Capital insuficiente para este préstamo</strong>
        <p>Disponible: Bs {{ \App\Support\Bolivianos::formatCompact($capitalStatus['available_cents']) }} · Solicitado: Bs {{ \App\Support\Bolivianos::formatCompact($capitalStatus['requested_cents']) }} · <strong>Faltan Bs {{ \App\Support\Bolivianos::formatCompact($capitalStatus['shortfall_cents']) }}</strong></p>
        <p>Reduce el capital a prestar o solicita a Administración que registre un aporte en Yape Préstamos.</p>
    </div>
@endif
<div class="pg-loan-form-actions"><x-filament::button type="submit" :disabled="$capitalStatus['insufficient']" icon="heroicon-o-check-circle" wire:loading.attr="disabled" wire:target="register">Confirmar préstamo</x-filament::button><x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\Prestamos\LoanList::getUrl()">Cancelar</x-filament::button></div>
