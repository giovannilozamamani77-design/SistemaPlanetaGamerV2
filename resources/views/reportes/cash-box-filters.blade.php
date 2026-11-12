    <x-filament::section icon="heroicon-o-funnel">
        <x-slot name="heading">{{ $filterHeading ?? 'Consulta por cajera y caja' }}</x-slot>
        <form wire:submit="applyCashFilters" class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <div><label for="cash-report-from">Desde · Bolivia</label><x-filament::input.wrapper><x-filament::input id="cash-report-from" type="date" wire:model="fromDate" /></x-filament::input.wrapper>@error('from')<p class="text-danger-600">{{ $message }}</p>@enderror</div>
            <div><label for="cash-report-until">Hasta · Bolivia</label><x-filament::input.wrapper><x-filament::input id="cash-report-until" type="date" wire:model="untilDate" /></x-filament::input.wrapper>@error('until')<p class="text-danger-600">{{ $message }}</p>@enderror</div>
            <div><label for="cash-report-user">Cajera / responsable</label><x-filament::input.wrapper><x-filament::input.select id="cash-report-user" wire:model="cashierId"><option value="">Todo el personal</option>@foreach($cashiers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@error('user_id')<p class="text-danger-600">{{ $message }}</p>@enderror</div>
            <div><label for="cash-report-box">Caja</label><x-filament::input.wrapper><x-filament::input.select id="cash-report-box" wire:model="cashBoxId"><option value="">Todas las cajas</option>@foreach($boxes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@error('box_id')<p class="text-danger-600">{{ $message }}</p>@enderror</div>
            <div class="flex items-end"><x-filament::button type="submit" icon="heroicon-o-funnel" wire:loading.attr="disabled">Consultar / actualizar</x-filament::button></div>
        </form>
        <p class="mt-3 text-sm text-gray-500">Periodo aplicado: {{ $cashReport['filters']['from'] }} — {{ $cashReport['filters']['until'] }} · Consultado {{ $cashReport['checked_at'] }}. Solo las consultas confirmadas cambian los resultados.</p>
    </x-filament::section>
