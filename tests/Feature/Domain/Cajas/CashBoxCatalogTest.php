<?php

use App\Domain\Cajas\Actions\CreateCashBoxAction;
use App\Domain\Cajas\Actions\UpdateCashBoxDetailsAction;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Models\User;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class]);
});

function cashBoxAdmin(string $role = 'administrador'): User
{
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole($role);

    return $user;
}

function cashBoxCreation(array $replace = []): array
{
    return array_replace(['code' => ' bcp-01 ', 'name' => 'Caja principal', 'type' => 'BCP', 'base_capital' => '20000.50',
        'is_active' => true, 'pin' => '1234', 'reason' => 'Registro inicial autorizado', 'request_key' => (string) Str::uuid()], $replace);
}

function cashBoxUpdate(CashBox $box, array $replace = []): array
{
    return array_replace(['name' => $box->name, 'is_active' => false, 'expected_revision' => $box->revision,
        'pin' => '1234', 'reason' => 'Retirada temporal de servicio', 'request_key' => (string) Str::uuid()], $replace);
}

it('registers exact base capital and a private audit without opening a session', function () {
    $admin = cashBoxAdmin();
    $box = app(CreateCashBoxAction::class)->execute($admin, cashBoxCreation());
    expect($box->code)->toBe('BCP-01')->and($box->base_capital_cents)->toBe(2000050)
        ->and($box->is_active)->toBeTrue()->and($box->revision)->toBe(1);
    $audit = $box->audits()->sole();
    expect($audit->actor_user_id)->toBe($admin->id)->and($audit->before)->toBeNull()
        ->and($audit->after['base_capital_cents'])->toBe(2000050)
        ->and(json_encode($audit->toArray()))->not->toContain('1234', 'pin_hash');
});

it('supports zero capital and separates Tigo Money from BCP', function () {
    $box = app(CreateCashBoxAction::class)->execute(cashBoxAdmin(), cashBoxCreation(['type' => 'TIGO_MONEY', 'base_capital' => '0']));
    expect($box->type)->toBe('TIGO_MONEY')->and($box->base_capital_cents)->toBe(0);
});

it('does not duplicate creation after a lost response or retry', function () {
    $admin = cashBoxAdmin();
    $data = cashBoxCreation();
    $first = app(CreateCashBoxAction::class)->execute($admin, $data);
    $retry = app(CreateCashBoxAction::class)->execute($admin, $data);
    expect($retry->id)->toBe($first->id);
    $this->assertDatabaseCount('cash_boxes', 1);
    $this->assertDatabaseCount('cash_box_audits', 1);
});

it('rejects reuse of a request key with different data', function () {
    $admin = cashBoxAdmin();
    $data = cashBoxCreation();
    app(CreateCashBoxAction::class)->execute($admin, $data);
    expect(fn () => app(CreateCashBoxAction::class)->execute($admin, array_replace($data, ['base_capital' => '500'])))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_boxes', 1);
});

it('prevents a different administrator from reusing another request', function () {
    $data = cashBoxCreation();
    app(CreateCashBoxAction::class)->execute(cashBoxAdmin(), $data);
    expect(fn () => app(CreateCashBoxAction::class)->execute(cashBoxAdmin(), $data))->toThrow(AuthorizationException::class);
});

it('requires administrative role even with a directly granted permission', function (string $role) {
    $user = cashBoxAdmin($role);
    $user->givePermissionTo('cajas.catalogo.gestionar');
    expect(fn () => app(CreateCashBoxAction::class)->execute($user, cashBoxCreation()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('cash_boxes', 0);
})->with(['cajera', 'encargado']);

it('honors individual restrictions and fresh permissions on retries', function () {
    $admin = cashBoxAdmin();
    $data = cashBoxCreation();
    app(CreateCashBoxAction::class)->execute($admin, $data);
    $admin->restrictedPermissions()->attach(Permission::findByName('cajas.catalogo.gestionar', 'web'));
    expect(fn () => app(CreateCashBoxAction::class)->execute($admin, $data))->toThrow(AuthorizationException::class);
});

it('rejects an incorrect PIN before changing the catalog', function () {
    expect(fn () => app(CreateCashBoxAction::class)->execute(cashBoxAdmin(), cashBoxCreation(['pin' => '9876'])))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_boxes', 0);
    $this->assertDatabaseCount('cash_box_audits', 0);
});

it('rejects invalid catalog values without partial writes', function (array $invalid) {
    expect(fn () => app(CreateCashBoxAction::class)->execute(cashBoxAdmin(), cashBoxCreation($invalid)))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_boxes', 0);
})->with([
    'negative capital' => [['base_capital' => '-1']], 'extra decimals' => [['base_capital' => '10.001']],
    'float payload' => [['base_capital' => 20.5]], 'ambiguous thousands' => [['base_capital' => '20,000']],
    'unsupported type' => [['type' => 'OTRA']], 'empty name' => [['name' => '   ']],
    'invalid code' => [['code' => 'CAJA BCP']], 'blank reason' => [['reason' => '   ']],
    'array name' => [['name' => ['invalid']]],
]);

it('rejects duplicate codes after normalization', function () {
    $admin = cashBoxAdmin();
    app(CreateCashBoxAction::class)->execute($admin, cashBoxCreation());
    expect(fn () => app(CreateCashBoxAction::class)->execute($admin, cashBoxCreation(['code' => 'bcp-01'])))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_boxes', 1);
});

it('deactivates and reactivates without deleting or replacing capital', function () {
    $admin = cashBoxAdmin();
    $box = app(CreateCashBoxAction::class)->execute($admin, cashBoxCreation());
    $data = cashBoxUpdate($box);
    $off = app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, $data);
    $retry = app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, $data);
    expect($off->is_active)->toBeFalse()->and($retry->revision)->toBe(2);
    $on = app(UpdateCashBoxDetailsAction::class)->execute($admin, $off, cashBoxUpdate($off, ['is_active' => true]));
    expect($on->is_active)->toBeTrue()->and($on->base_capital_cents)->toBe(2000050)->and($on->revision)->toBe(3);
    $this->assertDatabaseCount('cash_box_audits', 3);
    expect(Gate::forUser($admin)->allows('delete', $on))->toBeFalse();
});

