<form wire:submit="registerQuickCommission('{{ $kind }}')" class="pg-quick-commission">
    <h4>@svg($kind === 'ACH' ? 'heroicon-o-building-library' : 'heroicon-o-document-text') {{ $title }}</h4>
    @if ($kind === 'ACH')
        <label for="quick-ach-movement">Movimiento</label><select id="quick-ach-movement" wire:model="quickAch.movement"><option value="DEPOSITO">Depósito</option><option value="RETIRO">Retiro</option></select>
        <label for="quick-ach-amount">Monto de la operación</label><input id="quick-ach-amount" wire:model.live.debounce.300ms="quickAch.amount" inputmode="decimal" placeholder="0.00" autocomplete="off" required>
    @else
        <label for="quick-services-bills">Cantidad de facturas</label><input id="quick-services-bills" type="number" min="1" max="10000" wire:model.live.debounce.300ms="quickServices.bill_count" placeholder="0" required><small>Bs 0,50 por factura, sin importar cuántos meses incluya.</small>
    @endif
    <div class="pg-quick-preview" aria-live="polite"><span>Comisión automática</span><strong>{{ $this->quickCommissionPreview($kind) === null ? 'Bs 0.00' : 'Bs '.$this->quickCommissionPreview($kind) }}</strong></div>
    <label for="{{ $path }}-manual">Comisión manual (opcional)</label><input id="{{ $path }}-manual" wire:model="{{ $path }}.manual_fee" inputmode="decimal" placeholder="Dejar vacío para automática">
    @if ($errors->has($path.'.*'))<div class="pg-quick-errors" role="alert">@foreach ($errors->get($path.'.*') as $messages)@foreach ($messages as $message)<p>{{ $message }}</p>@endforeach @endforeach</div>@endif
    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="registerQuickCommission">{{ $kind === 'ACH' ? 'Registrar ACH' : 'Registrar facturas' }}</x-filament::button>
</form>
