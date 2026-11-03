<?php

use App\Domain\Tarjetas\Actions\SaveCardWholesaleSelectionAction;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Models\User;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function c158Setup(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole('administrador');
    $ps = [];
    foreach ([1000, 2000] as $cut) {
        $ps[] = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => $cut, 'purchase_price_cents' => $cut, 'normal_price_cents' => $cut, 'wholesale_price_cents' => $cut, 'is_active' => true, 'wholesale_enabled' => $cut === 1000, 'revision' => 1, 'created_by' => $u->id]);
    }

    return [$u, ...$ps];
}
function c158Data($a, $b): array
{
    return ['enabled' => ['ENTEL' => [], 'TIGO' => [$b->id], 'VIVA' => []], 'revisions' => [$a->id => 1, $b->id => 1], 'request_key' => (string) Str::uuid()];
}
it('C158 saves selected cuts atomically and retries without changing prices or revision twice', function () {
    [$u,$a,$b] = c158Setup();
    $data = c158Data($a, $b);
    $action = app(SaveCardWholesaleSelectionAction::class);
    $action->execute($u, $data);
    $action->execute($u, $data);
    expect($a->fresh()->wholesale_enabled)->toBeFalse()->and($b->fresh()->wholesale_enabled)->toBeTrue()->and($b->fresh()->revision)->toBe(2)->and($b->fresh()->normal_price_cents)->toBe(2000)->and(CardInventoryCommand::count())->toBe(1);
});

it('C158 refuses stale selections and wrong company ids without partially updating flags', function () {
    [$u,$a,$b] = c158Setup();
    $action = app(SaveCardWholesaleSelectionAction::class);
    $data = c158Data($a, $b);
    $b->update(['revision' => 2]);
    expect(fn () => $action->execute($u, $data))->toThrow(ValidationException::class);
    $b->update(['revision' => 1]);
    $data['enabled']['ENTEL'] = [$b->id];
    expect(fn () => $action->execute($u, $data))->toThrow(ValidationException::class);
    expect($a->fresh()->wholesale_enabled)->toBeTrue()->and($b->fresh()->wholesale_enabled)->toBeFalse()->and(CardInventoryCommand::count())->toBe(0);
});
it('C158 allows no wholesale cuts and rejects another actor or altered retry', function () {
    [$u,$a,$b] = c158Setup();
    $data = c158Data($a, $b);
    $data['enabled']['TIGO'] = [];
    $action = app(SaveCardWholesaleSelectionAction::class);
    $action->execute($u, $data);
    expect(CardProduct::where('wholesale_enabled', true)->count())->toBe(0);
    expect(fn () => $action->execute($u, array_replace($data, ['enabled' => ['ENTEL' => [], 'TIGO' => [$a->id], 'VIVA' => []]])))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $action->execute($other, $data))->toThrow(AuthorizationException::class);
});
it('C158 denies operational roles and inactive administrators', function (string $role) {
    [$u,$a,$b] = c158Setup();
    $u->syncRoles([$role]);
    if ($role === 'administrador') {
        $u->forceFill(['is_active' => false])->save();
    }
    expect(fn () => app(SaveCardWholesaleSelectionAction::class)->execute($u, c158Data($a, $b)))->toThrow(AuthorizationException::class);
    expect(CardInventoryCommand::count())->toBe(0);
})->with(['cajera', 'encargado', 'administrador']);
it('C158 rolls back every product if the final audit fails then accepts a single retry', function () {
    [$u,$a,$b] = c158Setup();
    $data = c158Data($a, $b);
    $action = app(SaveCardWholesaleSelectionAction::class);
    DB::unprepared("CREATE TRIGGER c158_fail BEFORE INSERT ON card_inventory_commands BEGIN SELECT RAISE(ABORT,'test interrupted'); END");
    expect(fn () => $action->execute($u, $data))->toThrow(QueryException::class);
    expect($a->fresh()->wholesale_enabled)->toBeTrue()->and($b->fresh()->revision)->toBe(1);
    DB::unprepared('DROP TRIGGER c158_fail');
    $action->execute($u, $data);
    $action->execute($u, $data);
    expect(CardInventoryCommand::count())->toBe(1);
});
