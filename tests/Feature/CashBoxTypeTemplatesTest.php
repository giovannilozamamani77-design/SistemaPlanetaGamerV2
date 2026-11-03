<?php

use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\CreateCashBoxAction;
use App\Domain\Cajas\Actions\SaveCashBoxTypeAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxType;
use App\Filament\Resources\Cajas\CashBoxes\Pages\ListCashBoxes;
use App\Filament\Resources\Cajas\CashBoxes\Schemas\CashBoxConfigurationForm;
use App\Filament\Resources\Cajas\Types\CashBoxTypeResource;
use App\Filament\Resources\Cajas\Types\Pages\ListCashBoxTypes;
use App\Models\User;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c4Admin(): User
{
    test()->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class]);
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole('administrador');

    return $user;
}
function c4Data(array $replace = []): array
{
    return array_replace(['request_key' => (string) Str::uuid(), 'pin' => '1234', 'reason' => 'Configuración confirmada de prueba'], $replace);
}
function c4Box(User $admin, string $type = 'TIGO_MONEY')
{
    return app(CreateCashBoxAction::class)->execute($admin, c4Data(['code' => 'C4-'.Str::upper(Str::random(8)), 'name' => 'Caja independiente', 'type' => $type, 'base_capital' => '20000', 'is_active' => true]));
}

it('copies the complete Tigo template into a new box without inventing balances', function () {
    $admin = c4Admin();
    $box = c4Box($admin);
    $accounts = DB::table('cash_box_accounts')->where('cash_box_id', $box->id)->orderBy('position')->get();
    expect($accounts->pluck('code')->all())->toBe(['efectivo', 'tigo_money', 'yape', 'recarga_entel', 'paquetes_entel', 'tigo_75885195', 'tigo_75249579', 'recarga_viva']);
    expect($accounts->pluck('reference', 'code')['tigo_75885195'])->toBe('75885195');
    $this->assertDatabaseCount('cash_box_configurations', 0);
    $this->assertDatabaseCount('cash_box_sessions', 0);
});

it('creates BCP boxes with independent predefined accounts', function () {
    $admin = c4Admin();
    $a = c4Box($admin, 'BCP');
    $b = c4Box($admin, 'BCP');
    expect(DB::table('cash_box_accounts')->where('cash_box_id', $a->id)->pluck('code')->all())->toBe(['bcp', 'efectivo', 'yape']);
    DB::table('cash_box_accounts')->where('cash_box_id', $a->id)->where('code', 'bcp')->update(['name' => 'BCP personalizada']);
    expect(DB::table('cash_box_accounts')->where('cash_box_id', $b->id)->where('code', 'bcp')->value('name'))->toBe('Cuenta BCP');
});

it('saves template changes idempotently without changing existing box accounts', function () {
    $admin = c4Admin();
    $box = c4Box($admin);
    $type = CashBoxType::where('code', 'TIGO_MONEY')->firstOrFail();
    $components = $type->components;
    $components[] = ['code' => 'recarga_nueva', 'name' => 'Nueva recarga', 'category' => 'recarga', 'reference' => null, 'required' => false];
    $data = c4Data(['code' => $type->code, 'name' => $type->name, 'family' => $type->family, 'is_active' => true, 'components' => $components, 'expected_revision' => $type->revision]);
    $saved = app(SaveCashBoxTypeAction::class)->execute($admin, $data);
    expect(app(SaveCashBoxTypeAction::class)->execute($admin, $data)->revision)->toBe($saved->revision);
    expect(DB::table('cash_box_accounts')->where('cash_box_id', $box->id)->count())->toBe(8);
    expect(DB::table('cash_box_accounts')->where('cash_box_id', c4Box($admin)->id)->count())->toBe(9);
    $this->assertDatabaseCount('cash_box_type_audits', 1);
});

