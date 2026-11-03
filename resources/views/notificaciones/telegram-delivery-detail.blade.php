<div class="space-y-4">
 <div class="flex flex-wrap items-center justify-between gap-2"><x-filament::badge color="primary" icon="heroicon-o-bell">Aviso automático</x-filament::badge><span class="text-sm">{{ $dispatch->created_at->timezone('America/La_Paz')->format('d/m/Y H:i:s') }}</span></div>
 <div class="rounded-xl border border-gray-500/20 p-3 text-sm"><strong>{{ $bot_name }}</strong>@if($bot_name_note)<small class="block">{{ $bot_name_note }}</small>@endif<p>{{ $deliveries->count() }} destinos · {{ $total_attempts }} intentos registrados</p></div>
 <p class="text-sm">{{ $dispatch->module }} · {{ $dispatch->event_code }} · {{ $dispatch->source_reference }}</p>
 @if($dispatch->media)
 <div class="rounded-xl border border-gray-500/20 p-3">
 <img src="data:{{ $dispatch->media['mime'] }};base64,{{ $dispatch->media['content'] }}" alt="Imagen identificativa guardada del aviso" class="mx-auto max-h-48 max-w-full rounded-lg" />
 <p class="mt-2 text-xs text-gray-500">Imagen y texto del mismo aviso · Diseño {{ $dispatch->media['version'] }}</p>
 <div class="mt-2 text-sm whitespace-pre-wrap break-words">{{ $dispatch->media['caption'] }}</div>
 </div>
 @endif
 <strong class="block text-sm">Detalle completo guardado</strong>
 <div class="rounded-xl border border-gray-500/20 p-3 text-sm whitespace-pre-wrap break-words">{{ $dispatch->message ?? 'Aviso omitido: no se guardó contenido ni se creó una entrega para enviar después.' }}</div>
 @foreach($deliveries as $delivery)
 <div class="rounded-xl border border-gray-500/20 p-3 text-sm"><strong>{{ $delivery->destination_name }}</strong> · {{ $states[$delivery->status] }}<p>Intentos: {{ $delivery->attempt_count }} · Automáticos: {{ $delivery->automatic_attempts }}/3 @if($delivery->message_id) · Confirmación Telegram: {{ $delivery->message_id }} @endif</p>
 @if($delivery->status==='INCIERTA')<p class="text-amber-500">No se puede confirmar la entrega. No se repetirá automáticamente; revisa el chat antes de solicitar otro intento.</p>@endif
 @if($delivery->last_code)<small>Código seguro: {{ $delivery->last_code }}</small>@endif
 @if(in_array($delivery->status,['ERROR_DEFINITIVO','INCIERTA'],true))<div class="mt-2">{{ ($this->retryAction)(['id'=>$delivery->id]) }}</div>@endif</div>
 @endforeach
 <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Entrega · intento</th><th class="p-2">Destino</th><th class="p-2">Tipo</th><th class="p-2">Resultado</th><th class="p-2">Fecha · Bolivia</th></tr></thead><tbody>
 @forelse($attempts as $attempt)<tr class="border-t border-gray-500/20"><td class="p-2">#{{ $attempt->delivery_id }} · {{ $attempt->number }}</td><td class="p-2">{{ $attempt_destinations[$attempt->delivery_id] ?? 'Destino no disponible' }}</td><td class="p-2">{{ $attempt->kind==='AUTO'?'Automático':'Manual' }}</td><td class="p-2">{{ $states[$attempt->status] }} · {{ $attempt->code }}</td><td class="p-2">{{ $attempt->started_at->timezone('America/La_Paz')->format('d/m/Y H:i:s') }}</td></tr>@empty<tr><td colspan="5" class="p-2">Sin intentos de envío.</td></tr>@endforelse
 </tbody></table></div>{{ $attempts->links() }}
 @foreach($manual as $change)<p class="text-xs">Reintento solicitado por {{ $change->actor_name }} · {{ $change->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }} · {{ $change->snapshot['reason'] }}</p>@endforeach
</div>
