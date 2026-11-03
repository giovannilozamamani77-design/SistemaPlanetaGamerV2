<x-filament-panels::page>
    <div class="pg-loan-registration" data-layout="cashbox" x-data="{
        layout: 'cashbox',
        init() {
            const key = 'pg-loan-layout:{{ auth()->id() }}';
            try {
                const saved = localStorage.getItem(key);
                if (['compact', 'columns', 'cashbox'].includes(saved)) this.layout = saved;
            } catch (error) {}
            this.$watch('layout', value => {
                try { localStorage.setItem(key, value); } catch (error) {}
            });
        }
    }" x-bind:data-layout="layout">
        <header class="pg-loan-registration-heading">
            <div class="pg-loan-heading-identity">
                <h1>Realizar préstamo</h1>
                <span>Responsable: <strong>{{ auth()->user()->name }}</strong></span>
            </div>
            <div class="pg-loan-layout-switch" role="group" aria-label="Distribución del formulario">
                <button type="button" x-on:click="layout = 'compact'" x-bind:aria-pressed="layout === 'compact'" title="Cuatro secciones en dos columnas">
                    <x-heroicon-o-squares-2x2 /> Compacto
                </button>
                <button type="button" x-on:click="layout = 'columns'" x-bind:aria-pressed="layout === 'columns'" title="Cliente, objeto y fechas en columnas cuando haya espacio">
                    <x-heroicon-o-view-columns /> En columnas
                </button>
                <button type="button" x-on:click="layout = 'cashbox'" x-bind:aria-pressed="layout === 'cashbox'" title="Indicadores arriba y acciones al costado">
                    <x-heroicon-o-building-storefront /> Estilo Cajas
                </button>
            </div>
            <div class="pg-loan-available-capital" data-insufficient="{{ $capitalStatus['insufficient'] ? 'true' : 'false' }}" aria-label="Capital disponible para prestar">
                <span>Capital disponible</span>
                <strong>Bs {{ \App\Support\Bolivianos::formatCompact($availableCapital) }}</strong>
            </div>
        </header>
        <section class="pg-loan-cashbox-metrics" aria-label="Resumen del préstamo" aria-live="polite">
            <article data-tone="available" data-insufficient="{{ $capitalStatus['insufficient'] ? 'true' : 'false' }}">
                <x-heroicon-o-wallet />
                <div><span>Capital disponible</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($availableCapital) }}</strong><small>Fondo de préstamos</small></div>
            </article>
            <article data-tone="capital">
                <x-heroicon-o-banknotes />
                <div><span>Capital a prestar</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($registrationTerms['capital_cents'] ?? $capitalStatus['requested_cents'] ?? 0) }}</strong><small>Importe que recibe el cliente</small></div>
            </article>
            <article data-tone="interest">
                <x-heroicon-o-calculator />
                <div><span>Interés del periodo</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($registrationTerms['interest_cents'] ?? 0) }}</strong><small>{{ ($registrationTerms['interest_mode'] ?? 'AUTO') === 'MANUAL' ? 'Interés manual' : 'Según objeto y plazo' }}</small></div>
            </article>
            <article data-tone="total">
                <x-heroicon-o-receipt-percent />
                <div><span>Total a pagar</span><strong>Bs {{ \App\Support\Bolivianos::formatCompact($registrationTerms['total_cents'] ?? 0) }}</strong><small>Capital e interés</small></div>
            </article>
        </section>
        <form wire:submit="register" class="pg-loan-form pg-loan-form-compact">
            {{ $this->form }}
            <aside class="pg-loan-registration-actions">
                <div class="pg-loan-cashbox-only">
                    <h2>Acciones del préstamo</h2>
                    <p>Revisa los datos antes de confirmar.</p>
                </div>
                @include('prestamos.register-submit')
                <div class="pg-loan-cashbox-only pg-loan-registration-help">
                    <h3>Información del registro</h3>
                    <p>Responsable: <strong>{{ auth()->user()->name }}</strong></p>
                    <p>Al confirmar podrás revisar el cliente, el objeto, las fechas y los importes.</p>
                    <p>Los detalles adicionales se completan desde el botón del objeto.</p>
                </div>
            </aside>
        </form>
        @if(($this->mountedActions[0]['name'] ?? null) === 'confirmLoan')
            <div wire:loading.flex wire:target="callMountedAction" class="pg-loan-saving" role="status">
                <span class="pg-loan-spinner" aria-hidden="true"></span><strong>Registrando préstamo…</strong>
            </div>
        @endif
    </div>
</x-filament-panels::page>
