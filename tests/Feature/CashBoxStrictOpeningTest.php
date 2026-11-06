<?php

use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Actions\RecordQuickCashBoxCommissionAction;
use App\Domain\Cajas\Actions\RectifyOpeningSurplusAction;
use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Models\User;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function strictOpeningSetup(bool $firstCount = true): array
{
    app(UsuariosPermissionsSeeder::class)->run();
    app(CajasPermissionsSeeder::class)->run();
    $admin = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $admin->assignRole('administrador');
    $box = CashBox::factory()->create(['type' => 'BCP']);
    app(ConfigureCashBoxAction::class)->execute($admin, $box, ['accounts' => ['bcp' => ['name' => 'BCP', 'initial' => $firstCount ? '' : '16000'], 'efectivo' => ['name' => 'Efectivo', 'initial' => $firstCount ? '' : '0'], 'yape' => ['name' => 'Yape', 'initial' => $firstCount ? '' : '0']], 'shifts' => CashBoxComponents::defaultShifts(), 'base_capital' => '16000', 'expected_revision' => $box->revision, 'pin' => '1234', 'reason' => 'Capital inicial', 'request_key' => (string) Str::uuid()]);
    $access = app(StartCashBoxAccessAction::class)->execute($admin, $box->fresh(), '1234', (string) Str::uuid());

    return [$admin, $box, $access];
}

it('rejects incomplete first counts instead of accepting them as the capital reference', function (string $amount) {
    [$admin, $box, $access] = strictOpeningSetup();
    try {
        app(OpenCashBoxAction::class)->execute($admin, $access->id, ['balances' => ['bcp' => $amount, 'efectivo' => '0', 'yape' => '0'], 'request_key' => (string) Str::uuid()]);
        $this->fail('Expected exact capital validation');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('opening_total');
    }
    $this->assertDatabaseCount('cash_box_sessions', 0);
    $this->assertDatabaseCount('cash_box_receipt_discrepancies', 0);
    $this->assertDatabaseCount('economic_incidents', 0);
})->with(['2000', '13107', '15999.99', '16000.01']);

it('accepts a complete first count distributed across accounts and remains idempotent', function () {
    [$admin, $box, $access] = strictOpeningSetup();
    $data = ['balances' => ['bcp' => '1491.30', 'efectivo' => '13107.50', 'yape' => '1401.20'], 'request_key' => (string) Str::uuid()];
    $one = app(OpenCashBoxAction::class)->execute($admin, $access->id, $data);
    $two = app(OpenCashBoxAction::class)->execute($admin, $access->id, $data);
    expect($one->id)->toBe($two->id)->and(array_sum($one->opening_balances))->toBe(1600000);
});

it('requires exactly the released handover total including unliquidated commissions', function (string $amount, bool $accepted) {
    [$admin, $box, $access] = strictOpeningSetup(false);
    $previous = app(OpenCashBoxAction::class)->execute($admin, $access->id, ['balances' => ['bcp' => '16000', 'efectivo' => '0', 'yape' => '0'], 'request_key' => (string) Str::uuid()]);
    $previous->update(['status' => 'CERRADA', 'active_slot' => null, 'closed_at' => now(), 'released_at' => now(), 'handover_balances' => ['bcp' => 1600000, 'efectivo' => 500, 'yape' => 0]]);
    $next = app(StartCashBoxAccessAction::class)->execute($admin, $box->fresh(), '1234', (string) Str::uuid());
    $call = fn () => app(OpenCashBoxAction::class)->execute($admin, $next->id, ['balances' => ['bcp' => $amount, 'efectivo' => '0', 'yape' => '0'], 'request_key' => (string) Str::uuid()]);
    if ($accepted) {
        expect(array_sum($call()->opening_balances))->toBe(1600500);
    } else {
        expect($call)->toThrow(ValidationException::class);
        expect(CashBoxSession::count())->toBe(1);
    }
})->with([['16005', true], ['16000', false], ['16006', false]]);

function legacyIncompleteOpeningForRectification(): array
{
    [$admin, $box, $access] = strictOpeningSetup(false);
    $old = app(OpenCashBoxAction::class)->execute($admin, $access->id, ['balances' => ['bcp' => '16000', 'efectivo' => '0', 'yape' => '0'], 'request_key' => (string) Str::uuid()]);
    $old->update(['opening_balances' => ['bcp' => 1310700, 'efectivo' => 0, 'yape' => 0], 'current_balances' => ['bcp' => 0, 'efectivo' => 1310700, 'yape' => 0], 'status' => 'CERRADA', 'active_slot' => null, 'closed_at' => now(), 'released_at' => now(), 'handover_balances' => ['bcp' => 0, 'efectivo' => 1310700, 'yape' => 0]]);
    $current = $old->replicate();
    $current->fill(['previous_session_id' => $old->id, 'status' => 'ABIERTA', 'active_slot' => 1, 'closed_at' => null, 'released_at' => null, 'handover_balances' => null,
        'opening_balances' => ['bcp' => 149130, 'efectivo' => 1310750, 'yape' => 140120], 'current_balances' => ['bcp' => 149130, 'efectivo' => 1311250, 'yape' => 140120],
        'comparison' => ['difference_cents' => 289300, 'opening_surplus_cents' => 289300, 'receipt_classification' => 'SOBRANTE'], 'request_key' => (string) Str::uuid(), 'revision' => 3]);
    $current->current_balances = $current->opening_balances;
    $current->save();
    app(RecordQuickCashBoxCommissionAction::class)->execute($admin, $current->id, ['fee_type' => 'FACTURAS', 'bill_count' => 10, 'request_key' => (string) Str::uuid(), 'expected_revision' => $current->revision]);
    $current->refresh();
    DB::table('cash_box_receipt_discrepancies')->insert(['cash_box_session_id' => $current->id, 'previous_session_id' => $old->id, 'reported_by' => $admin->id, 'difference_cents' => 289300, 'comparison' => '{}', 'reason' => 'Sobrante inicial', 'status' => 'SOBRANTE_REGISTRADO', 'classification' => 'SOBRANTE', 'created_at' => now()]);

    return [$admin, $current];
}

