<?php

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Configuraciones\Actions\InitializeEmptyInstallationAction;
use App\Domain\Inicio\Queries\HomeDashboardQuery;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
it('initializes only Giovanni three configured boxes and the card catalog without business movements', function () {
    Http::preventStrayRequests();
    $user = app(InitializeEmptyInstallationAction::class)->execute('giovanni@example.test', 'Test-only-password-1234');
    expect(User::count())->toBe(1)->and($user->name)->toBe('Giovanni')->and($user->hasRole('administrador'))->toBeTrue()->and($user->can('panel.acceder'))->toBeTrue()->and(Hash::check('Test-only-password-1234', $user->password))->toBeTrue();
    $technical = ['migrations', 'users', 'permissions', 'roles', 'model_has_roles', 'role_has_permissions', 'cash_box_types', 'cash_boxes', 'cash_box_accounts', 'cash_box_configurations', 'cash_box_audits', 'card_products', 'card_stores', 'card_stocks'];
    foreach (Schema::getTableListing() as $table) {
        $name = basename(str_replace('.', '/', $table));
        if (! in_array($name, $technical, true) && ! str_starts_with($name, 'sqlite_')) {
            expect(DB::table($name)->count())->toBe(0);
        }
    }
    expect(CardProduct::count())->toBe(16)->and(DB::table('card_stocks')->sum('quantity'))->toBe(0)->and(DB::table('card_stores')->sum('cash_balance_cents'))->toBe(0);
    expect(DB::table('card_stores')->value('initialized_at'))->toBeNull()->and($user->pin_hash)->toBeNull();
    expect(CashBox::orderBy('code')->get(['code', 'name', 'base_capital_cents', 'is_active'])->toArray())->toBe([
        ['code' => 'BCP_1', 'name' => 'Tablet Blanca', 'base_capital_cents' => 1600000, 'is_active' => true],
        ['code' => 'BCP_2', 'name' => 'Tablet Negra', 'base_capital_cents' => 1600000, 'is_active' => true],
        ['code' => 'PTM', 'name' => 'Tigo Money', 'base_capital_cents' => 1000000, 'is_active' => true],
    ]);
    expect(CashBoxConfiguration::count())->toBe(3);
    foreach (CashBox::with('latestConfiguration')->get() as $box) {
        expect($box->latestConfiguration->first_count_pending)->toBeTrue();
        expect($box->latestConfiguration->base_capital_cents)->toBe($box->base_capital_cents);
        expect(array_sum(array_column($box->latestConfiguration->accounts, 'initial_cents')))->toBe(0);
    }
    $this->assertDatabaseCount('cash_box_audits', 6);
    $rows = json_decode(file_get_contents(database_path('seeders/Tarjetas/initial-card-catalog.json')), true);
    foreach ($rows as $row) {
        $p = CardProduct::where('company', $row['company'])->where('face_value_cents', $row['face_value_cents'])->sole();
        expect($p->purchase_price_cents)->toBe($row['purchase_price_cents'])->and($p->wholesale_price_cents)->toBe($row['wholesale_price_cents'])->and($p->normal_price_cents)->toBe($row['normal_price_cents']);
    }
    $home = app(HomeDashboardQuery::class)->execute($user);
    expect($home['operations']['boxes']['total'])->toBe(3);
    expect(collect($home['operations']['boxes']['rows'])->firstWhere('label', 'Sin apertura')['value'])->toBe(3);
    expect($home['operations']['loans']['total'])->toBe(0);
    expect($home['operations']['cards']['initialized'])->toBeFalse();
    expect($home['has_activity'])->toBeFalse();
    expect(Schema::hasIndex('cash_box_closes', 'cash_close_mysql_fk_support'))->toBeFalse()
        ->and(Schema::hasIndex('cash_box_closes', 'cash_box_closes_session_active_unique'))->toBeTrue();
    Http::assertNothingSent();
});
it('C374 refuses retries existing users or invalid passwords without changing data', function () {
    $a = app(InitializeEmptyInstallationAction::class);
    expect(fn () => $a->execute('invalid', 'short'))->toThrow(ValidationException::class);
    expect(User::count())->toBe(0)->and(CardProduct::count())->toBe(0);
    $u = $a->execute('giovanni@example.test', 'Test-only-password-1234');
    $hash = $u->password;
    expect(fn () => $a->execute('other@example.test', 'Different-test-password'))->toThrow(ValidationException::class);
    expect(User::sole()->password)->toBe($hash)->and(CardProduct::count())->toBe(16);
    expect(CashBox::count())->toBe(3)->and(CashBoxConfiguration::count())->toBe(3);
});
it('C374 rolls back an interrupted catalog installation and permits a safe retry', function () {
    if (DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('SQLite fault injection only');
    }
    DB::unprepared("CREATE TRIGGER fail_install BEFORE INSERT ON card_products BEGIN SELECT RAISE(ABORT, 'simulated interruption'); END");
    expect(fn () => app(InitializeEmptyInstallationAction::class)->execute('giovanni@example.test', 'Test-only-password-1234'))->toThrow(QueryException::class);
    expect(User::count())->toBe(0)->and(CardProduct::count())->toBe(0);
    expect(CashBox::count())->toBe(0)->and(CashBoxConfiguration::count())->toBe(0);
    $this->assertDatabaseCount('cash_box_audits', 0);
    $this->assertDatabaseCount('cash_box_accounts', 0);
    DB::unprepared('DROP TRIGGER fail_install');
    app(InitializeEmptyInstallationAction::class)->execute('giovanni@example.test', 'Test-only-password-1234');
    expect(User::count())->toBe(1)->and(CardProduct::count())->toBe(16);
    expect(CashBox::count())->toBe(3);
});
it('C374 general seeding no longer creates a public test user', function () {
    test()->seed();
    expect(User::count())->toBe(0);
});
it('C374 blocks unattended installation rather than accepting credentials in arguments', function () {
    test()->artisan('planeta:instalar', ['--no-interaction' => true])->assertFailed();
    expect(User::count())->toBe(0);
});

