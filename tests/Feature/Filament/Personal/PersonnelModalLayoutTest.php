<?php

use App\Filament\Support\Personal\PersonnelModal;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

it('organizes modal fields into responsive cards without nesting their state', function () {
    $cards = PersonnelModal::fields([
        TextInput::make('amount'),
        Textarea::make('reason'),
    ]);
    expect($cards)->toHaveCount(2);
    expect($cards[0])->toBeInstanceOf(Section::class);
    expect($cards[1])->toBeInstanceOf(Section::class);
    expect($cards[0]->getStatePath(isAbsolute: false))->toBeNull();
    expect($cards[1]->getStatePath(isAbsolute: false))->toBeNull();
});

it('uses Spanish framework and Filament controls with published production translations', function () {
    expect(app()->getLocale())->toBe('es');
    expect(__('auth.failed'))->toBe('Estas credenciales no coinciden con nuestros registros.');
    expect(__('pagination.next'))->toContain('Siguiente');
    expect(__('filament-actions::modal.actions.cancel.label'))->toBe('Cancelar');
    expect(validator(['email' => 'incorrecto'], ['email' => 'email'])->errors()->first('email'))
        ->not->toContain('must be');
});
