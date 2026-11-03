<?php

namespace App\Livewire\Navigation;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;

class Sidebar extends \Filament\Livewire\Sidebar
{
    #[On('panel-inbox-refresh')]
    public function refreshNotices(): void {}

    public function render(): View
    {
        return view('filament.navigation.sidebar');
    }
}
