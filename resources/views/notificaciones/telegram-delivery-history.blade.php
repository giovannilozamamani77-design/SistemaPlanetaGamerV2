<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::button wire:click="$set('type', 'AUTOMATIC')" icon="heroicon-o-bell" :color="$type==='AUTOMATIC'?'primary':'gray'">Avisos automáticos</x-filament::button>
        <x-filament::button wire:click="$set('type', 'TEST')" icon="heroicon-o-beaker" :color="$type==='TEST'?'primary':'gray'">Mensajes de prueba</x-filament::button>
    </div>
    <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs">Desde · Bolivia<input type="date" wire:model.live="dateFrom" class="block w-36 rounded-lg border border-gray-500/30 bg-transparent p-2 text-sm"></label>
        <label class="text-xs">Hasta · Bolivia<input type="date" wire:model.live="dateTo" class="block w-36 rounded-lg border border-gray-500/30 bg-transparent p-2 text-sm"></label>
        <label class="text-xs">Bot<select wire:model.live="module" class="block w-44 rounded-lg border border-gray-500/30 bg-transparent p-2 text-sm"><option value="">Todos los bots</option>@foreach($filterOptions['bots'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        <label class="text-xs">Destino<select wire:model.live="destinationId" title="Filtra por destino; se muestra su nombre actual" class="block w-44 rounded-lg border border-gray-500/30 bg-transparent p-2 text-sm"><option value="">Todos los destinos</option>@foreach($filterOptions['destinations'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        <label class="text-xs">Resultado<select wire:model.live="status" class="block w-44 rounded-lg border border-gray-500/30 bg-transparent p-2 text-sm"><option value="">Todos</option>@foreach($states as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        <x-filament::button wire:click="clearFilters" color="gray" icon="heroicon-o-arrow-path" tooltip="Limpiar filtros" aria-label="Limpiar filtros">Limpiar</x-filament::button>
    </div>
    @error('dateFrom')<p class="text-sm text-red-500">{{ $message }}</p>@enderror
    @error('dateTo')<p class="text-sm text-red-500">{{ $message }}</p>@enderror
    @error('destinationId')<p class="text-sm text-red-500">{{ $message }}</p>@enderror
    <x-filament::section :heading="$type==='TEST'?'Mensajes de prueba':'Avisos automáticos'" icon="heroicon-o-clock" compact>
        <p class="mb-3 text-sm">{{ $type==='TEST'?'Una fila por prueba solicitada. Consultar el historial no envía mensajes ni activa avisos.':'Una fila por aviso; el detalle conserva sus destinos e intentos. Los avisos omitidos no se recuperan al activar.' }}</p>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <thead><tr class="border-b border-gray-500/20"><th class="p-2">Fecha · Bolivia</th><th class="p-2">Bot</th><th class="p-2">Destino</th><th class="p-2">Resultado</th><th class="p-2">Opciones</th></tr></thead>
            <tbody>
            @if($type==='TEST')
                @forelse($history as $test)
                <tr class="border-b border-gray-500/20" wire:key="test-history-{{ $test['id'] }}">
                    <td class="p-2 whitespace-nowrap">{{ $test['created_at']->timezone('America/La_Paz')->format('d/m/Y H:i') }}<small class="block">Prueba #{{ $test['id'] }} · {{ $test['actor_name'] }}</small></td>
                    <td class="p-2"><strong>{{ $test['bot_name'] }}</strong><small class="block">{{ $modules[$test['module']] ?? 'Módulo no disponible' }}@unless($test['bot_name_historical']) · {{ $test['bot_name_note'] }} @endunless</small></td>
                    <td class="p-2">{{ $test['destination_name'] }}@unless($test['destination_name_historical'])<small class="block">{{ $test['destination_name_note'] }}</small>@endunless</td>
                    <td class="p-2"><x-filament::badge :color="$test['status']==='SENT'?'success':($test['status']==='REJECTED'?'danger':'warning')">{{ $states[$test['status']] ?? 'Sin confirmar' }}</x-filament::badge><small class="block">{{ $test['result_message'] }}</small></td>
                    <td class="p-2">{{ ($this->testDetailAction)(['id'=>$test['id']]) }}</td>
                </tr>
                @empty<tr><td colspan="5" class="p-4">{{ $filterError?'Corrige los filtros para consultar el historial.':'Todavía no hay mensajes de prueba registrados.' }}</td></tr>@endforelse
            @else
                @forelse($history as $notice)
                <tr class="border-b border-gray-500/20" wire:key="automatic-history-{{ $notice->id }}">
                    <td class="p-2 whitespace-nowrap">{{ $notice->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }}</td>
                    <td class="p-2"><strong>{{ $notice->bot_name }}</strong><small class="block">{{ $modules[$notice->module] ?? $notice->module }}@unless($notice->bot_name_historical) · {{ $notice->bot_name_current?'Nombre actual':'Identificado por módulo' }} @endunless</small><small class="block">{{ $notice->event_code }} · {{ $notice->source_reference }}</small></td>
                    <td class="p-2">@forelse($notice->deliveries->take(2) as $delivery)<div>{{ $delivery->destination_name }}</div>@empty Sin destino autorizado @endforelse @if($notice->deliveries->count()>2)<small>Más destinos en el detalle</small>@endif</td>
                    <td class="p-2">@if($notice->status==='OMITIDA')<x-filament::badge color="gray">Omitida</x-filament::badge><small class="block">{{ $notice->reason==='PAUSED'?'Envíos pausados':'Sin destinos autorizados' }}</small>@else @foreach($notice->deliveries->take(2) as $delivery)<div>{{ $states[$delivery->status] }} · {{ $delivery->attempt_count }} intentos</div>@endforeach @endif</td>
                    <td class="p-2">{{ ($this->detailAction)(['id'=>$notice->id]) }}</td>
                </tr>
                @empty<tr><td colspan="5" class="p-4">{{ $filterError?'Corrige los filtros para consultar el historial.':'Todavía no hay avisos registrados. Preparar eventos o ver ejemplos no genera envíos.' }}</td></tr>@endforelse
            @endif
            </tbody>
        </table></div>
        <div class="mt-3">{{ $history->links() }}</div>
    </x-filament::section>
</x-filament-panels::page>
