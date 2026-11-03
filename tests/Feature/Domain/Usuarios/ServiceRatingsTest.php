<?php

use App\Domain\Usuarios\Actions\RecordServiceRatingAction;
use App\Domain\Usuarios\Enums\ServiceRatingValue;
use App\Domain\Usuarios\Models\ServiceRating;
use App\Filament\Resources\Usuarios\Users\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function ratedCashier(string $role = 'cajera'): User
{
    app(UsuariosPermissionsSeeder::class)->run();
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('records each supported face for the attributed cashier with server time', function (ServiceRatingValue $value) {
    $cashier = ratedCashier();
    $this->freezeTime();
    $rating = app(RecordServiceRatingAction::class)->execute($cashier, $value, (string) Str::uuid());
    expect($rating->value)->toBe($value);
    expect($rating->cashier_user_id)->toBe($cashier->id);
    expect($rating->recorded_at->timestamp === now()->timestamp)->toBeTrue();
    expect($rating->cashier->is($cashier))->toBeTrue();
})->with(ServiceRatingValue::cases());

it('does not duplicate a rating when a submission is retried', function () {
    $cashier = ratedCashier();
    $reference = (string) Str::uuid();
    $first = app(RecordServiceRatingAction::class)->execute($cashier, ServiceRatingValue::Good, $reference);
    $second = app(RecordServiceRatingAction::class)->execute($cashier, ServiceRatingValue::Good, $reference);
    expect($second->id)->toBe($first->id);
    expect(ServiceRating::count())->toBe(1);
});

it('rejects reuse of a submission for another value or cashier', function (bool $otherCashier) {
    $cashier = ratedCashier();
    $reference = (string) Str::uuid();
    app(RecordServiceRatingAction::class)->execute($cashier, ServiceRatingValue::Good, $reference);
    $target = $otherCashier ? ratedCashier() : $cashier;
    expect(fn () => app(RecordServiceRatingAction::class)->execute($target, ServiceRatingValue::Bad, $reference))
        ->toThrow(ValidationException::class);
    expect(ServiceRating::count())->toBe(1);
})->with([true, false]);

it('refuses inactive cashiers and users outside the cashier role', function (string $role, bool $active) {
    $cashier = ratedCashier($role);
    $cashier->is_active = $active;
    $cashier->save();
    expect(fn () => app(RecordServiceRatingAction::class)->execute($cashier, ServiceRatingValue::Good, (string) Str::uuid()))
        ->toThrow(HttpException::class);
    expect(ServiceRating::count())->toBe(0);
})->with([['cajera', false], ['administrador', true], ['encargado', true]]);

it('shows the real rating count in user cards without inventing a quality score', function () {
    $admin = ratedCashier('administrador');
    $cashier = ratedCashier();
    app(RecordServiceRatingAction::class)->execute($cashier, ServiceRatingValue::Good, (string) Str::uuid());
    app(RecordServiceRatingAction::class)->execute($cashier, ServiceRatingValue::Bad, (string) Str::uuid());
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(ListUsers::class)->searchTable($cashier->email)->assertSee('2 calificaciones')->assertSee('Buenas: 1 · Regulares: 0 · Malas: 1');
});
