<div class="space-y-3">
    <x-filament::badge color="warning" icon="heroicon-o-pause-circle">Datos ficticios · No se envía</x-filament::badge>
    <p class="text-sm">{{ $preview['description'] }}</p>
    @if($preview['media'] ?? null)
    <img src="data:{{ $preview['media']['mime'] }};base64,{{ $preview['media']['content'] }}" alt="Imagen identificativa de ejemplo del módulo" class="mx-auto max-h-48 max-w-full rounded-lg" />
    <p class="text-xs text-gray-500">{{ $preview['image_label'] ?? 'Imagen identificativa de ejemplo del módulo.' }}</p>
    @endif
    <div class="rounded-xl border border-gray-500/20 bg-gray-500/5 p-4 text-sm whitespace-pre-wrap break-words">{{ $preview['message'] }}</div>
    <p class="text-xs text-gray-500">Plantilla {{ $preview['template_version'] }} · {{ $preview['priority'] }} · El aviso real usa el registro confirmado. @if($preview['media'] ?? null) Imagen y texto se envían juntos; el detalle completo se conserva en el historial. @endif</p>
</div>