it('rectifies only the fictitious opening surplus preserving balances history and real fees on retry', function () {
    [$admin, $session] = legacyIncompleteOpeningForRectification();
    $balances = $session->current_balances;
    $key = (string) Str::uuid();
    $expectedRevision = $session->revision + 1;
    $action = app(RectifyOpeningSurplusAction::class);
    $result = $action->execute($admin, $session->id, 289300, 'Corrección de primera apertura incompleta autorizada.', $key);
    $retry = $action->execute($admin, $session->id, 289300, 'Corrección de primera apertura incompleta autorizada.', $key);
    expect($result->current_balances)->toBe($balances)->and($result->comparison['opening_surplus_cents'])->toBe(0)
        ->and($result->comparison['difference_cents'])->toBe(289300)->and($retry->revision)->toBe($expectedRevision);
    expect(DB::table('cash_box_operations')->where('cash_box_session_id', $session->id)->sum('fee_cents'))->toBe(500);
    $this->assertDatabaseCount('cash_box_sessions', 2);
    $this->assertDatabaseHas('cash_box_receipt_discrepancies', ['cash_box_session_id' => $session->id, 'status' => 'RECTIFICADO', 'difference_cents' => 289300]);
    expect(CashBoxAudit::where('action', 'SOBRANTE_INICIAL_RECTIFICADO')->count())->toBe(1);
});

it('rejects an unexpected surplus or a closed session without changing any balances', function (bool $closed) {
    [$admin, $session] = legacyIncompleteOpeningForRectification();
    if ($closed) {
        $session->update(['status' => 'CERRADA', 'active_slot' => null]);
    }
    $before = $session->fresh()->toArray();
    expect(fn () => app(RectifyOpeningSurplusAction::class)->execute($admin, $session->id, $closed ? 289300 : 289301, 'Corrección administrativa de referencia incompleta.', (string) Str::uuid()))->toThrow(ValidationException::class);
    expect($session->fresh()->toArray())->toBe($before);
})->with([false, true]);

it('renders an explicit opening button and rejects a mismatched amount through Livewire', function () {
    [$admin, $box] = strictOpeningSetup();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    $page = Livewire::test(CashBoxOperations::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $page->assertSee('Total requerido:')->assertSee('16000.00')->assertDontSee('wire:submit="confirmOpening"', false)
        ->assertSee('wire:click="confirmOpening"', false)->assertSee('x-on:keydown.enter.prevent.stop', false)
        ->set('openingBalances', ['bcp' => '2000', 'efectivo' => '0', 'yape' => '0'])->call('confirmOpening')->assertHasErrors(['opening_total']);
    $this->assertDatabaseCount('cash_box_sessions', 0);
    $page->set('openingBalances', ['bcp' => '16000', 'efectivo' => '0', 'yape' => '0'])->call('confirmOpening')->assertHasNoErrors();
    $this->assertDatabaseCount('cash_box_sessions', 1);
});

it('does not allow a cashier to perform an administrative surplus rectification', function () {
    [$admin, $session] = legacyIncompleteOpeningForRectification();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => app(RectifyOpeningSurplusAction::class)->execute($cashier, $session->id, 289300, 'Intento de corrección sin autorización.', (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect($session->fresh()->comparison['opening_surplus_cents'])->toBe(289300);
});

it('rolls back the complete rectification if its audit cannot be written', function () {
    [$admin, $session] = legacyIncompleteOpeningForRectification();
    $before = $session->toArray();
    DB::unprepared("CREATE TRIGGER reject_rectification_audit BEFORE INSERT ON cash_box_audits WHEN NEW.action = 'SOBRANTE_INICIAL_RECTIFICADO' BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
    try {
        expect(fn () => app(RectifyOpeningSurplusAction::class)->execute($admin, $session->id, 289300, 'Corrección administrativa de referencia inicial.', (string) Str::uuid()))->toThrow(QueryException::class);
        expect($session->fresh()->toArray())->toBe($before);
        $this->assertDatabaseHas('cash_box_receipt_discrepancies', ['cash_box_session_id' => $session->id, 'status' => 'SOBRANTE_REGISTRADO']);
    } finally {
        DB::unprepared('DROP TRIGGER reject_rectification_audit');
    }
});
