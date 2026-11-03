<?php

namespace App\Livewire\Cajas;

use App\Domain\Cajas\Queries\CashBoxCorrectionAlertsQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

#[Isolate]
class CorrectionAlerts extends Component
{
    #[Locked]
    public int $accessId;

    #[Locked]
    public array $alerts = ['unread' => 0, 'returned' => 0];

    #[Locked]
    public bool $active = true;

    public function mount(int $accessId, ?array $initialAlerts = null): void
    {
        $this->accessId = $accessId;
        // Initial values come from the already authorized server-rendered workspace.
        // Reuse them instead of repeating its aggregate query on page load.
        if ($initialAlerts !== null) {
            $this->alerts = ['unread' => max(0, (int) ($initialAlerts['unread'] ?? 0)), 'returned' => max(0, (int) ($initialAlerts['returned'] ?? 0))];

            return;
        }
        $state = $this->readState();
        $this->active = $state['active'];
        $this->alerts = ['unread' => $state['unread'], 'returned' => $state['returned']];
    }

    public function refreshAlerts(): void
    {
        if (! $this->active) {
            $this->skipRender();

            return;
        }
        $state = $this->readState();
        $counts = ['unread' => $state['unread'], 'returned' => $state['returned']];
        if ($this->active === $state['active'] && $this->alerts === $counts) {
            $this->skipRender();

            return;
        }
        $this->active = $state['active'];
        $this->alerts = $counts;
        $this->dispatch('cash-box-correction-alerts-updated', accessId: $this->accessId, unread: $counts['unread'], returned: $counts['returned']);
    }

    #[On('cash-box-correction-changed')]
    public function refreshAfterChange(int $accessId): void
    {
        if ($accessId !== $this->accessId) {
            $this->skipRender();

            return;
        }
        $this->refreshAlerts();
    }

    private function readState(): array
    {
        try {
            $user = auth()->user();

            return $user ? app(CashBoxCorrectionAlertsQuery::class)->execute($user, $this->accessId) : ['active' => false, 'unread' => 0, 'returned' => 0];
        } catch (AuthorizationException|ModelNotFoundException) {
            return ['active' => false, 'unread' => 0, 'returned' => 0];
        }
    }

    public function render(): View
    {
        return view('livewire.cajas.correction-alerts');
    }
}
