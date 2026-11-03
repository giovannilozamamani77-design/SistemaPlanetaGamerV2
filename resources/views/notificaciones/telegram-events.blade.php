<x-filament-panels::page>
    <x-filament::section :heading="$bot?->is_enabled?'Bot activo · eventos y destinos':'Envíos pausados'" icon="heroicon-o-pause-circle" icon-color="warning" compact>
        <p class="text-sm">Elige qué avisos preparar y sus destinos. La vista previa no envía mensajes. En un bot activo, los cambios se aplican a nuevos avisos y destinos habilitados.</p>
    </x-filament::section>
    <div class="flex flex-wrap gap-2">
        @foreach($modules as $key=>$label)
        <x-filament::button wire:click="selectModule('{{ $key }}')" :color="$module===$key?'primary':'gray'" :outlined="$module!==$key">{{ $label }}</x-filament::button>
        @endforeach
    </div>
    <x-filament::section :heading="$modules[$module]" icon="heroicon-o-bell" icon-color="info" compact>
        <p class="mb-3 text-sm">{{ $bot?->name ?? 'Configura primero el bot y sus destinos en Bots y destinos.' }} · Cada operación se agrupará para evitar avisos repetidos.</p>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <thead><tr class="border-b border-gray-500/20"><th class="p-2">Evento</th><th class="p-2">Destinos elegidos</th><th class="p-2">Preparación</th><th class="p-2">Opciones</th></tr></thead>
            <tbody>
            @foreach($events as $code=>$event)
            <tr class="border-b border-gray-500/20">
                <td class="p-2"><strong>{{ $event['title'] }}</strong><small class="block text-gray-500">{{ $event['description'] }}</small><x-filament::badge :color="$event['connected']?'success':'warning'">{{ $event['connected']?'Conectado':'Pendiente de integrar' }}</x-filament::badge></td>
                <td class="p-2">{{ implode(', ',$event['destinations']) ?: 'Sin destinos' }}</td>
                <td class="p-2"><x-filament::badge :color="$event['review_required']?'warning':($event['prepared']?'info':'gray')">{{ $event['review_required']?'Revisar asignación':($event['prepared']?'Preparado':'Sin preparar') }}</x-filament::badge><small class="block">{{ $bot?->is_enabled?'Según destinos habilitados':'Envíos pausados' }}</small></td>
                <td class="p-2"><div class="flex flex-wrap gap-2">{{ ($this->previewAction)(['module'=>$module,'event_code'=>$code]) }} {{ ($this->templateAction)(['module'=>$module,'event_code'=>$code]) }}
                @if($bot) {{ ($this->eventAction)(['module'=>$module,'event_code'=>$code]) }} {{ ($this->templateTestAction)(['module'=>$module,'event_code'=>$code]) }} @endif
                </div></td>
            </tr>
            @endforeach
            </tbody>
        </table></div>
        <p class="mt-3 text-xs text-gray-500">Las plantillas y pruebas no activan eventos pendientes de integrar. Guarda los cambios y revisa la vista previa antes de enviar una prueba. Una prueba no representa una operación real.</p>
    </x-filament::section>
</x-filament-panels::page>
