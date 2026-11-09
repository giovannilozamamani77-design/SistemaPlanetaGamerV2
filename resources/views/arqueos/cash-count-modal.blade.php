<div x-on:modal-closed.window="if ($event.detail.id === 'cash-count-editor' && $wire.cashCountOpen) $wire.dismissCashCount()">
    <x-filament::modal id="cash-count-editor" width="5xl" heading="Arqueo de efectivo" description="Opcional. Cuenta las piezas sueltas y anota el valor total embalado en Bs, sin volver a contarlo como piezas." :sticky-header="true" :sticky-footer="true">
        @if ($cashCountOpen)
            <div class="pg-cash-count-layout" x-data="{
                money(value) {
    const raw = String(value ?? '');
    if (raw === '') return 0;
    if (!/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/.test(raw)) return NaN;
    const [whole, cents = ''] = raw.replace(',', '.').split('.');
    return Number(whole) * 100 + Number(cents.padEnd(2, '0'));
}, format(cents) { return (cents / 100).toFixed(2); },
                denominations: @js(\App\Domain\Arqueos\Calculators\CashCountCalculator::DENOMINATIONS),
                loose(key) {
                    const raw = String($wire.cashCountQuantities[key] ?? '');
                    return raw === '' ? 0 : /^[0-9]{1,6}$/.test(raw) ? Number(raw) * this.denominations[key] : NaN;
                },
                packed(key) { return this.money($wire.cashCountPacked[key]); },
                get looseTotal() { return Object.keys(this.denominations).reduce((sum, key) => sum + this.loose(key), 0); },
                get packedTotal() { return Object.keys(this.denominations).reduce((sum, key) => sum + this.packed(key), 0); },
                get total() { return this.looseTotal + this.packedTotal; },
                get valid() { return Number.isFinite(this.total) && this.total <= 99999999999; }
            }">
                <div class="pg-cash-count-total" aria-live="polite"><span>Total contado</span><strong x-text="valid ? 'Bs ' + format(total) : 'Revisa los datos'">{{ $this->cashCountPreview['valid'] ? 'Bs '.\App\Support\Bolivianos::format($this->cashCountPreview['snapshot']['total_cents']) : 'Revisa los datos' }}</strong>
                    <small x-show="valid" x-text="'Sueltos: Bs ' + format(looseTotal) + ' · Embalados: Bs ' + format(packedTotal)">Sueltos: Bs {{ \App\Support\Bolivianos::format($this->cashCountPreview['snapshot']['loose_total_cents'] ?? 0) }} · Embalados: Bs {{ \App\Support\Bolivianos::format($this->cashCountPreview['snapshot']['packed_total_cents'] ?? 0) }}</small>
                </div>
                <div class="pg-cash-count-columns">
                    @foreach (['Billetes' => \App\Domain\Arqueos\Calculators\CashCountCalculator::BILLS, 'Monedas' => \App\Domain\Arqueos\Calculators\CashCountCalculator::COINS] as $group => $denominations)
                        <section class="pg-cash-count-column"><h3><x-filament::icon :icon="$group === 'Billetes' ? 'heroicon-o-banknotes' : 'heroicon-o-circle-stack'" />{{ $group }}</h3>
                            <table><thead><tr><th scope="col">Corte</th><th scope="col">Piezas</th><th scope="col">Embalados Bs</th><th scope="col">Total Bs</th></tr></thead><tbody>
                            @foreach ($denominations as $key => $cents)
                                <tr wire:key="cash-count-{{ $key }}"><th scope="row">{{ \App\Support\Bolivianos::format($cents) }}</th>
                                    <td><x-filament::input.wrapper :valid="!isset($this->cashCountPreview['errors']['quantities.'.$key])"><x-filament::input type="text" inputmode="numeric" autocomplete="off" placeholder="0" :aria-label="$group.' de Bs '.\App\Support\Bolivianos::format($cents).' piezas sueltas'" wire:model="cashCountQuantities.{{ $key }}" /></x-filament::input.wrapper></td>
                                    <td><x-filament::input.wrapper :valid="!isset($this->cashCountPreview['errors']['packed_amounts.'.$key])"><x-filament::input type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" :aria-label="$group.' de Bs '.\App\Support\Bolivianos::format($cents).' importe total embalado'" wire:model="cashCountPacked.{{ $key }}" /></x-filament::input.wrapper></td>
                                    <td x-text="valid ? format(loose(@js($key)) + packed(@js($key))) : '—'">{{ $this->cashCountPreview['valid'] ? \App\Support\Bolivianos::format($this->cashCountPreview['snapshot']['row_total_cents'][$key]) : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody></table>
                        </section>
                    @endforeach
                </div>
                @if (!$this->cashCountPreview['valid'])<p role="alert" class="pg-closing-error">{{ implode(' ', array_unique(array_values($this->cashCountPreview['errors']))) }}</p>@endif
                @error('cashCount')<p role="alert" class="pg-closing-error">{{ $message }}</p>@enderror
            </div>
        @endif
        <x-slot name="footer"><div class="pg-cash-count-actions"><x-filament::button color="gray" wire:click="dismissCashCount" wire:loading.attr="disabled">Volver sin aplicar</x-filament::button><x-filament::button icon="heroicon-o-check" wire:click="applyCashCount" wire:loading.attr="disabled">Aplicar al efectivo</x-filament::button></div></x-slot>
    </x-filament::modal>
</div>
