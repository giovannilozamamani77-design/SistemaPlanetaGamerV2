<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Reportes\Data\ReportPeriod;
use App\Domain\Tarjetas\Models\CardSaleLine;
use App\Models\User;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DailyReportRecordsQuery
{
    private const SOURCES = [
        'CARDS' => ['label' => 'Tarjetas físicas y Cabina Viva', 'sale' => true, 'note' => 'Normal y mayorista; importe cobrado, sin sumar otra vez los movimientos de efectivo.'],
        'CREDIT' => ['label' => 'Entel · Crédito', 'sale' => true, 'note' => 'Cobrado al cliente; la bonificación no se suma como otro cobro.'],
        'PACKAGES' => ['label' => 'Entel · Paquetes', 'sale' => true, 'note' => 'Cobrado al cliente; la bonificación no se suma como otro cobro.'],
        'ACCESSORY' => ['label' => 'Accesorios', 'sale' => true, 'note' => 'Importe final registrado, con descuentos. Una venta mixta aparece en ambos tipos, sin duplicar sus importes.'],
        'PHONE' => ['label' => 'Teléfonos', 'sale' => true, 'note' => 'Importe final registrado, con descuentos. Una venta mixta aparece en ambos tipos, sin duplicar sus importes.'],
        'OBJECT_SALE' => ['label' => 'Préstamos · Venta de objetos', 'sale' => true, 'note' => 'Solo cobros confirmados; no incluye capital entregado, aportes ni anulaciones.'],
        'TERM_PAYMENT' => ['label' => 'Préstamos · Renovaciones y ampliaciones', 'sale' => false, 'note' => 'Solo cobros confirmados; no incluye capital entregado, aportes ni anulaciones.'],
        'SETTLEMENT' => ['label' => 'Préstamos · Pago y entrega', 'sale' => false, 'note' => 'Incluye el capital recuperado y lo cobrado; no es ganancia.'],
        'BOX_FEES' => ['label' => 'Cajas · Comisiones operativas', 'sale' => false, 'note' => 'Operaciones vigentes; no se suma el monto transferido entre cuentas.'],
    ];

    public function context(User $actor, string $date, string $metric = 'collections', string $source = 'ALL', ?string $untilDate = null): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        $untilDate ??= $date;
        Validator::make(compact('date', 'metric', 'source', 'untilDate'), [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('America/La_Paz')->toDateString()],
            'untilDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:date', 'before_or_equal:'.now('America/La_Paz')->toDateString()],
            'metric' => ['required', Rule::in(['sales', 'collections'])],
            'source' => ['required', Rule::in(['ALL', ...array_keys(self::SOURCES)])],
        ], ['date.*' => 'Selecciona un día válido, hasta hoy, en hora de Bolivia.', 'metric.*' => 'Selecciona Ventas o Cobros.', 'source.*' => 'Selecciona un origen válido del resumen.', 'untilDate.*' => 'El final del periodo debe ser válido, no anterior al inicio ni posterior a hoy.'])->validate();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/La_Paz');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $untilDate, 'America/La_Paz');

        return ['date' => $date, 'until_date' => $untilDate, 'period_label' => app(ReportPeriod::class)->label($date, $untilDate), 'metric' => $metric, 'source' => $source,
            'label' => $source === 'ALL' ? 'Todos los orígenes' : self::SOURCES[$source]['label'],
            'metric_label' => $metric === 'sales' ? 'Ventas' : 'Cobros',
            'from' => $day->utc()->format('Y-m-d H:i:s'), 'until' => $end->addDay()->utc()->format('Y-m-d H:i:s')];
    }

    public function selectedContributions(User $actor, string $date, string $untilDate, array $sources): Builder
    {
        $context = $this->context($actor, $date, untilDate: $untilDate);
        Validator::make(['sources' => $sources], ['sources' => ['required', 'array', 'min:1'], 'sources.*' => ['required', Rule::in(array_keys(self::SOURCES))]])->validate();
        $union = null;
        foreach (array_unique($sources) as $source) {
            $part = $this->source($source, $context['from'], $context['until']);
            $union = $union === null ? $part : $union->unionAll($part);
        }

        return DB::query()->fromSub($union, 'module_records');
    }

    public function sourceLabels(): array
    {
        return array_map(fn (array $definition): string => $definition['label'], self::SOURCES);
    }

    public function summary(User $actor, string $date, ?string $untilDate = null): array
    {
        $context = $this->context($actor, $date, untilDate: $untilDate);
        $totals = $this->contributions($context)->selectRaw('source, COUNT(*) AS operations, SUM(sales_cents) AS sales_cents, SUM(collections_cents) AS collections_cents')->groupBy('source')->get()->keyBy('source');
        $rows = [];
        foreach (self::SOURCES as $source => $definition) {
            $total = $totals->get($source);
            $rows[] = ['source' => $source, 'label' => $definition['label'], 'operations' => (int) ($total?->operations ?? 0),
                'sales_cents' => (int) ($total?->sales_cents ?? 0), 'collections_cents' => (int) ($total?->collections_cents ?? 0), 'note' => $definition['note']];
        }

        return $rows;
    }

    public function records(User $actor, string $date, string $metric = 'collections', string $source = 'ALL', int $page = 1, int $perPage = 5, ?string $untilDate = null): array
    {
        $context = $this->context($actor, $date, $metric, $source, $untilDate);
        Validator::make(compact('page', 'perPage'), ['page' => ['integer', 'between:1,1000000'], 'perPage' => ['integer', Rule::in([5, 10, 25])]])->validate();

        return DB::transaction(function () use ($context, $page, $perPage): array {
            $query = $this->contributions($context);
            $column = $context['metric'].'_cents';
            $totals = (clone $query)->selectRaw('COUNT(*) AS records_count, COALESCE(SUM('.$column.'), 0) AS total_cents')->first();
            $records = $query->orderByDesc('recorded_at')->orderBy('source')->orderByDesc('record_id')
                ->paginate($perPage, ['*'], 'reportRecordsPage', $page, (int) $totals->records_count);
            $records->setCollection($this->describe($records->getCollection(), $column));

            return ['date' => $context['date'], 'until_date' => $context['until_date'], 'period_label' => $context['period_label'], 'label' => $context['label'], 'metric_label' => $context['metric_label'], 'total_cents' => (int) $totals->total_cents, 'records' => $records];
        });
    }

    public function export(User $actor, string $date, string $metric, string $source, string $untilDate, callable $consume): mixed
    {
        $context = $this->context($actor, $date, $metric, $source, $untilDate);

        return DB::transaction(function () use ($actor, $context, $consume): mixed {
            $query = $this->contributions($context);
            $column = $context['metric'].'_cents';
            $totals = (clone $query)->selectRaw('COUNT(*) AS records_count, COALESCE(SUM('.$column.'), 0) AS total_cents')->first();
            if ((int) $totals->records_count > 50000) {
                throw ValidationException::withMessages(['period' => 'El detalle supera 50000 registros. Reduce el rango de fechas para descargarlo completo.']);
            }
            $records = function () use ($actor, $query, $column, $totals): \Generator {
                for ($offset = 0; $offset < (int) $totals->records_count; $offset += 500) {
                    Gate::forUser($actor)->authorize('viewDailyReports');
                    $batch = (clone $query)->orderByDesc('recorded_at')->orderBy('source')->orderByDesc('record_id')->offset($offset)->limit(500)->get();
                    yield from $this->describe($batch, $column);
                }
            };

            return $consume([...$context, 'records_count' => (int) $totals->records_count, 'total_cents' => (int) $totals->total_cents], $records());
        });
    }

    public function evidence(User $actor, string $date, string $untilDate): array
    {
        $context = $this->context($actor, $date, untilDate: $untilDate);
        $records = $this->contributions($context)->orderBy('source')->orderBy('record_id')->get();
        $names = User::whereIn('id', $records->pluck('actor_id')->unique())->pluck('name', 'id');

        return $records->map(fn ($record): array => [
            'key' => $record->source.':'.$record->record_id, 'source' => $record->source,
            'record_id' => (int) $record->record_id, 'recorded_at' => $record->recorded_at,
            'actor_id' => $record->actor_id === null ? null : (int) $record->actor_id,
            'actor_name' => $names->get($record->actor_id, 'Sin responsable'),
            'subject_id' => $record->subject_id === null ? null : (int) $record->subject_id,
            'quantity' => (int) $record->quantity, 'sales_cents' => (int) $record->sales_cents,
            'collections_cents' => (int) $record->collections_cents,
        ])->all();
    }

    private function contributions(array $context): Builder
    {
        $union = null;
        foreach (self::SOURCES as $source => $definition) {
            if (($context['source'] !== 'ALL' && $context['source'] !== $source) || ($context['metric'] === 'sales' && ! $definition['sale'])) {
                continue;
            }
            $part = $this->source($source, $context['from'], $context['until']);
            if ($union === null) {
                $union = $part;
            } else {
                $union->unionAll($part);
            }
        }
        if ($union === null) {
            $union = $this->source('CARDS', $context['from'], $context['until'])->whereRaw('1 = 0');
        }

        return DB::query()->fromSub($union, 'daily_records');
    }

    private function source(string $source, string $from, string $until): Builder
    {
        if (in_array($source, ['ACCESSORY', 'PHONE'], true)) {
            return DB::table('accessory_sale_lines as line')->join('accessory_sales as sale', 'sale.id', '=', 'line.sale_id')
                ->where('sale.created_at', '>=', $from)->where('sale.created_at', '<', $until)->where('line.kind', $source)
                ->selectRaw('? AS source, sale.id AS record_id, sale.created_at AS recorded_at, sale.user_id AS actor_id, sale.id AS subject_id, SUM(line.quantity) AS quantity, SUM(line.total_cents) AS sales_cents, SUM(line.total_cents) AS collections_cents', [$source])
                ->groupBy('sale.id', 'sale.created_at', 'sale.user_id');
        }
        if ($source === 'CARDS') {
            $query = DB::table('card_sales');
            $fields = 'id AS record_id, created_at AS recorded_at, user_id AS actor_id, card_store_id AS subject_id, total_quantity AS quantity, total_cents AS sales_cents, total_cents AS collections_cents';
        } elseif (in_array($source, ['CREDIT', 'PACKAGES'], true)) {
            $query = DB::table('entel_recharges')->where('kind', $source);
            $fields = 'id AS record_id, created_at AS recorded_at, user_id AS actor_id, card_store_id AS subject_id, 0 AS quantity, amount_cents AS sales_cents, amount_cents AS collections_cents';
        } elseif ($source === 'BOX_FEES') {
            $query = DB::table('cash_box_operations')->where('status', 'VIGENTE');
            $fields = 'id AS record_id, created_at AS recorded_at, user_id AS actor_id, cash_box_session_id AS subject_id, 0 AS quantity, 0 AS sales_cents, fee_cents AS collections_cents';
        } else {
            $query = DB::table('loan_movements')->where('kind', $source);
            $fields = 'id AS record_id, created_at AS recorded_at, user_id AS actor_id, loan_id AS subject_id, 0 AS quantity, '.($source === 'OBJECT_SALE' ? 'delta_cents' : '0').' AS sales_cents, delta_cents AS collections_cents';
        }

        return $query->where('created_at', '>=', $from)->where('created_at', '<', $until)->selectRaw('? AS source, '.$fields, [$source]);
    }

    private function describe(Collection $records, string $column): Collection
    {
        $ids = fn (array $sources): array => $records->whereIn('source', $sources)->pluck('record_id')->all();
        $users = User::whereIn('id', $records->pluck('actor_id'))->pluck('name', 'id');
        $cardModes = DB::table('card_sales')->whereIn('id', $ids(['CARDS']))->pluck('mode', 'id');
        $cardLines = CardSaleLine::whereIn('card_sale_id', $ids(['CARDS']))->orderBy('id')->get(['card_sale_id', 'quantity', 'total_cents', 'product_snapshot'])->groupBy('card_sale_id');
        $productLines = AccessorySaleLine::whereIn('sale_id', $ids(['ACCESSORY', 'PHONE']))->orderBy('id')->get(['sale_id', 'kind', 'quantity', 'total_cents', 'product_name'])->groupBy('sale_id');
        $saleActors = DB::table('accessory_sales')->whereIn('id', $ids(['ACCESSORY', 'PHONE']))->pluck('actor_name', 'id');
        $loans = Loan::whereIn('id', $records->whereIn('source', ['OBJECT_SALE', 'TERM_PAYMENT', 'SETTLEMENT'])->pluck('subject_id'))->get(['id', 'borrower_snapshot', 'object_snapshot'])->keyBy('id');
        $boxes = DB::table('cash_box_sessions as session')->join('cash_boxes as box', 'box.id', '=', 'session.cash_box_id')->whereIn('session.id', $records->where('source', 'BOX_FEES')->pluck('subject_id'))->pluck('box.name', 'session.id');

        return $records->map(function (object $record) use ($column, $users, $cardModes, $cardLines, $productLines, $saleActors, $loans, $boxes): array {
            $items = [];
            $description = match ($record->source) {
                'CARDS' => $cardModes->get($record->record_id) === 'WHOLESALE' ? 'Venta mayorista' : 'Venta normal',
                'CREDIT' => 'Recarga mayorista de crédito Entel',
                'PACKAGES' => 'Recarga mayorista de paquetes Entel',
                'ACCESSORY' => 'Venta de accesorios · importe final',
                'PHONE' => 'Venta de teléfonos · importe final',
                'SETTLEMENT' => 'Pago y entrega · incluye capital recuperado',
                'TERM_PAYMENT' => 'Cobro de renovación o ampliación',
                'OBJECT_SALE' => 'Venta de objeto de Préstamos',
                default => 'Comisión operativa',
            };
            $reference = match ($record->source) {
                'CARDS', 'ACCESSORY', 'PHONE' => 'Venta #'.$record->record_id,
                'CREDIT', 'PACKAGES' => 'Recarga #'.$record->record_id,
                'BOX_FEES' => 'Operación #'.$record->record_id,
                default => 'Movimiento #'.$record->record_id.($record->subject_id ? ' · Préstamo #'.$record->subject_id : ''),
            };
            if ($record->source === 'CARDS') {
                $items = $cardLines->get($record->record_id, collect())->map(fn ($line): array => ['label' => $line->product_snapshot['label'] ?? (($line->product_snapshot['company'] ?? 'Tarjeta').' · Bs '.Bolivianos::formatCompact((int) ($line->product_snapshot['face_value_cents'] ?? 0))), 'quantity' => $line->quantity, 'amount_cents' => $line->total_cents])->all();
            } elseif (in_array($record->source, ['ACCESSORY', 'PHONE'], true)) {
                $items = $productLines->get($record->record_id, collect())->where('kind', $record->source)->map(fn ($line): array => ['label' => $line->product_name, 'quantity' => $line->quantity, 'amount_cents' => $line->total_cents])->values()->all();
            } elseif ($record->source === 'BOX_FEES') {
                $description = ($boxes->get($record->subject_id) ?? 'Caja').' · Solo comisión operativa vigente.';
            } elseif ($loan = $loans->get($record->subject_id)) {
                $description = ($loan->borrower_snapshot['name'] ?? 'Cliente').' · '.($loan->object_snapshot['name'] ?? 'Objeto en garantía').'. '.$description;
            }

            return ['key' => $record->source.':'.$record->record_id, 'source' => $record->source, 'reference' => $reference,
                'label' => self::SOURCES[$record->source]['label'], 'recorded_at' => CarbonImmutable::parse($record->recorded_at, 'UTC')->setTimezone('America/La_Paz')->format('d/m/Y H:i:s'),
                'actor' => in_array($record->source, ['ACCESSORY', 'PHONE'], true) ? ($saleActors->get($record->record_id) ?? $users->get($record->actor_id, 'Sin responsable')) : $users->get($record->actor_id, 'Sin responsable'),
                'sales_cents' => (int) $record->sales_cents, 'collections_cents' => (int) $record->collections_cents, 'amount_cents' => (int) $record->{$column},
                'quantity' => (int) $record->quantity, 'description' => $description, 'items' => $items];
        });
    }
}
