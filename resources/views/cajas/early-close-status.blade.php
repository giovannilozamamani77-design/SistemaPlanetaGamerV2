@if (($departureMinutes ?? 0) > 0)
    <x-filament::section heading="Tiempo por salida anticipada">
        <p class="font-semibold">Si confirmas el cierre ahora: {{ \App\Support\TimeDuration::format($departureMinutes) }}.</p>
        <p class="text-sm">Solo se registrará al confirmar el cierre autorizado. Solicitar permiso o volver a trabajar no genera este tiempo.</p>
    </x-filament::section>
@endif
@if ($request)
    <x-filament::section>
        <p class="font-semibold">Solicitud {{ mb_strtolower($request->status) }}</p>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $request->reason }}</p>
        @if ($request->decision_reason)
            <p class="mt-2 text-sm">Respuesta de Administración: {{ $request->decision_reason }}</p>
        @endif
        <p class="mt-2 text-sm">Puedes seguir trabajando y volver a pulsar Cerrar caja cuando corresponda.</p>
    </x-filament::section>
@endif
