<?php

namespace App\Livewire\Navigation;

use Illuminate\Contracts\View\View;

class Topbar extends \Filament\Livewire\Topbar
{
    public function render(): View
    {
        return view('filament.navigation.topbar');
    }
}
