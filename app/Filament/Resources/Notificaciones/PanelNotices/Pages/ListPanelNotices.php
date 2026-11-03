<?php

namespace App\Filament\Resources\Notificaciones\PanelNotices\Pages;

use App\Filament\Resources\Notificaciones\PanelNotices\PanelNoticeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Livewire\Attributes\On;

class ListPanelNotices extends ListRecords
{
    protected static string $resource = PanelNoticeResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): ?string
    {
        return 'Avisos privados para tu cuenta. Se actualizan automáticamente mientras esta pestaña está visible.';
    }

    #[On('panel-inbox-refresh')]
    public function refreshInbox(): void
    {
        $this->flushCachedTableRecords();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('actualizar')->label('Actualizar')->icon('heroicon-o-arrow-path')->action(fn () => $this->resetTable())];
    }
}
