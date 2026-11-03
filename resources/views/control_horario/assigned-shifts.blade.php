<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-arrow-path">
        <x-slot name="heading">Una asignación, control diario</x-slot>
        <p class="text-sm text-gray-600 dark:text-gray-400">Asignas cajera y turno → se aplica de lunes a sábado → el PIN marca su llegada → al abrir se evalúa el retraso. Los registros de cada día se conservan en el historial.</p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">La fecha de pago es independiente. Activar un turno habitual no activa el interruptor general del control de horario.</p>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>
