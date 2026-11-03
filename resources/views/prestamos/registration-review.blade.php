@if($terms)
    <div class="pg-loan-review">
        <div class="pg-loan-review-parties">
            <section class="pg-loan-review-card" data-tone="client" aria-label="Cliente del préstamo">
                <h3>Cliente</h3>
                <strong class="pg-loan-review-name">{{ $review['name'] }}</strong>
                <dl class="pg-loan-review-facts">
                    <div><dt>CI</dt><dd>{{ $review['ci'] }}</dd></div>
                    @if(filled($review['phone'] ?? null))
                        <div><dt>Teléfono o WhatsApp</dt><dd>+591 {{ $review['phone'] }}</dd></div>
                    @endif
                </dl>
            </section>
            <section class="pg-loan-review-card" data-tone="object" aria-label="Objeto en garantía">
                <h3>Objeto en garantía</h3>
                <strong class="pg-loan-review-name">{{ $terms['type_name'] }}</strong>
                <p class="pg-loan-review-description">{{ $review['description'] }}</p>
                @if(filled($review['serial'] ?? null) || filled($review['condition'] ?? null) || filled($review['accessories'] ?? null))
                    <dl class="pg-loan-review-facts">
                        @foreach(['serial' => 'Serie / IMEI', 'condition' => 'Estado físico', 'accessories' => 'Accesorios'] as $key => $label)
                            @if(filled($review[$key] ?? null))
                                <div><dt>{{ $label }}</dt><dd>{{ $review[$key] }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                @endif
            </section>
        </div>
        <section class="pg-loan-review-card" aria-label="Fechas y plazo del préstamo">
            <div class="pg-loan-review-section-heading">
                <h3>Fechas y plazo</h3>
                <span>{{ $terms['schedule_mode'] === 'MANUAL' ? 'Fechas manuales' : 'Fechas automáticas' }}</span>
            </div>
            <dl class="pg-loan-review-dates">
                <div><dt>Inicio</dt><dd>{{ \Carbon\CarbonImmutable::parse($terms['start_date'])->format('d/m/Y') }}</dd></div>
                <div><dt>Vencimiento</dt><dd>{{ \Carbon\CarbonImmutable::parse($terms['due_date'])->format('d/m/Y') }}</dd></div>
                <div><dt>Plazo</dt><dd>{{ $terms['days'] }} días</dd></div>
            </dl>
            <p class="pg-loan-review-caption">Vence al finalizar el día, hora de Bolivia.</p>
        </section>
        <section aria-label="Importes que se guardarán">
            <dl class="pg-loan-review-amounts">
                <div><dt>Capital prestado</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($terms['capital_cents']) }}</dd></div>
                <div data-tone="interest"><dt>Interés del periodo</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($terms['interest_cents']) }}</dd></div>
                <div data-tone="total"><dt>Total a pagar</dt><dd>Bs {{ \App\Support\Bolivianos::formatCompact($terms['total_cents']) }}</dd></div>
            </dl>
            <p class="pg-loan-review-caption">{{ $terms['interest_mode'] === 'MANUAL' ? 'Interés manual para este periodo.' : 'Interés automático para el plazo seleccionado.' }}</p>
        </section>
        <p class="pg-loan-review-warning">Recuerda revisar <strong>{{ $terms['type_name'] }}</strong>: estado físico, funcionamiento y accesorios antes de confirmar.</p>
    </div>
@endif
