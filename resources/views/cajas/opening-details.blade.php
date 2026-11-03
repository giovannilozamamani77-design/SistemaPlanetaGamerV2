<div class="pg-workspace pg-opening-details">
    <div class="pg-workspace-information"><strong>{{ $workspace['box'] }} · {{ $workspace['cashier'] }}</strong><p>Sesión #{{ $workspace['session_id'] }} · Apertura {{ $workspace['opened_at'] }}</p></div>
    <div class="pg-workspace-capital"><span>Capital base <strong>Bs {{ \App\Support\Bolivianos::format($workspace['base_capital']) }}</strong></span><span>Capital operativo <strong>Bs {{ \App\Support\Bolivianos::format($workspace['operating_capital']) }}</strong></span></div>
    <div class="pg-workspace-accounts">
        @foreach($workspace['accounts'] as $code=>$account)
            <article><div class="pg-opening-account-label"><x-filament::icon :icon="\App\Filament\Support\Cajas\CashBoxComponentIcon::for($code, $account)" /><span>{{ $account['name'] }}</span></div><small>Saldo de apertura</small><strong>Bs {{ \App\Support\Bolivianos::format($workspace['opening_balances'][$code]) }}</strong><small>Saldo actual: Bs {{ \App\Support\Bolivianos::format($workspace['balances'][$code]) }}</small>@if(!empty($account['reference']))<small>{{ $account['reference'] }}</small>@endif</article>
        @endforeach
    </div>

    @include('arqueos.count-details', ['count' => $workspace['opening_count_details']['cash_count'] ?? null])

    <p>La apertura conserva su registro original. Los saldos actuales incluyen los movimientos posteriores.</p>
</div>