it('does not overwrite a newer change from another open form', function () {
    $admin = cashBoxAdmin();
    $box = app(CreateCashBoxAction::class)->execute($admin, cashBoxCreation());
    $stale = cashBoxUpdate($box, ['name' => 'Nombre anterior']);
    app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, cashBoxUpdate($box, ['name' => 'Nombre vigente']));
    expect(fn () => app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, $stale))->toThrow(ValidationException::class);
    expect($box->fresh()->name)->toBe('Nombre vigente');
    $this->assertDatabaseCount('cash_box_audits', 2);
});

it('does not expose a generic balance or capital editor through details', function () {
    $admin = cashBoxAdmin();
    $box = CashBox::factory()->create();
    expect(fn () => app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, cashBoxUpdate($box, ['base_capital_cents' => 1])))
        ->toThrow(ValidationException::class);
    expect($box->fresh()->base_capital_cents)->toBe(2000000);
});

it('rolls back catalog creation if its audit cannot be persisted', function () {
    $admin = cashBoxAdmin();
    CashBoxAudit::creating(fn () => throw new RuntimeException('Audit interrupted'));
    try {
        expect(fn () => app(CreateCashBoxAction::class)->execute($admin, cashBoxCreation()))->toThrow(RuntimeException::class);
    } finally {
        CashBoxAudit::flushEventListeners();
    }
    $this->assertDatabaseCount('cash_boxes', 0);
    $this->assertDatabaseCount('cash_box_audits', 0);
});

it('rolls back an update if its audit fails and permits retry afterwards', function () {
    $admin = cashBoxAdmin();
    $box = app(CreateCashBoxAction::class)->execute($admin, cashBoxCreation());
    $data = cashBoxUpdate($box);
    CashBoxAudit::creating(fn () => throw new RuntimeException('Audit interrupted'));
    try {
        expect(fn () => app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, $data))->toThrow(RuntimeException::class);
    } finally {
        CashBoxAudit::flushEventListeners();
    }
    expect($box->fresh()->is_active)->toBeTrue()->and($box->fresh()->revision)->toBe(1);
    $retry = app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, $data);
    expect($retry->is_active)->toBeFalse();
    $this->assertDatabaseCount('cash_box_audits', 2);
});

it('does not restore role grants deliberately revoked by administration when reseeding', function () {
    $role = Role::findByName('administrador', 'web');
    $role->revokePermissionTo('cajas.catalogo.gestionar');
    $this->seed(CajasPermissionsSeeder::class);
    expect($role->fresh()->hasPermissionTo('cajas.catalogo.gestionar'))->toBeFalse();
});

it('rejects an inactive administrator even when a stale actor still has grants', function () {
    $admin = cashBoxAdmin();
    User::whereKey($admin->id)->update(['is_active' => false]);
    expect(fn () => app(CreateCashBoxAction::class)->execute($admin, cashBoxCreation()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('cash_boxes', 0);
});

it('checks the manage permission again for updates', function () {
    $admin = cashBoxAdmin();
    $box = CashBox::factory()->create();
    $admin->restrictedPermissions()->attach(Permission::findByName('cajas.catalogo.gestionar', 'web'));
    expect(fn () => app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, cashBoxUpdate($box)))->toThrow(AuthorizationException::class);
    expect($box->fresh()->is_active)->toBeTrue();
});

it('rejects a changed update payload with the same key instead of silently reverting data', function () {
    $admin = cashBoxAdmin();
    $box = CashBox::factory()->create();
    $data = cashBoxUpdate($box);
    app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, $data);
    expect(fn () => app(UpdateCashBoxDetailsAction::class)->execute($admin, $box, array_replace($data, ['name' => 'Otro nombre'])))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_audits', 1);
    expect($box->fresh()->name)->toBe($box->name);
});

it('enforces the code and idempotency uniqueness in the database', function () {
    $admin = cashBoxAdmin();
    $box = app(CreateCashBoxAction::class)->execute($admin, cashBoxCreation());
    expect(fn () => CashBox::factory()->create(['code' => $box->code]))->toThrow(UniqueConstraintViolationException::class);
    $audit = $box->audits()->sole();
    expect(fn () => CashBoxAudit::create(collect($audit->getAttributes())->except(['id'])->all()))->toThrow(UniqueConstraintViolationException::class);
});
