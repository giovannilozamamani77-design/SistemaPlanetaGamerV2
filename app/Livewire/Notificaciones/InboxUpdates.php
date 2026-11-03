<?php

namespace App\Livewire\Notificaciones;

use App\Domain\Notificaciones\Queries\PanelInboxVersionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Isolate]
class InboxUpdates extends Component
{
    #[Locked]
    public string $version = '';

    #[Locked]
    public int $ownerId;

    #[Locked]
    public bool $active = true;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $this->ownerId = auth()->id();
        $this->version = app(PanelInboxVersionQuery::class)->execute(auth()->user());
    }

    public function checkForUpdates(): array
    {
        $this->skipRender();
        if (! $this->active || auth()->id() !== $this->ownerId) {
            $this->active = false;

            return ['active' => false, 'changed' => false];
        }
        try {
            $version = app(PanelInboxVersionQuery::class)->execute(auth()->user());
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->active = false;
            $this->dispatch('panel-inbox-refresh');

            return ['active' => false, 'changed' => true];
        }
        $changed = ! hash_equals($this->version, $version);
        if ($changed) {
            $this->version = $version;
            $this->dispatch('panel-inbox-refresh');
        }

        return ['active' => true, 'changed' => $changed];
    }

    public function render(): View
    {
        return view('livewire.notificaciones.inbox-updates');
    }
}
