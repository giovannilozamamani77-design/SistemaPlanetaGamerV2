<?php

use App\Domain\Accesorios\Actions\RegisterPhoneUnitAction;
use App\Domain\Accesorios\Actions\ReleasePhoneReservationAction;
use App\Domain\Accesorios\Actions\ReservePhoneAction;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PhoneVariant;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function reservationAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
function reservationPhone(User $u): PhoneUnit
{
    return app(RegisterPhoneUnitAction::class)->execute($u, ['brand' => 'Samsung', 'model' => 'Galaxy', 'ram_gb' => 8, 'storage_gb' => 128, 'condition' => 'USED', 'color' => 'Negro', 'imei1' => '012345678901234', 'purchase_cost' => '800', 'expenses' => '0', 'price_mode' => 'MANUAL', 'sale_price' => '950', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()]);
}
it('C264 reserves exclusively and retries without extending or duplicating the same reservation', function () {
    $u = reservationAdmin();
    $p = reservationPhone($u);
    $key = (string) Str::uuid();
    $a = app(ReservePhoneAction::class);
    $r = $a->execute($u, $p->id, $key, 10);
    $this->travel(1)->minutes();
    $again = $a->execute($u, $p->id, $key, 10);
    expect($again->id)->toBe($r->id)->and($again->expires_at->equalTo($r->expires_at))->toBeTrue()->and(PhoneReservation::count())->toBe(1)->and($p->fresh()->status)->toBe('RESERVED');
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(fn () => $a->execute($other, $p->id, (string) Str::uuid(), 10))->toThrow(ValidationException::class);
    expect(PhoneVariant::withCount('availableUnits')->first()->available_units_count)->toBe(0);
});
it('C264 releases on cancellation and keeps history without duplicate changes', function () {
    $u = reservationAdmin();
    $p = reservationPhone($u);
    $key = (string) Str::uuid();
    $r = app(ReservePhoneAction::class)->execute($u, $p->id, $key, 10);
    $release = app(ReleasePhoneReservationAction::class);
    $release->execute($u, $key);
    $release->execute($u, $key);
    expect($r->fresh()->status)->toBe('RELEASED')->and($p->fresh()->status)->toBe('AVAILABLE')->and(PhoneReservation::count())->toBe(1);
});
it('C264 makes expired units available without polling and preserves the expired reservation on reclaim', function () {
    $u = reservationAdmin();
    $p = reservationPhone($u);
    $old = app(ReservePhoneAction::class)->execute($u, $p->id, (string) Str::uuid(), 1);
    $this->travel(1)->minutes();
    expect(PhoneUnit::availableForSale()->count())->toBe(1);
    $new = app(ReservePhoneAction::class)->execute($u, $p->id, (string) Str::uuid(), 1);
    expect($new->id)->not->toBe($old->id)->and($old->fresh()->status)->toBe('EXPIRED')->and($p->fresh()->reservation_id)->toBe($new->id);
});
it('C264 forbids releasing another cashier reservation and reserves no sold unit', function () {
    $u = reservationAdmin();
    $p = reservationPhone($u);
    $key = (string) Str::uuid();
    app(ReservePhoneAction::class)->execute($u, $p->id, $key, 10);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(fn () => app(ReleasePhoneReservationAction::class)->execute($other, $key))->toThrow(AuthorizationException::class);
    expect($p->fresh()->status)->toBe('RESERVED');
    $p->update(['status' => 'SOLD']);
    expect(fn () => app(ReservePhoneAction::class)->execute($u, $p->id, (string) Str::uuid(), 10))->toThrow(ValidationException::class);
});
