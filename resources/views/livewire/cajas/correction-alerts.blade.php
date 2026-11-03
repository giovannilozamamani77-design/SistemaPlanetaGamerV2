<div x-data="{ tabVisible: !document.hidden }"
    x-on:visibilitychange.document="tabVisible = !document.hidden; if (tabVisible) $wire.refreshAlerts()">
    @if ($active)
        <template x-if="tabVisible">
            <span wire:poll.15s.visible="refreshAlerts">
                <x-filament::icon-button
                    label="Historial de solicitudes y respuestas"
                    :icon="$alerts['unread'] > 0 ? 'heroicon-o-bell-alert' : 'heroicon-o-clock'"
                    :color="$alerts['unread'] > 0 ? 'primary' : ($alerts['returned'] > 0 ? 'warning' : 'gray')"
                    :badge="$alerts['returned'] > 0 ? (string) $alerts['returned'] : null"
                    :tooltip="'Respuestas nuevas: '.$alerts['unread'].' · Devueltas para completar: '.$alerts['returned']"
                    :class="$alerts['unread'] > 0 ? 'pg-history-unread' : ''"
                    wire:click="$parent.mountAction('historialSolicitudes')" />
            </span>
        </template>
    @endif
</div>
