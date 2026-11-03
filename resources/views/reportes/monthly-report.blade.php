<x-filament-panels::page>
    <form wire:submit="prepare" class="flex flex-wrap items-end gap-3">
        <div>
            <label for="monthly-report-month" class="mb-1 block text-sm font-medium">Mes terminado</label>
            <x-filament::input.wrapper><x-filament::input id="monthly-report-month" type="month" wire:model="month" max="{{ now('America/La_Paz')->startOfMonth()->subMonth()->format('Y-m') }}" /></x-filament::input.wrapper>
            @error('month')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
        </div>
        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled">Revisar mes</x-filament::button>
        <p class="text-sm text-gray-500">Hora de Bolivia. El nuevo mes continúa operando normalmente.</p>
    </form>
    @error('preview')<div class="rounded-xl bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-950">{{ $message }}</div>@enderror
    @php($display = $saved['report'] ?? ($preview['report'] ?? null))
    @if ($display)
        @php($sourceLabels = array_column($display['rows'], 'label', 'source'))
        <x-filament::section>
            <x-slot name="heading">{{ $saved ? 'Cierre original confirmado · '.$saved['close']->month : 'Vista previa · '.$preview['month'] }}</x-slot>
            @if ($saved)
                <p class="mb-3 text-sm text-gray-500">Confirmado por {{ $saved['close']->actor_name }} · {{ $saved['close']->confirmed_at->setTimezone('America/La_Paz')->format('d/m/Y H:i:s') }} · Referencia #{{ $saved['close']->id }}. El original no se recalcula ni se sobrescribe.</p>
                <div class="mb-3 flex flex-wrap gap-2">@foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $format => $label)<x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-arrow-down-tray" :href="route('reportes.cierre-export', ['close' => $saved['close']->id, 'format' => $format])">{{ $label }} del original</x-filament::button>@endforeach</div>
                <div class="mb-3 flex flex-wrap gap-2">@foreach (['xlsx' => 'Excel', 'csv' => 'CSV'] as $format => $label)<x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-clipboard-document-list" :href="route('reportes.cierre-detalle-export', ['close' => $saved['close']->id, 'format' => $format])">Detalle {{ $label }} del original</x-filament::button>@endforeach</div>
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-primary-50 p-3 dark:bg-primary-950"><p class="text-sm">Ventas registradas</p><p class="text-2xl font-bold">Bs {{ \App\Support\Bolivianos::formatCompact($display['sales_cents']) }}</p></div>
                <div class="rounded-xl bg-success-50 p-3 dark:bg-success-950"><p class="text-sm">Cobros registrados</p><p class="text-2xl font-bold">Bs {{ \App\Support\Bolivianos::formatCompact($display['collections_cents']) }}</p></div>
            </div>
            <p class="my-3 text-sm text-gray-500">{{ $display['period']['label'] }} · Anterior: {{ $display['comparison']['available'] ? $display['period']['previous_label'] : 'Sin comparación disponible' }}. Ventas ya forma parte de Cobros; no se suman. No representa ganancias.</p>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b border-gray-200 dark:border-gray-700"><th class="py-2">Indicador</th><th>Anterior</th><th>Diferencia</th></tr></thead><tbody>
            @foreach (['sales' => 'Ventas', 'collections' => 'Cobros'] as $metric => $label)
                <tr class="border-b border-gray-200 dark:border-gray-700"><td class="py-2">{{ $label }}</td><td>{{ $display['comparison'][$metric] === null ? 'No disponible' : 'Bs '.\App\Support\Bolivianos::formatCompact($display['comparison'][$metric]['previous_cents']) }}</td><td>{{ $display['comparison'][$metric]['label'] ?? 'Sin comparación disponible' }}</td></tr>
            @endforeach
            </tbody></table></div>
            <p class="mt-3 text-sm text-gray-500">No incluye saldo final bancario o efectivo histórico: el saldo actual no acredita el saldo al fin de mes.</p>
            <details class="mt-3"><summary class="cursor-pointer text-sm font-medium">Desglose por origen</summary><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="py-2">Origen</th><th>Registros</th><th>Ventas</th><th>Cobros</th></tr></thead><tbody>
                @foreach ($display['rows'] as $row)<tr class="border-b border-gray-200 dark:border-gray-700"><td class="py-2">{{ $row['label'] }}</td><td>{{ $row['operations'] }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($row['sales_cents']) }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($row['collections_cents']) }}</td></tr>@endforeach
            </tbody></table></div></details>
        </x-filament::section>
    @endif
    @if ($preview)
        <x-filament::section heading="Confirmar cierre mensual">
            <p class="mb-3 text-sm">{{ $preview['source_count'] }} contribuciones al resumen. El cierre conserva los datos originales y no modifica operaciones.</p>
            @if ($preview['pending_count'] > 0)
                <div class="mb-3 rounded-xl bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-950">
                    <strong>{{ $preview['pending_count'] }} pendientes</strong> originados hasta el fin del mes, observados al confirmar. Incluye pendientes anteriores. No se resolverán ni se reconocerán como pérdidas.
                </div>
                <div class="overflow-x-auto"><table class="mb-2 w-full text-left text-sm"><thead><tr><th>Tipo / referencia</th><th>Responsable</th><th>Estado</th><th>Importe</th></tr></thead><tbody>
                @foreach ($previewPending as $row)<tr class="border-b border-gray-200 dark:border-gray-700"><td class="py-2">{{ $row['source'] }} · {{ $row['reference'] }}</td><td>{{ $row['actor_name'] }}</td><td>{{ $row['status'] }}</td><td>{{ $row['amount_cents'] === null ? 'Por revisar' : 'Bs '.\App\Support\Bolivianos::formatCompact($row['amount_cents']) }}</td></tr>@endforeach
                </tbody></table></div>{{ $previewPending->links() }}
                <label class="my-3 flex items-center gap-2 text-sm"><x-filament::input.checkbox wire:model="acceptPending" /> Entiendo que existen pendientes y confirmo el cierre conservándolos por separado.</label>
            @endif
            @error('acceptPending')<p class="mb-2 text-sm text-danger-600">{{ $message }}</p>@enderror
            <form wire:submit="confirm" class="flex flex-wrap items-end gap-3">
                <div><label for="monthly-pin" class="mb-1 block text-sm font-medium">Mi PIN de Administración</label><x-filament::input.wrapper><x-filament::input id="monthly-pin" wire:model="pin" type="password" inputmode="numeric" maxlength="4" autocomplete="off" /></x-filament::input.wrapper>@error('pin')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <x-filament::button type="submit" icon="heroicon-o-lock-closed" wire:loading.attr="disabled" wire:target="confirm">Confirmar cierre mensual</x-filament::button>
            </form>
        </x-filament::section>
    @endif
    @if ($saved)
        <x-filament::section heading="Evidencia conservada">
            <div class="mb-3 flex flex-wrap gap-2"><x-filament::button color="gray" size="sm" wire:click="changeEvidence('records')">Contribuciones ({{ $saved['close']->source_count }})</x-filament::button><x-filament::button color="warning" size="sm" wire:click="changeEvidence('pending')">Pendientes al confirmar ({{ $saved['close']->pending_count }})</x-filament::button></div>
            @if ($saved['close']->pending_count > 0)<p class="mb-3 text-sm text-warning-600">Se confirmó con advertencia de pendientes. Esta lista conserva su estado original, aunque después se resuelvan.</p>@endif
            <div class="overflow-x-auto"><table class="mb-3 w-full text-left text-sm"><thead><tr><th>Origen / referencia</th><th>Fecha · Bolivia</th><th>Responsable</th><th>{{ $evidenceKind === 'pending' ? 'Pendiente' : 'Cobro aportado' }}</th></tr></thead><tbody>
                @forelse ($saved['evidence'] as $row)<tr class="border-b border-gray-200 dark:border-gray-700"><td class="py-2">{{ $sourceLabels[$row['source']] ?? $row['source'] }} · {{ $row['reference'] ?? '#'.$row['record_id'] }} @if(isset($row['status']))<span class="text-gray-500">{{ $row['status'] }}</span>@endif</td><td>{{ \Carbon\CarbonImmutable::parse($row['recorded_at'], 'UTC')->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</td><td>{{ $row['actor_name'] }}</td><td>{{ ($row['collections_cents'] ?? $row['amount_cents'] ?? null) === null ? 'Por revisar' : 'Bs '.\App\Support\Bolivianos::formatCompact($row['collections_cents'] ?? $row['amount_cents']) }}</td></tr>@empty<tr><td colspan="4" class="py-4 text-gray-500">Sin registros en esta sección.</td></tr>@endforelse
            </tbody></table></div>{{ $saved['evidence']->links() }}
        </x-filament::section>
    @endif
    @if ($saved && $adjusted)
        <x-filament::section heading="Ajustes posteriores">
            <p class="mb-3 text-sm text-gray-500">El original se conserva. Las diferencias se calculan desde los cambios registrados en origen; este ajuste no mueve dinero. La comparación mantiene el mes anterior observado en el cierre original.</p>
            <div class="mb-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Concepto</th><th>Original</th><th>Ajustes acumulados</th><th>Resultado registrado</th></tr></thead><tbody>
                @foreach (['sales' => 'Ventas', 'collections' => 'Cobros'] as $metric => $label)<tr class="border-b border-gray-200 dark:border-gray-700"><td class="py-2">{{ $label }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($adjusted['original_'.$metric.'_cents']) }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($adjusted[$metric.'_adjustment_cents']) }}</td><td class="font-bold">Bs {{ \App\Support\Bolivianos::formatCompact($adjusted['snapshot']['report'][$metric.'_cents']) }}</td></tr>@endforeach
            </tbody></table></div>
            <p class="mb-3 text-xs text-gray-500">{{ $adjusted['latest'] ? 'Última versión registrada: '.$adjusted['latest']->sequence : 'Aún no hay ajustes registrados.' }} · Los nuevos cambios de origen solo se incorporan al revisar y confirmar otro ajuste.</p>
            @if ($adjusted['latest'])<div class="mb-3 flex flex-wrap gap-2">@foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $format => $label)<x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-arrow-down-tray" :href="route('reportes.cierre-ajuste-export', ['close' => $saved['close']->id, 'adjustment' => $adjusted['latest']->id, 'format' => $format])">{{ $label }} ajustado v{{ $adjusted['latest']->sequence }}</x-filament::button>@endforeach</div><div class="mb-3 flex flex-wrap gap-2">@foreach (['xlsx' => 'Excel', 'csv' => 'CSV'] as $format => $label)<x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-clipboard-document-list" :href="route('reportes.cierre-ajuste-detalle-export', ['close' => $saved['close']->id, 'adjustment' => $adjusted['latest']->id, 'format' => $format])">Detalle {{ $label }} ajustado v{{ $adjusted['latest']->sequence }}</x-filament::button>@endforeach</div>@endif
            <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="prepareAdjustment" wire:loading.attr="disabled">Revisar cambios</x-filament::button>
            @error('adjustment')<p class="my-2 text-sm text-danger-600">{{ $message }}</p>@enderror
            @if ($adjustmentPreview)
                <div class="mt-4 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <p class="mb-3 font-medium">{{ count($adjustmentPreview['changes']) }} cambios detectados · Δ Ventas Bs {{ \App\Support\Bolivianos::formatCompact($adjustmentPreview['sales_delta_cents']) }} · Δ Cobros Bs {{ \App\Support\Bolivianos::formatCompact($adjustmentPreview['collections_delta_cents']) }}</p>
                    @include('reportes.monthly-adjustment-changes', ['changeRows' => $adjustmentPreviewRows])
                    @if ($adjustmentPreview['changes'])
                        <form wire:submit="confirmAdjustment" class="mt-3 space-y-3">
                            <div><label for="monthly-adjustment-reason" class="mb-1 block text-sm font-medium">Motivo del ajuste</label><x-filament::input.wrapper><x-filament::input id="monthly-adjustment-reason" wire:model="adjustmentReason" maxlength="1000" /></x-filament::input.wrapper>@error('reason')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                            @if ($adjustmentPreview['pending_count'] > 0)<label class="flex items-center gap-2 rounded-xl bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-950"><x-filament::input.checkbox wire:model="acceptAdjustmentPending" /> Acepto que el resultado sigue con {{ $adjustmentPreview['pending_count'] }} pendientes, separados y sin reconocerlos como pérdidas.</label>@endif
                            @error('adjustmentPending')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                            <div class="flex flex-wrap items-end gap-3"><div><label for="monthly-adjustment-pin" class="mb-1 block text-sm font-medium">Mi PIN de Administración</label><x-filament::input.wrapper><x-filament::input id="monthly-adjustment-pin" type="password" wire:model="pin" maxlength="4" inputmode="numeric" autocomplete="off" /></x-filament::input.wrapper>@error('pin')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div><x-filament::button type="submit" icon="heroicon-o-check-circle" wire:loading.attr="disabled" wire:target="confirmAdjustment">Registrar ajuste</x-filament::button></div>
                        </form>
                    @endif
                </div>
            @endif
            <div class="mt-4 overflow-x-auto"><table class="mb-3 w-full text-left text-sm"><thead><tr><th>Versión / fecha</th><th>Administración</th><th>Motivo</th><th>Δ Ventas</th><th>Δ Cobros</th><th></th></tr></thead><tbody>
                @forelse ($adjustmentHistory as $item)<tr class="border-b border-gray-200 dark:border-gray-700"><td class="py-2">v{{ $item->sequence }}<span class="block text-xs text-gray-500">{{ $item->confirmed_at->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</span></td><td>{{ $item->actor_name }}</td><td class="max-w-xs truncate" title="{{ $item->reason }}">{{ $item->reason }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($item->sales_delta_cents) }}</td><td>Bs {{ \App\Support\Bolivianos::formatCompact($item->collections_delta_cents) }}</td><td><x-filament::button size="sm" color="gray" icon="heroicon-o-eye" wire:click="viewAdjustment({{ $item->id }})">Detalle</x-filament::button></td></tr>@empty<tr><td colspan="6" class="py-4 text-gray-500">Sin ajustes registrados.</td></tr>@endforelse
            </tbody></table></div>{{ $adjustmentHistory->links() }}
            @if ($adjustmentDetail)
                <details open class="mt-4"><summary class="cursor-pointer font-medium">Detalle del ajuste v{{ $adjustmentDetail['adjustment']->sequence }}</summary><p class="my-2 text-sm">{{ $adjustmentDetail['adjustment']->reason }}</p><div class="mb-3 flex flex-wrap gap-2">@foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $format => $label)<x-filament::button tag="a" color="gray" size="sm" :href="route('reportes.cierre-ajuste-export', ['close' => $saved['close']->id, 'adjustment' => $adjustmentDetail['adjustment']->id, 'format' => $format])">{{ $label }} de esta versión</x-filament::button>@endforeach</div><div class="mb-3 flex flex-wrap gap-2">@foreach (['xlsx' => 'Excel', 'csv' => 'CSV'] as $format => $label)<x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-clipboard-document-list" :href="route('reportes.cierre-ajuste-detalle-export', ['close' => $saved['close']->id, 'adjustment' => $adjustmentDetail['adjustment']->id, 'format' => $format])">Detalle {{ $label }} de esta versión</x-filament::button>@endforeach</div>@include('reportes.monthly-adjustment-changes', ['changeRows' => $adjustmentDetail['changes']])</details>
            @endif
        </x-filament::section>
    @endif
    <x-filament::section heading="Cierres mensuales confirmados">
        <div class="overflow-x-auto"><table class="mb-3 w-full text-left text-sm"><thead><tr><th>Mes</th><th>Confirmado por</th><th>Fecha · Bolivia</th><th>Pendientes</th><th></th></tr></thead><tbody>
        @forelse ($history as $close)<tr class="border-b border-gray-200 dark:border-gray-700"><td class="py-2 font-medium">{{ $close->month }}</td><td>{{ $close->actor_name }}</td><td>{{ $close->confirmed_at->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</td><td>{{ $close->pending_count }}</td><td><x-filament::button size="sm" color="gray" icon="heroicon-o-eye" wire:click="viewClose({{ $close->id }})">Ver original</x-filament::button></td></tr>@empty<tr><td colspan="5" class="py-4 text-gray-500">Aún no hay cierres mensuales confirmados.</td></tr>@endforelse
        </tbody></table></div>{{ $history->links() }}
    </x-filament::section>
</x-filament-panels::page>
