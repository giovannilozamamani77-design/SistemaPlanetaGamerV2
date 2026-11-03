<x-filament-panels::page>
    @unless($transportEnabled)
    <x-filament::section heading="Telegram pausado por mantenimiento" icon="heroicon-o-exclamation-triangle" icon-color="warning" compact>
        <p class="text-sm">El transporte está deshabilitado en el servidor. Puedes pausar bots; la activación requiere habilitar el transporte en el despliegue.</p>
    </x-filament::section>
    @endunless
    <x-filament::section icon="heroicon-o-pause-circle" icon-color="warning" :heading="$bots->contains('is_enabled', true)?'Control de envíos por bot':'Envíos de Telegram pausados'">
        <p class="text-sm">Cada bot comienza pausado. Configura y comprueba bot y destinos, prepara los eventos y luego activa únicamente el bot que necesites. La bandeja interna sigue funcionando.</p>
    </x-filament::section>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
    @foreach($modules as $module=>$label)
        @php($bot=$bots->get($module))
        <x-filament::section icon="heroicon-o-paper-airplane" icon-color="info" :heading="$label" compact>
            <div class="space-y-3 text-sm">
                <strong>{{ $bot?->name ?? 'Bot sin configurar' }}</strong>
                <p>{{ $bot ? 'Token guardado y protegido' : 'Agrega un bot creado en BotFather' }}</p>
                <p>{{ $bot?->destinations_count ?? 0 }} destinos · <x-filament::badge :color="$bot?->is_enabled?'success':'warning'">{{ $bot?->is_enabled?'Activo':'Pausado' }}</x-filament::badge></p>
                {{ ($this->botAction)(['module'=>$module]) }}
                <x-filament::button color="gray" icon="heroicon-o-bell" tag="a" :href="\App\Filament\Pages\Notificaciones\TelegramEvents::getUrl(['module'=>$module])">Eventos y mensajes</x-filament::button>
                @if($bot)
                {{ ($this->stateAction)(['id'=>$bot->id])->label($bot->is_enabled?'Pausar o cambiar destinos':'Activar')->color($bot->is_enabled?'warning':'success') }}
                <p>{{ $connectionStatuses['BOT:'.$bot->id] }}</p>
                {{ ($this->checkAction)(['kind'=>'BOT','id'=>$bot->id]) }}
                {{ ($this->testMessageAction)(['id'=>$bot->id]) }}
                @if(isset($testStatuses[$bot->id]))<p>{{ $testStatuses[$bot->id] }}</p>@endif
                @endif
            </div>
        </x-filament::section>
    @endforeach
    </div>
    <x-filament::section heading="Cómo comprobar un destino" icon="heroicon-o-question-mark-circle" icon-color="info" collapsible collapsed compact>
        <ol class="list-decimal space-y-1 pl-5 text-sm">
            <li>Chat personal: abre tu bot en Telegram y pulsa Iniciar o envía /start desde tu cuenta.</li>
            <li>Grupo privado: agrega el bot al grupo y permite que escriba mensajes.</li>
            <li>En la tarjeta de arriba, pulsa Comprobar conexión para revisar el bot.</li>
            <li>Abajo, pulsa Comprobar en la fila del destinatario. El Chat ID debe ser el de esa persona o grupo; no es un número de teléfono.</li>
            <li>Al obtener Acceso comprobado en ambos, puedes usar Mensaje de prueba. Los avisos automáticos siguen pausados hasta que los actives aparte.</li>
        </ol>
        <p class="mt-2 text-sm">Si el error menciona certificados HTTPS, el servidor necesita su paquete de certificados de confianza. Cambiar el Chat ID no corrige ese error.</p>
    </x-filament::section>
    <x-filament::section heading="Destinos privados" icon="heroicon-o-user-group" icon-color="success">
        <x-slot name="afterHeader">{{ $this->destinationAction }}</x-slot>
        <p class="mb-3 text-sm">Primero configura el bot. Después registra el chat personal o grupo privado al que se enviarán sus avisos.</p>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b border-gray-500/20"><th class="p-2">Destino</th><th class="p-2">Módulo</th><th class="p-2">Responsable</th><th class="p-2">Estado</th><th></th></tr></thead><tbody>
        @forelse($destinations as $destination)
            <tr class="border-b border-gray-500/20"><td class="p-2"><strong>{{ $destination->name }}</strong><small class="block">{{ $destination->kind==='GROUP'?'Grupo privado declarado':'Chat personal' }} · {{ $destination->chat_id }}</small></td><td class="p-2">{{ $modules[$destination->bot->module] }}</td><td class="p-2">{{ $destination->responsible->name }}</td><td class="p-2">{{ $destination->is_enabled?'Habilitado':'Pausado' }} · {{ $connectionStatuses['DESTINATION:'.$destination->id] }}</td><td class="p-2">{{ ($this->destinationAction)(['id'=>$destination->id])->label('Editar')->icon('heroicon-o-pencil-square') }} {{ ($this->checkAction)(['kind'=>'DESTINATION','id'=>$destination->id])->label('Comprobar') }}</td></tr>
        @empty<tr><td colspan="5" class="p-3">Sin destinos. No se enviará ningún mensaje.</td></tr>@endforelse
        </tbody></table></div>
        <div class="mt-3">{{ $destinations->links() }}</div>
    </x-filament::section>
    <x-filament::section heading="Historial de configuración" icon="heroicon-o-clock" collapsible collapsed>
        @forelse($changes as $change)<p class="border-b border-gray-500/20 py-2 text-sm">{{ $change->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }} · {{ $change->actor_name }} · {{ ['BOT'=>'Bot','DESTINATION'=>'Destino','BOT_CHECK'=>'Comprobación de bot','DESTINATION_CHECK'=>'Comprobación de destino','EVENT_SETTING'=>'Evento y destinos','DELIVERY_RETRY'=>'Reintento de entrega','BOT_STATE'=>'Estado de envíos','BOT_TEST_REQUEST'=>'Solicitud de prueba','BOT_TEST_RESULT'=>'Resultado de prueba','BOT_TEST_ATTEMPT'=>'Inicio de intento de prueba'][$change->kind] ?? $change->kind }} #{{ $change->source_id }}@if($change->kind==='EVENT_SETTING') · {{ $change->snapshot['after']['event_code'] }}
@endif · {{ $change->kind==='DELIVERY_RETRY'?'Intentos previos':'Versión' }} {{ $change->snapshot['after']['revision'] ?? $change->snapshot['after']['attempt_count'] ?? 0 }}@if(in_array($change->kind,['BOT_TEST_REQUEST','BOT_TEST_RESULT','BOT_TEST_ATTEMPT'],true)) · {{ \App\Domain\Notificaciones\Actions\SendTelegramTestMessageAction::message($change->snapshot) }}
@elseif(isset($change->snapshot['status'])) · {{ \App\Domain\Notificaciones\Actions\CheckTelegramConnectionAction::message($change->snapshot) }}
@endif
@if($change->kind==='BOT_STATE') · {{ $change->snapshot['after']['is_enabled']?'Activado':'Pausado' }}
@endif
@if($change->snapshot['token_replaced']??false) · Credencial reemplazada
@endif</p>@empty<p class="text-sm">Todavía no hay cambios.</p>@endforelse
        <div class="mt-3">{{ $changes->links() }}</div>
    </x-filament::section>
</x-filament-panels::page>