function c4Settings($box): array
{
    $rows = [];
    foreach (DB::table('cash_box_accounts')->where('cash_box_id', $box->id)->orderBy('position')->get() as $row) {
        $rows[$row->code] = ['name' => $row->name, 'category' => $row->category, 'reference' => $row->reference, 'initial' => match ($row->code) {
            'efectivo' => '1000','tigo_money' => '5000','yape' => '2000','recarga_entel' => '500','paquetes_entel' => '500','tigo_75885195' => '500','tigo_75249579' => '500',default => '0'
        }];
    }

    return c4Data(['accounts' => $rows, 'shifts' => CashBoxComponents::defaultShifts(), 'base_capital' => '10000', 'expected_revision' => $box->revision]);
}
it('rejects altered replays and stale template revisions', function () {
    $admin = c4Admin();
    $type = CashBoxType::where('code', 'BCP')->firstOrFail();
    $data = c4Data($type->only(['code', 'name', 'family', 'is_active', 'components']) + ['expected_revision' => 1]);
    app(SaveCashBoxTypeAction::class)->execute($admin, $data);
    expect(fn () => app(SaveCashBoxTypeAction::class)->execute($admin, array_replace($data, ['name' => 'Otro nombre'])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCashBoxTypeAction::class)->execute($admin, array_replace($data, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
});

it('denies template administration even to a cashier with direct catalog permission', function () {
    c4Admin();
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole('cajera');
    $user->givePermissionTo('cajas.catalogo.gestionar');
    expect(fn () => app(SaveCashBoxTypeAction::class)->execute($user, []))->toThrow(AuthorizationException::class);
});

it('rolls back a template change if its audit cannot be saved and permits a retry', function () {
    $admin = c4Admin();
    $type = CashBoxType::where('code', 'BCP')->firstOrFail();
    $data = c4Data($type->only(['code', 'name', 'family', 'is_active', 'components']) + ['expected_revision' => 1]);
    $fail = true;
    DB::listen(function ($query) use (&$fail) {
        if ($fail && str_contains($query->sql, 'insert into "cash_box_type_audits"')) {
            $fail = false;
            throw new RuntimeException('Interrupción simulada');
        }
    });
    expect(fn () => app(SaveCashBoxTypeAction::class)->execute($admin, $data))->toThrow(RuntimeException::class);
    expect($type->fresh()->revision)->toBe(1);
    $this->assertDatabaseCount('cash_box_type_audits', 0);
    expect(app(SaveCashBoxTypeAction::class)->execute($admin, $data)->revision)->toBe(2);
});

it('uses a custom type with its approved financial family and refuses inactive types for new boxes', function () {
    $admin = c4Admin();
    $base = CashBoxType::where('code', 'BCP')->firstOrFail();
    $type = app(SaveCashBoxTypeAction::class)->execute($admin, c4Data(['code' => 'BCP_SUCURSAL', 'name' => 'BCP sucursal', 'family' => 'BCP', 'is_active' => true, 'components' => $base->components, 'expected_revision' => 0]));
    expect(c4Box($admin, $type->code)->family())->toBe('BCP');
    $type->update(['is_active' => false]);
    expect(fn () => c4Box($admin, $type->code))->toThrow(ValidationException::class);
});

it('opens the administrative template page and edits through its floating form', function () {
    $admin = c4Admin();
    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->get(CashBoxTypeResource::getUrl())->assertOk()->assertSee('Tipos y plantillas de cajas');
    $type = CashBoxType::where('code', 'TIGO_MONEY')->firstOrFail();
    Livewire::test(ListCashBoxTypes::class)->callTableAction('editar', $type, data: c4Data(['name' => 'Tigo sucursal', 'code' => $type->code, 'family' => $type->family, 'is_active' => true, 'components' => $type->components, 'expected_revision' => $type->revision]))->assertHasNoActionErrors();
    expect($type->fresh()->name)->toBe('Tigo sucursal');
});

it('refuses the template page to a cashier and never offers a delete action', function () {
    c4Admin();
    $user = User::factory()->create();
    $user->assignRole('cajera');
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->get(CashBoxTypeResource::getUrl())->assertForbidden();
    expect(CashBoxTypeResource::canDelete(CashBoxType::first()))->toBeFalse();
});

it('configures all Tigo template balances and references through the existing modal', function () {
    $admin = c4Admin();
    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $box = c4Box($admin);
    Livewire::test(ListCashBoxes::class)->callTableAction('configurar', $box, data: c4Settings($box))->assertHasNoActionErrors();
    $config = CashBoxConfiguration::where('cash_box_id', $box->id)->firstOrFail();
    expect($config->accounts)->toHaveCount(8)->and($config->accounts['recarga_entel']['initial_cents'])->toBe(50000);
});

it('rejects a recharge as liquidation source and duplicate component codes', function () {
    $admin = c4Admin();
    $box = c4Box($admin);
    $data = c4Settings($box);
    $data['settlement_source'] = 'recarga_entel';
    expect(fn () => app(ConfigureCashBoxAction::class)->execute($admin, $box, $data))->toThrow(ValidationException::class);
    $type = CashBoxType::where('code', 'BCP')->firstOrFail();
    $components = $type->components;
    $components[] = $components[0];
    expect(fn () => app(SaveCashBoxTypeAction::class)->execute($admin, c4Data($type->only(['code', 'name', 'family', 'is_active']) + ['expected_revision' => 1, 'components' => $components])))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_configurations', 0);
});

it('rejects an explicitly supplied invalid legacy PIN when changing a template', function () {
    $admin = c4Admin();
    $type = CashBoxType::where('code', 'BCP')->firstOrFail();
    expect(fn () => app(SaveCashBoxTypeAction::class)->execute($admin, c4Data($type->only(['code', 'name', 'family', 'is_active', 'components']) + ['expected_revision' => 1, 'pin' => '9999'])))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_type_audits', 0);
});

it('reuses the account definitions while preparing fields and values in one request', function () {
    $admin = c4Admin();
    $box = c4Box($admin);
    $reads = 0;
    DB::listen(function ($query) use (&$reads) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"cash_box_accounts"')) {
            $reads++;
        }
    });
    CashBoxConfigurationForm::fields($box);
    CashBoxConfigurationForm::values($box);
    CashBoxConfigurationForm::fields($box);
    expect($reads)->toBe(1);
});