it('C374 interactive installer confirms private credentials and initializes once', function () {
    test()->artisan('planeta:instalar')
        ->expectsQuestion('Correo de acceso para Giovanni', 'giovanni@example.test')
        ->expectsQuestion('Contraseña nueva (mínimo 12 caracteres)', 'Test-only-password-1234')
        ->expectsQuestion('Repite la contraseña', 'Test-only-password-1234')
        ->assertSuccessful();
    expect(User::sole()->name)->toBe('Giovanni')->and(CardProduct::count())->toBe(16);
});

it('C374 refuses mismatched confirmation without creating data', function () {
    test()->artisan('planeta:instalar', ['--email' => 'giovanni@example.test'])
        ->expectsQuestion('Contraseña nueva (mínimo 12 caracteres)', 'Test-only-password-1234')
        ->expectsQuestion('Repite la contraseña', 'Different-test-password')
        ->assertFailed();
    expect(User::count())->toBe(0)->and(CardProduct::count())->toBe(0);
});

it('C374 preserves a database containing business records even without users', function () {
    DB::table('card_stores')->insert(['code' => 'EXISTING', 'cash_balance_cents' => 17500, 'revision' => 0]);
    expect(fn () => app(InitializeEmptyInstallationAction::class)->execute('giovanni@example.test', 'Test-only-password-1234'))
        ->toThrow(ValidationException::class);
    expect(User::count())->toBe(0)->and(DB::table('card_stores')->value('cash_balance_cents'))->toBe(17500);
});

it('C374 bounded Telegram worker requires both hosting and transport flags', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'queue:work telegram'));
    expect($event)->not->toBeNull();
    foreach ([[false, false, false], [true, false, false], [false, true, false], [true, true, true]] as [$worker, $transport, $expected]) {
        config(['telegram.scheduled_worker_enabled' => $worker, 'telegram.sending_enabled' => $transport]);
        expect($event->filtersPass(app()))->toBe($expected);
    }
});

it('C374 preserves short index names and produces stable distinct MySQL names within 64 bytes', function () {
    $connection = new MySqlConnection(fn () => throw new RuntimeException('No network in this test'), 'test', '', ['driver' => 'mysql']);
    $connection->useDefaultSchemaGrammar();
    $blueprint = app(Blueprint::class, ['connection' => $connection, 'table' => 'accessory_product_price_changes']);
    $name = $blueprint->unique(['product_id', 'previous_revision'])->index;
    expect(strlen($name))->toBeLessThanOrEqual(64)
        ->and($blueprint->dropUnique(['product_id', 'previous_revision'])->index)->toBe($name)
        ->and($blueprint->unique(['product_id', 'another_revision'])->index)->not->toBe($name)
        ->and($blueprint->index('id')->index)->toBe('accessory_product_price_changes_id_index');
});
