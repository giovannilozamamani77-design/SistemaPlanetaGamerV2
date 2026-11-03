<?php

namespace App\Domain\Notificaciones\Queries;

use App\Domain\Notificaciones\Actions\SendTelegramTestMessageAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDeliveryAttempt;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TelegramDeliveryQuery
{
    public const TEST_STATES = ['PENDING' => 'En proceso / sin resultado', 'SENT' => 'Enviada', 'REJECTED' => 'Rechazada', 'UNCERTAIN' => 'Sin confirmar', 'PAUSED' => 'Pausada por mantenimiento', 'CANCELLED' => 'Cancelada'];

    public function history(User $actor, string $module = '', string $status = '', array $filters = []): LengthAwarePaginator
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        if ($module !== '' && ! array_key_exists($module, TelegramSettingsQuery::MODULES)) {
            throw ValidationException::withMessages(['module' => 'Módulo inválido.']);
        }
        if ($status !== '' && ! array_key_exists($status, TelegramDelivery::LABELS)) {
            throw ValidationException::withMessages(['status' => 'Estado inválido.']);
        }

        $filter = $this->filters($filters);
        $query = TelegramDispatch::select(['id', 'module', 'event_code', 'source_reference', 'status', 'reason', 'created_at'])
            ->with(['deliveries' => fn ($q) => $q->select(['id', 'dispatch_id', 'destination_name', 'status', 'attempt_count', 'last_code', 'message_id', 'snapshot'])->when($filter['destinationId'], fn ($q, $id) => $q->where('destination_id', $id))])
            ->when($module, fn ($q) => $q->where('module', $module));
        $this->dateRange($query, $filter);
        if ($filter['destinationId']) {
            $query->whereHas('deliveries', fn ($q) => $q->where('destination_id', $filter['destinationId'])->when($status, fn ($q) => $q->where('status', $status)));
        } elseif ($status !== '') {
            $query->where(fn ($q) => $q->where('status', $status)->orWhereHas('deliveries', fn ($q) => $q->where('status', $status)));
        }
        $page = $query->orderByDesc('id')->paginate(5);
        $bots = TelegramBotSetting::whereIn('module', $page->getCollection()->pluck('module'))->pluck('name', 'module');
        foreach ($page as $notice) {
            $name = ($notice->deliveries->first()?->snapshot ?? [])['bot_name'] ?? null;
            $notice->setAttribute('bot_name', $name ?? $bots->get($notice->module) ?? 'Bot de '.(TelegramSettingsQuery::MODULES[$notice->module] ?? $notice->module));
            $notice->setAttribute('bot_name_historical', $name !== null);
            $notice->setAttribute('bot_name_current', $bots->has($notice->module));
        }

        return $page;
    }

    public function testHistory(User $actor, string $module = '', string $status = '', array $filters = []): LengthAwarePaginator
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        if ($module !== '' && ! array_key_exists($module, TelegramSettingsQuery::MODULES)) {
            throw ValidationException::withMessages(['module' => 'Módulo inválido.']);
        }
        if ($status !== '' && ! array_key_exists($status, self::TEST_STATES)) {
            throw ValidationException::withMessages(['status' => 'Resultado de prueba inválido.']);
        }
        $filter = $this->filters($filters);
        $summaryFields = ['status', 'module', 'bot_name', 'destination_name', 'destination_id'];
        $query = TelegramConfigurationChange::select(['id', 'source_id', 'actor_name', 'created_at'])
            ->addSelect(array_map(fn ($field) => 'snapshot->'.$field.' as saved_'.$field, $summaryFields))->where('kind', 'BOT_TEST_REQUEST');
        $this->testModule($query, $module);
        $this->dateRange($query, $filter);
        if ($filter['destinationId']) {
            $query->where('snapshot->destination_id', $filter['destinationId']);
        }
        $resultQuery = fn ($q) => $q->selectRaw('1')->from('telegram_configuration_changes as test_result')->where('test_result.kind', 'BOT_TEST_RESULT')->whereColumn('test_result.source_id', 'telegram_configuration_changes.id');
        if ($status === 'PENDING') {
            $query->whereNotExists($resultQuery);
        } elseif ($status !== '') {
            $query->whereExists(fn ($q) => $resultQuery($q)->where('test_result.snapshot->status', $status));
        }
        $page = $query->orderByDesc('id')->paginate(5);
        $requests = $page->getCollection();
        $requests->each(function ($request) use ($summaryFields): void {
            $saved = [];
            foreach ($summaryFields as $field) {
                if ($request->{'saved_'.$field} !== null) {
                    $saved[$field] = $request->{'saved_'.$field};
                }
            }
            $request->setAttribute('snapshot', $saved);
        });
        $results = TelegramConfigurationChange::select(['id', 'source_id', 'snapshot', 'created_at'])->where('kind', 'BOT_TEST_RESULT')->whereIn('source_id', $requests->pluck('id'))->orderBy('id')->get()->keyBy('source_id');
        $bots = TelegramBotSetting::select(['id', 'name', 'module'])->whereIn('id', $requests->pluck('source_id'))->get()->keyBy('id');
        $destinations = TelegramDestination::whereIn('id', $requests->map(fn ($r) => $r->snapshot['destination_id'] ?? 0))->pluck('name', 'id');
        $page->setCollection($requests->map(function ($request) use ($results, $bots, $destinations): array {
            $saved = $request->snapshot;
            $result = $results->get($request->id);
            $outcome = $result?->snapshot ?? $saved;
            $bot = $bots->get($request->source_id);

            return [
                'id' => $request->id, 'created_at' => $request->created_at, 'completed_at' => $result?->created_at,
                'bot_name' => $saved['bot_name'] ?? $bot?->name ?? 'Bot no disponible',
                'bot_name_historical' => isset($saved['bot_name']), 'bot_name_note' => isset($saved['bot_name']) ? null : ($bot ? 'Nombre actual' : 'Nombre no guardado'), 'module' => $saved['module'] ?? $bot?->module,
                'destination_name' => $saved['destination_name'] ?? $destinations->get($saved['destination_id'] ?? 0) ?? 'Destino no disponible',
                'destination_name_historical' => isset($saved['destination_name']), 'destination_name_note' => isset($saved['destination_name']) ? null : ($destinations->has($saved['destination_id'] ?? 0) ? 'Nombre actual' : 'Nombre no guardado'), 'actor_name' => $request->actor_name,
                'status' => $outcome['status'] ?? 'PENDING', 'result_message' => SendTelegramTestMessageAction::message($outcome),
            ];
        }));

        return $page;
    }

    private function filters(array $input): array
    {
        $v = Validator::make(array_map(fn ($value) => $value === '' ? null : $value, $input), [
            'dateFrom' => ['nullable', 'date_format:Y-m-d'], 'dateTo' => ['nullable', 'date_format:Y-m-d'],
            'destinationId' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        if (! empty($v['dateFrom']) && ! empty($v['dateTo']) && $v['dateFrom'] > $v['dateTo']) {
            throw ValidationException::withMessages(['dateTo' => 'La fecha final debe ser igual o posterior a la inicial.']);
        }

        return ['dateFrom' => $v['dateFrom'] ?? null, 'dateTo' => $v['dateTo'] ?? null, 'destinationId' => isset($v['destinationId']) ? (int) $v['destinationId'] : null];
    }

    private function dateRange($query, array $filter): void
    {
        if ($filter['dateFrom']) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filter['dateFrom'], 'America/La_Paz')->utc()->format('Y-m-d H:i:s'));
        }
        if ($filter['dateTo']) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filter['dateTo'], 'America/La_Paz')->addDay()->utc()->format('Y-m-d H:i:s'));
        }
    }

    private function testModule($query, string $module): void
    {
        if ($module !== '') {
            $query->where(fn ($q) => $q->where('snapshot->module', $module)->orWhere(fn ($q) => $q->whereNull('snapshot->module')->whereIn('source_id', TelegramBotSetting::select('id')->where('module', $module))));
        }
    }

    public function filterOptions(User $actor, string $module = '', string $type = 'AUTOMATIC'): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        if (($module !== '' && ! array_key_exists($module, TelegramSettingsQuery::MODULES)) || ! in_array($type, ['AUTOMATIC', 'TEST'], true)) {
            throw ValidationException::withMessages(['module' => 'Selecciona un bot y tipo válidos.']);
        }
        $bots = TelegramBotSetting::select(['id', 'module', 'name'])->get()->keyBy('module');
        $labels = collect(TelegramSettingsQuery::MODULES)->map(fn ($label, $key) => $label.' · '.($bots->get($key)?->name ?? 'Sin configurar'))->all();
        $destinations = TelegramDestination::query();
        if ($module !== '') {
            $historic = $type === 'AUTOMATIC'
                ? TelegramDelivery::select('destination_id')->whereHas('dispatch', fn ($q) => $q->where('module', $module))
                : TelegramConfigurationChange::select('snapshot->destination_id')->where('kind', 'BOT_TEST_REQUEST');
            if ($type === 'TEST') {
                $this->testModule($historic, $module);
            }
            $destinations->where(fn ($q) => $q->whereIn('bot_id', TelegramBotSetting::select('id')->where('module', $module))->orWhereIn('id', $historic));
        }

        return ['bots' => $labels, 'destinations' => $destinations->orderBy('name')->orderBy('id')->pluck('name', 'id')->all()];
    }

    public function detail(User $actor, int $id): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $dispatch = TelegramDispatch::findOrFail($id);
        $deliveries = $dispatch->deliveries()->select(['id', 'dispatch_id', 'destination_name', 'status', 'attempt_count', 'automatic_attempts', 'last_code', 'message_id', 'next_attempt_at', 'snapshot'])->orderBy('id')->get();
        $attempts = TelegramDeliveryAttempt::whereIn('delivery_id', $deliveries->pluck('id'))->orderByDesc('id')->paginate(10, ['*'], 'attemptsPage');
        $manual = TelegramConfigurationChange::where('kind', 'DELIVERY_RETRY')->whereIn('source_id', $deliveries->pluck('id'))->latest('id')->limit(10)->get();

        $savedName = ($deliveries->first()?->snapshot ?? [])['bot_name'] ?? null;
        $currentName = TelegramBotSetting::where('module', $dispatch->module)->value('name');

        return compact('dispatch', 'deliveries', 'attempts', 'manual') + [
            'bot_name' => $savedName ?? $currentName ?? 'Bot de '.(TelegramSettingsQuery::MODULES[$dispatch->module] ?? $dispatch->module),
            'bot_name_note' => $savedName ? null : ($currentName ? 'Nombre actual' : 'Identificado por módulo'),
            'attempt_destinations' => $deliveries->pluck('destination_name', 'id')->all(),
            'total_attempts' => $deliveries->sum('attempt_count'),
        ];
    }

    public function testDetail(User $actor, int $id): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $request = TelegramConfigurationChange::select(['id', 'source_id', 'snapshot', 'actor_name', 'created_at'])->where('kind', 'BOT_TEST_REQUEST')->findOrFail($id);
        $saved = $request->snapshot;
        $result = TelegramConfigurationChange::select(['id', 'snapshot', 'created_at'])->where('kind', 'BOT_TEST_RESULT')->where('source_id', $id)->latest('id')->first();
        $starts = TelegramConfigurationChange::select(['id', 'created_at'])->where('kind', 'BOT_TEST_ATTEMPT')->where('source_id', $id)->orderBy('id')->get();
        $bot = TelegramBotSetting::select(['name', 'module'])->find($request->source_id);
        $destination = TelegramDestination::select(['name'])->find($saved['destination_id'] ?? 0);
        $outcome = $result?->snapshot ?? $saved;

        return [
            'id' => $request->id, 'module' => $saved['module'] ?? $bot?->module, 'actor_name' => $request->actor_name,
            'created_at' => $request->created_at, 'completed_at' => $result?->created_at,
            'bot_name' => $saved['bot_name'] ?? $bot?->name ?? 'Bot no disponible',
            'bot_name_note' => isset($saved['bot_name']) ? null : ($bot ? 'Nombre actual' : 'Nombre no guardado'),
            'destination_name' => $saved['destination_name'] ?? $destination?->name ?? 'Destino no disponible',
            'destination_name_note' => isset($saved['destination_name']) ? null : ($destination ? 'Nombre actual' : 'Nombre no guardado'),
            'message' => is_string($saved['message'] ?? null) ? $saved['message'] : null,
            'media' => $this->testMedia($saved),
            'event_code' => $saved['event_code'] ?? null, 'presentation_revision' => $saved['presentation_revision'] ?? null,
            'status' => $outcome['status'] ?? 'PENDING', 'result_message' => SendTelegramTestMessageAction::message($outcome),
            'code' => $outcome['code'] ?? null, 'message_id' => $outcome['message_id'] ?? null,
            'http_status' => $outcome['http_status'] ?? null, 'starts' => $starts,
            'attempts_recorded' => $starts->count(), 'legacy_attempt' => ! isset($saved['message_template_version']) && $starts->isEmpty(),
        ];
    }

    private function testMedia(array $saved): ?array
    {
        if (empty($saved['encrypted_media'])) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($saved['encrypted_media']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    public function retryForm(User $actor, int $id): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $d = TelegramDelivery::findOrFail($id);

        return ['delivery_id' => $id, 'expected_attempt_count' => $d->attempt_count, 'reason' => '', 'confirm_uncertain' => false, 'request_key' => (string) Str::uuid()];
    }
}
