<div style="display:grid;gap:0.6rem;">
    <p>Pendiente confirmado: <strong>Bs {{ \App\Support\Bolivianos::format($pending) }}</strong></p>
    @if ($account)
        <p>Deposita a: <strong>{{ $account->name }} · {{ $account->destination }}</strong></p>
        <p>Titular: {{ $account->holder_name ?: 'Sin titular configurado' }}</p>
    @else
        <p>Administración debe configurar el destino de reposiciones antes de informar el pago.</p>
    @endif
    @if ($blocked)<p>Ya tienes un depósito por verificar. Espera su revisión antes de informar otro.</p>@endif
    <p>También puedes usar «Reponer» en cada faltante. Se cobra únicamente el pendiente de ese registro.</p>
</div>
