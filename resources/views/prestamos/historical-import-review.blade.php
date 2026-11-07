<div class="space-y-4">
    @if($review)
        <p><strong>Referencia anterior:</strong> {{ $review['external_reference'] }} · <strong>Cliente:</strong> {{ $review['name'] }} · CI {{ $review['ci'] }}</p>
        <p><strong>Objeto:</strong> {{ $review['type_name'] }} — {{ $review['description'] }}</p>
        <p><strong>Cajero original:</strong> {{ $review['original_cashier_name'] }} · <strong>Fecha original:</strong> {{ $review['occurred_at'] }} · Bolivia</p>
        <p><strong>Vencimiento:</strong> {{ $review['due_date'] }} · <strong>Estado resultante:</strong> {{ $review['display_status'] }}</p>
        <p><strong>Teléfono original:</strong> {{ $review['phone'] ?? 'Sin teléfono' }} · <strong>Medio de entrega:</strong> {{ $review['delivery_method'] === 'YAPE' ? 'Yape' : 'Efectivo' }}</p>
        <p><strong>Tasa original:</strong> {{ \App\Support\Bolivianos::formatCompact($review['rate_bps']) }} % / 30 días · <strong>Plazo declarado:</strong> {{ $review['days'] }} días</p>
        @foreach(['serial' => 'Serie / IMEI', 'condition' => 'Estado físico', 'accessories' => 'Accesorios', 'closed_at' => 'Fecha y hora del cierre histórico', 'closed_by_name' => 'Responsable del cierre original', 'expenses_at' => 'Fecha del gasto histórico', 'expenses_description' => 'Gastos originales', 'difference_reason' => 'Observaciones del cierre'] as $field => $label)
            @if(filled($review[$field] ?? null))<p><strong>{{ $label }}:</strong> {{ $review[$field] }}</p>@endif
        @endforeach
        <p><strong>Fuente / motivo:</strong> {{ $review['reason'] }}</p>
        @if($review['current_collection_cents'] !== null && in_array($review['display_status'], ['ACTIVO', 'CON_MULTA'], true))<p><strong>Total actual para recoger según las reglas vigentes:</strong> Bs {{ \App\Support\Bolivianos::format($review['current_collection_cents']) }}{{ $review['display_status'] === 'CON_MULTA' ? ' (incluye multa actual)' : '' }}.</p>@endif
        <dl class="grid grid-cols-2 gap-3">
            @foreach(['capital_cents' => 'Desembolso histórico', 'interest_cents' => 'Interés original', 'expense_cents' => 'Gastos históricos del objeto', 'received_cents' => 'Cobro / devolución histórico', 'fund_before_cents' => 'Fondo disponible antes', 'fund_delta_cents' => 'Ajuste neto que se registrará ahora', 'fund_after_cents' => 'Fondo disponible después'] as $field => $label)
                <div><dt>{{ $label }}</dt><dd><strong>Bs {{ \App\Support\Bolivianos::format($review[$field]) }}</strong></dd></div>
            @endforeach
        </dl>
        <p>Se incorporan el desembolso, los gastos declarados y el cobro históricos una sola vez. El ajuste es contable; no se ejecuta un pago bancario ni una nueva entrega de dinero.</p>
        @if($review['display_status'] === 'PERDIDA_TOTAL')<p role="alert">Por su estado o vencimiento, quedará en pérdida total. No permitirá pago ordinario y entrega; se aplicará el flujo actual de objetos perdidos.</p>@endif
        <p>Al confirmar será un registro permanente con su auditoría. Revisa y corrige antes de importar; cancelar no modifica el fondo.</p>
    @endif
</div>
