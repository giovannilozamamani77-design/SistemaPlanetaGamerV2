<div class="space-y-3 text-sm">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-filament::badge color="info" icon="heroicon-o-beaker">Mensaje de prueba</x-filament::badge>
        <span>Prueba #{{ $id }} · {{ $created_at->timezone('America/La_Paz')->format('d/m/Y H:i:s') }}</span>
    </div>
    <div class="grid gap-3 md:grid-cols-2">
        <div class="rounded-xl border border-gray-500/20 p-3"><small>Bot · {{ \App\Domain\Notificaciones\Queries\TelegramSettingsQuery::MODULES[$module] ?? 'Módulo no disponible' }}</small><strong class="block">{{ $bot_name }}</strong>@if($bot_name_note)<small>{{ $bot_name_note }}</small>@endif</div>
        <div class="rounded-xl border border-gray-500/20 p-3"><small>Destino</small><strong class="block">{{ $destination_name }}</strong>@if($destination_name_note)<small>{{ $destination_name_note }}</small>@endif</div>
    </div>
    @if($media ?? null)<img src="data:{{ $media['mime'] }};base64,{{ $media['content'] }}" alt="Imagen guardada de la prueba" style="max-width:240px;max-height:200px;object-fit:contain;border-radius:.5rem" />@endif
    @if($event_code ?? null)<p>Evento: {{ $event_code }} · Revisión de plantilla: {{ $presentation_revision }}</p>@endif
    <div class="rounded-xl border border-gray-500/20 p-3"><strong>Mensaje guardado</strong><div class="mt-2 whitespace-pre-wrap break-words">{{ $message ?? 'No se guardó el texto original de esta prueba.' }}</div></div>
    <div class="rounded-xl border border-gray-500/20 p-3">
        <x-filament::badge :color="$status==='SENT'?'success':($status==='REJECTED'?'danger':'warning')">{{ $states[$status] ?? 'Sin confirmar' }}</x-filament::badge>
        <p class="mt-2">{{ $result_message }}</p><small>Solicitado por {{ $actor_name }}</small>
        @if($completed_at)<small class="block">Resultado registrado: {{ $completed_at->timezone('America/La_Paz')->format('d/m/Y H:i:s') }}</small>@endif
        @if($message_id)<small class="block">Confirmación Telegram: {{ $message_id }}</small>@endif
        @if($code)<small class="block">Código seguro: {{ $code }}@if($http_status) · HTTP {{ $http_status }} @endif</small>@endif
    </div>
    <div class="rounded-xl border border-gray-500/20 p-3">
        <strong>Intentos registrados: {{ $attempts_recorded }}</strong>
        @forelse($starts as $start)<p class="mt-2">Intento manual · {{ $start->created_at->timezone('America/La_Paz')->format('d/m/Y H:i:s') }} · {{ $states[$status] ?? 'Sin confirmar' }}</p>@empty<p class="mt-2">{{ $legacy_attempt?'No se conservó el inicio del intento en este registro antiguo.':'No hay inicio de intento registrado.' }}</p>@endforelse
        <p class="mt-2">Sin reintentos automáticos. Consultar este detalle no envía mensajes ni cambia los avisos automáticos.</p>
        @if(in_array($status,['PENDING','UNCERTAIN'],true))<p class="mt-2 text-amber-500">Revisa Telegram antes de decidir enviar otra prueba.</p>@endif
    </div>
</div>
