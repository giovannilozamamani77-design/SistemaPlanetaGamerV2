<?php

namespace App\Domain\Reportes\Queries;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class MonthlyReportSnapshotQuery
{
    public function month(string $month): string
    {
        Validator::make(['month' => $month], ['month' => ['required', 'date_format:Y-m', 'after_or_equal:1900-01']], ['month.*' => 'Elige un mes válido desde enero de 1900.'])->validate();
        $first = CarbonImmutable::createFromFormat('!Y-m', $month, 'America/La_Paz');
        if ($first >= now('America/La_Paz')->startOfMonth()) {
            throw ValidationException::withMessages(['month' => 'Solo puedes cerrar meses terminados, en hora de Bolivia.']);
        }

        return $first->toDateString();
    }

    public function execute(User $actor, string $month): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        $first = $this->month($month);

        return DB::transaction(function () use ($actor, $month, $first): array {
            $report = app(DailyReportQuery::class)->execute($actor, $first, selection: ['mode' => 'month']);
            unset($report['checked_at']);
            $report['balances'] = [];
            $report['balance_scope'] = 'excluded_monthly';
            $records = app(DailyReportRecordsQuery::class)->evidence($actor, $report['period']['start'], $report['period']['end']);
            if (count(array_unique(array_column($records, 'key'))) !== count($records)) {
                throw new LogicException('La evidencia del cierre contiene contribuciones repetidas.');
            }
            $until = CarbonImmutable::parse($report['period']['end'], 'America/La_Paz')->addDay()->utc()->format('Y-m-d H:i:s');
            $pending = $this->pending($until);
            $payload = ['schema_version' => 1, 'month' => $month, 'timezone' => 'America/La_Paz', 'report' => $report, 'records' => $records, 'pending' => $pending];

            return ['snapshot' => $payload, 'hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
        });
    }

    private function pending(string $until): array
    {
        $rows = [];
        $incidents = DB::table('economic_incidents')->where('occurred_at', '<', $until)
            ->whereIn('status', ['DETECTADA', 'EN_REVISION', 'CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'])
            ->where(fn (Builder $q): Builder => $q->where('pending_cents', '>', 0)->orWhereIn('status', ['DETECTADA', 'EN_REVISION']))
            ->orderBy('id')->get(['id', 'source_module', 'source_reference', 'status', 'user_id', 'occurred_at', 'pending_cents', 'amount_cents']);
        foreach ($incidents as $row) {
            $rows[] = ['key' => 'INCIDENT:'.$row->id, 'source' => 'Faltante / incidencia', 'record_id' => $row->id, 'reference' => $row->source_module.' · '.$row->source_reference,
                'actor_id' => $row->user_id, 'recorded_at' => $row->occurred_at, 'status' => $row->status,
                'amount_cents' => (int) (in_array($row->status, ['DETECTADA', 'EN_REVISION'], true) ? $row->amount_cents : $row->pending_cents)];
        }
        $closes = DB::table('cash_box_closes')->where('created_at', '<', $until)->whereNull('cancelled_at')->where('requires_liquidation', true)->where('liquidation_completed', false)
            ->orderBy('id')->get(['id', 'cash_box_id', 'user_id', 'created_at']);
        foreach ($closes as $row) {
            $rows[] = ['key' => 'LIQUIDATION:'.$row->id, 'source' => 'Liquidación pendiente', 'record_id' => $row->id, 'reference' => 'Cierre #'.$row->id.' · Caja #'.$row->cash_box_id,
                'actor_id' => $row->user_id, 'recorded_at' => $row->created_at, 'status' => 'PENDIENTE', 'amount_cents' => null];
        }
        $corrections = DB::table('cash_box_corrections as correction')->join('cash_box_operations as operation', 'operation.id', '=', 'correction.cash_box_operation_id')
            ->where('operation.created_at', '<', $until)->whereIn('correction.status', ['PENDIENTE', 'DEVUELTA'])->orderBy('correction.id')
            ->get(['correction.id', 'correction.status', 'operation.user_id', 'operation.created_at', 'operation.id as operation_id']);
        foreach ($corrections as $row) {
            $rows[] = ['key' => 'CORRECTION:'.$row->id, 'source' => 'Corrección en revisión', 'record_id' => $row->id, 'reference' => 'Operación #'.$row->operation_id,
                'actor_id' => $row->user_id, 'recorded_at' => $row->created_at, 'status' => $row->status, 'amount_cents' => null];
        }
        $names = User::whereIn('id', array_column($rows, 'actor_id'))->pluck('name', 'id');
        foreach ($rows as &$row) {
            $row['actor_name'] = $names->get($row['actor_id'], 'Sin responsable');
        }

        return $rows;
    }
}
