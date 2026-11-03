<?php

namespace App\Domain\Notificaciones\Queries;

use App\Domain\Notificaciones\Actions\CheckTelegramConnectionAction;
use App\Domain\Notificaciones\Actions\SendTelegramTestMessageAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TelegramSettingsQuery
{
    public const MODULES = ['CAJAS' => 'Cajas', 'PRESTAMOS' => 'Préstamos', 'TARJETAS' => 'Tarjetas', 'ACCESORIOS' => 'Accesorios'];

    public function authorize(User $actor): User
    {
        $u = User::findOrFail($actor->id);
        if (! $u->is_active || ! $u->hasRole('administrador') || ! $u->can('panel.acceder') || ! $u->can('notificaciones.telegram.configurar')) {
            throw new AuthorizationException;
        }

        return $u;
    }

    public function bots(User $actor): Collection
    {
        $this->authorize($actor);

        return TelegramBotSetting::select(['id', 'module', 'name', 'revision', 'is_enabled'])->withCount('destinations')->get()->keyBy('module');
    }

    public function destinations(User $actor): LengthAwarePaginator
    {
        $this->authorize($actor);

        return TelegramDestination::with(['bot:id,module,name,revision', 'responsible:id,name'])->orderByDesc('id')->paginate(5, ['*'], 'destinationsPage');
    }

    public function history(User $actor): LengthAwarePaginator
    {
        $this->authorize($actor);

        return TelegramConfigurationChange::orderByDesc('id')->paginate(5, ['*'], 'changesPage');
    }

    public function connectionStatuses(User $actor, Collection $bots, $destinations): array
    {
        $this->authorize($actor);
        $latest = TelegramConfigurationChange::selectRaw('MAX(id)')->where(function ($q) use ($bots, $destinations) {
            $q->where(fn ($q) => $q->where('kind', 'BOT_CHECK')->whereIn('source_id', $bots->pluck('id')))
                ->orWhere(fn ($q) => $q->where('kind', 'DESTINATION_CHECK')->whereIn('source_id', collect($destinations->items())->pluck('id')));
        })->groupBy('kind', 'source_id');
        $checks = TelegramConfigurationChange::whereIn('id', $latest)->get()->keyBy(fn ($row) => $row->kind.':'.$row->source_id);
        $statuses = [];
        foreach ($bots as $bot) {
            $statuses['BOT:'.$bot->id] = $this->status($checks->get('BOT_CHECK:'.$bot->id), $bot->revision, $bot->revision);
        }
        foreach ($destinations as $dest) {
            $statuses['DESTINATION:'.$dest->id] = $this->status($checks->get('DESTINATION_CHECK:'.$dest->id), $dest->revision, $dest->bot->revision);
        }

        return $statuses;
    }

    private function status(?TelegramConfigurationChange $check, int $revision, int $botRevision): string
    {
        if (! $check || (int) ($check->snapshot['after']['revision'] ?? 0) !== $revision || (int) ($check->snapshot['bot_revision'] ?? 0) !== $botRevision) {
            return 'Sin comprobar · pausado';
        }

        return CheckTelegramConnectionAction::message($check->snapshot).' '.$check->created_at->timezone('America/La_Paz')->format('d/m H:i');
    }

    public function testForm(User $actor, int $id): array
    {
        $state = $this->stateForm($actor, $id);

        return collect($state)->only(['bot_id', 'expected_revision', 'destination_revisions', 'request_key'])->all() + ['destination_id' => null, 'confirmed' => false];
    }

    public function testStatuses(User $actor, Collection $bots): array
    {
        $this->authorize($actor);
        $latest = TelegramConfigurationChange::selectRaw('MAX(id)')->where('kind', 'BOT_TEST_REQUEST')->whereIn('source_id', $bots->pluck('id'))->groupBy('source_id');
        $requests = TelegramConfigurationChange::whereIn('id', $latest)->get();
        $results = TelegramConfigurationChange::where('kind', 'BOT_TEST_RESULT')->whereIn('source_id', $requests->pluck('id'))->get()->keyBy('source_id');

        return $requests->mapWithKeys(fn ($request) => [$request->source_id => SendTelegramTestMessageAction::message($results->get($request->id)?->snapshot ?? $request->snapshot)])->all();
    }

    public function stateForm(User $actor, int $id): array
    {
        $this->authorize($actor);
        $bot = TelegramBotSetting::findOrFail($id);
        $destinations = $bot->destinations()->get();

        return ['bot_id' => $id, 'enabled' => ! $bot->is_enabled, 'expected_revision' => $bot->revision,
            'expected_state_version' => (int) TelegramConfigurationChange::where('kind', 'BOT_STATE')->where('source_id', $id)->max('id'),
            'destination_ids' => $destinations->where('is_enabled', true)->pluck('id')->all(),
            'destination_revisions' => $destinations->pluck('revision', 'id')->all(), 'request_key' => (string) Str::uuid()];
    }

    public function stateDestinationOptions(User $actor, int $id): array
    {
        $this->authorize($actor);

        return TelegramDestination::where('bot_id', $id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function recipients(User $actor): array
    {
        $this->authorize($actor);

        return User::where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('name', ['administrador', 'encargado']))->orderBy('name')->pluck('name', 'id')->all();
    }
}
