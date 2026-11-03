<div class="pg-correction-feedback-modal">
    <p class="pg-correction-alert-summary" role="status"
        x-data="{ accessId: {{ $accessId }}, unread: {{ $alerts['unread'] }}, returned: {{ $alerts['returned'] }} }"
        x-on:cash-box-correction-alerts-updated.window="if ($event.detail.accessId === accessId) { unread = $event.detail.unread; returned = $event.detail.returned }">
        Respuestas nuevas: <span x-text="unread">{{ $alerts['unread'] }}</span> · Devueltas para completar: <span x-text="returned">{{ $alerts['returned'] }}</span>
    </p>
    @if ($feedback)
                    <div class="pg-correction-feedback-list">
                        @foreach ($feedback as $feedback)
                            <article class="pg-correction-section" wire:key="correction-feedback-{{ $feedback['request_id'] }}">
                                <header><strong>{{ $feedback['label'] }} · {{ $feedback['correction_status'] }}</strong><span>{{ $feedback['decided_at'] }}</span></header>
                                @if ($feedback['is_unread'])<span class="pg-correction-new-label">Nueva respuesta</span>@endif
                                <p class="pg-correction-reason">{{ $feedback['decision_reason'] }}</p>
                                <div class="pg-correction-feedback-actions">
                                    <x-filament::button size="sm" color="gray" icon="heroicon-o-eye" wire:click="replaceMountedAction('respuestaCorreccion', { operation_id: {{ $feedback['id'] }} })">Ver respuesta e historial</x-filament::button>
                                    @if ($feedback['correction_status'] === 'DEVUELTA')<x-filament::button size="sm" icon="heroicon-o-pencil-square" wire:click="replaceMountedAction('solicitarCorreccion', { operation_id: {{ $feedback['id'] }} })">Completar solicitud</x-filament::button>@endif
                                </div>
                            </article>
                        @endforeach
                    </div>
    @else
        <p class="pg-workspace-empty">Todavía no hay respuestas de Administración para esta caja.</p>
    @endif
</div>
