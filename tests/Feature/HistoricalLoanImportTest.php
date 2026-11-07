<?php

use App\Domain\Prestamos\Actions\ImportHistoricalLoanAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Actions\SettleLoanAction;
use App\Domain\Prestamos\Calculators\LoanSettlementCalculator;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\HistoricalLoanImport as ImportRecord;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanCommand;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanSettlement;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Domain\Prestamos\Services\HistoricalLoanImport;
use App\Filament\Pages\Prestamos\LoanList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'America/La_Paz'));
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
});

function historyLoanFixture(string $role = 'administrador'): array
{
    test()->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Cajera original']);
    $cashier->assignRole('cajera');
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Teléfono', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $actor = $admin;
    if ($role === 'encargado') {
        $actor = User::factory()->create();
        $actor->assignRole('encargado');
    }
    $data = ['external_reference' => 'Anterior-123', 'ci' => 'CI-123', 'name' => 'Cliente original', 'phone' => '77777777', 'type_id' => $type->id, 'type_revision' => $type->revision,
        'type_name' => 'Teléfono del contrato', 'description' => 'Samsung A12', 'original_cashier_id' => $cashier->id, 'original_cashier_name' => $cashier->name,
        'occurred_at' => '2026-11-01 10:30:15', 'due_date' => '2026-11-30', 'capital' => '200', 'interest' => '30', 'rate' => '15', 'status' => 'ACTIVO',
        'delivery_method' => 'CASH', 'reason' => 'Carga de boleta original del sistema anterior.', 'request_key' => (string) Str::uuid()];

    return [$actor, $cashier, $data];
}

function historyLoanConfirmed(User $actor, array $data): array
{
    $review = app(HistoricalLoanImport::class)->prepare($actor, $data);

    return $data + ['review_confirmed' => true, 'expected_fund_revision' => $review['fund_revision'], 'expected_display_status' => $review['display_status'], 'expected_collection_cents' => $review['current_collection_cents']];
}

it('imports the original contract author and dates with an explicit single net fund adjustment', function (string $status, int $balance, int $delta, ?string $received) {
    [$actor, $cashier, $data] = historyLoanFixture('encargado');
    $data['status'] = $status;
    if (in_array($status, ['PERDIDA_TOTAL', 'VENDIDO'], true)) {
        $data['occurred_at'] = '2026-10-01 10:30:15';
        $data['due_date'] = '2026-10-29';
    }
    if ($received !== null) {
        $data['received'] = $received;
        $data['closed_at'] = $status === 'VENDIDO' ? '2026-11-06 11:00:00' : '2026-11-02 11:00:00';
    }
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    expect($loan->cashier_snapshot['name'])->toBe($cashier->name)->and($loan->user_id)->toBe($cashier->id)
        ->and($loan->created_at->timezone('America/La_Paz')->format('Y-m-d H:i:s'))->toBe($data['occurred_at'])
        ->and($loan->original_interest_cents)->toBe(3000)->and($loan->status)->toBe($status);
    expect(LoanFund::sole()->balance_cents)->toBe($balance);
    expect(LoanMovement::where('kind', 'HISTORICAL_IMPORT')->sole()->delta_cents)->toBe($delta);
    expect(LoanMovement::where('kind', 'DISBURSEMENT')->count())->toBe(0);
    expect(ImportRecord::sole()->imported_by)->toBe($actor->id)->and(LoanCommand::where('kind', 'HISTORICAL_IMPORT')->count())->toBe(1);
    expect(app(LoanQuery::class)->detail($actor, $loan->id)['terms_snapshot']['historical_import']['reference'])->toBe('Anterior-123');
})->with([['ACTIVO', 80000, -20000, null], ['PERDIDA_TOTAL', 80000, -20000, null], ['RECOGIDO_PAGADO', 103000, 3000, '230'], ['ANULADO', 100000, 0, '200'], ['VENDIDO', 110000, 10000, '300']]);

it('replays the same import without changing the fund and blocks the same historical reference with a different request', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $command = historyLoanConfirmed($actor, $data);
    $first = app(ImportHistoricalLoanAction::class)->execute($actor, $command);
    expect(app(ImportHistoricalLoanAction::class)->execute($actor, $command)->id)->toBe($first->id);
    $data['request_key'] = (string) Str::uuid();
    $data['external_reference'] = ' anterior-123 ';
    expect(fn () => app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data)))->toThrow(ValidationException::class);
    expect(Loan::count())->toBe(1)->and(ImportRecord::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(80000);
});

it('blocks a stale fund quote without creating a borrower or loan', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $command = historyLoanConfirmed($actor, $data);
    app(ManageLoanFundAction::class)->execute($actor, ['kind' => 'IN', 'amount' => '1', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    expect(fn () => app(ImportHistoricalLoanAction::class)->execute($actor, $command))->toThrow(ValidationException::class);
    expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100100);
});

it('applies current late and loss rules using the actual original due date', function (string $due, string $status) {
    [$actor, $cashier, $data] = historyLoanFixture();
    $data['occurred_at'] = '2026-10-01 10:00:00';
    $data['due_date'] = $due;
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    expect($loan->displayStatus())->toBe($status);
    if ($status === 'CON_MULTA') {
        expect(app(LoanSettlementCalculator::class)->quote($loan)['paid_cents'])->toBe(27000);
    } else {
        expect(fn () => app(LoanSettlementCalculator::class)->quote($loan))->toThrow(ValidationException::class);
    }
})->with([['2026-11-05', 'CON_MULTA'], ['2026-10-29', 'PERDIDA_TOTAL']]);

it('collects a pending imported loan through the normal payment and delivery action without repeating the debit', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    $quote = app(LoanSettlementCalculator::class)->quote($loan);
    app(SettleLoanAction::class)->execute($cashier, $loan->id, ['request_key' => (string) Str::uuid(), 'expected_revision' => $quote['revision'], 'expected_paid_cents' => $quote['paid_cents'], 'payment_confirmed' => true, 'delivery_confirmed' => true]);
    expect($loan->fresh()->status)->toBe('RECOGIDO_PAGADO')->and(LoanFund::sole()->balance_cents)->toBe(103000)->and(LoanSettlement::count())->toBe(1);
});

it('preserves current client contact data while retaining the historical snapshot', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $client = Borrower::create(['ci' => $data['ci'], 'name' => 'Nombre actual', 'phone' => '88888888']);
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    expect($client->fresh()->name)->toBe('Nombre actual')->and($loan->borrower_snapshot['name'])->toBe('Cliente original');
});

it('rolls back the whole import and net fund movement if audit insertion fails', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $command = historyLoanConfirmed($actor, $data);
    DB::unprepared("CREATE TRIGGER fail_import BEFORE INSERT ON loan_historical_imports BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
    try {
        expect(fn () => app(ImportHistoricalLoanAction::class)->execute($actor, $command))->toThrow(QueryException::class);
        expect(Loan::count())->toBe(0)->and(Borrower::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000)
            ->and(LoanMovement::where('kind', 'HISTORICAL_IMPORT')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER fail_import');
    }
});

it('reviews and imports through the administrator modal and finds the old reference in the permanent list', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $this->actingAs($actor);
    $page = Livewire::test(LoanList::class)->callAction('registerHistoricalLoan', $data)->assertHasNoActionErrors()->assertActionMounted('confirmHistoricalLoan');
    expect(Loan::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
    $page->setActionData(['review_confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    $loan = Loan::sole();
    $page->searchTable('Anterior-123')->assertCanSeeTableRecords([$loan]);
    expect(LoanFund::sole()->balance_cents)->toBe(80000);
});

it('hides the import option from cashiers even when they can view and register ordinary loans', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $this->actingAs($cashier);
    Livewire::test(LoanList::class)->assertActionHidden('registerHistoricalLoan');
    expect(fn () => app(ImportHistoricalLoanAction::class)->execute($cashier, $data))->toThrow(AuthorizationException::class);
});

it('keeps an import replay idempotent after both fund and catalog change', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $data['capital'] = '800';
    $command = historyLoanConfirmed($actor, $data);
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, $command);
    DB::table('loan_object_types')->where('id', $data['type_id'])->increment('revision');
    expect(app(ImportHistoricalLoanAction::class)->execute($actor, $command)->id)->toBe($loan->id)
        ->and(LoanFund::sole()->balance_cents)->toBe(20000)->and(ImportRecord::count())->toBe(1);
});

it('rejects invalid historical fields without changing the fund or permanent register', function (array $change) {
    [$actor, $cashier, $data] = historyLoanFixture();
    expect(fn () => app(HistoricalLoanImport::class)->prepare($actor, array_replace($data, $change)))->toThrow(ValidationException::class);
    expect(Loan::count())->toBe(0)->and(ImportRecord::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
})->with([
    [['occurred_at' => '2026-11-07 10:00:00']], [['due_date' => '2026-10-31']], [['external_reference' => '   ']],
    [['capital' => '-1']], [['interest' => '-1']], [['capital' => '1001']],
    [['status' => 'RECOGIDO_PAGADO', 'closed_at' => '2026-10-31 10:00:00', 'received' => '230']],
    [['status' => 'RECOGIDO_PAGADO', 'closed_at' => '2026-11-02 11:00:00', 'received' => '210']],
    [['status' => 'ANULADO', 'closed_at' => '2026-11-02 11:00:00', 'received' => '100']],
]);

it('records an original cashier without creating a new login and ignores injected importer and fund fields', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $users = User::count();
    $data['original_cashier_id'] = null;
    $data['original_cashier_name'] = 'Personal del sistema anterior';
    $data['imported_by'] = $cashier->id;
    $data['fund_delta_cents'] = 999999;
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    expect(User::count())->toBe($users)->and($loan->cashier_snapshot['id'])->toBeNull()->and($loan->cashier_snapshot['name'])->toBe('Personal del sistema anterior')
        ->and(ImportRecord::sole()->imported_by)->toBe($actor->id)->and(LoanFund::sole()->balance_cents)->toBe(80000);
});

it('cancels a reviewed historical registration without touching capital or creating any record', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $this->actingAs($actor);
    $page = Livewire::test(LoanList::class)->callAction('registerHistoricalLoan', $data)->assertActionMounted('confirmHistoricalLoan');
    $page->unmountAction();
    expect(Loan::count())->toBe(0)->and(ImportRecord::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});

it('keeps the original data when going back from review to correct it', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $this->actingAs($actor);
    $page = Livewire::test(LoanList::class)->callAction('registerHistoricalLoan', $data)->assertActionMounted('confirmHistoricalLoan');
    $page->call('replaceMountedAction', 'registerHistoricalLoan')->assertActionMounted('registerHistoricalLoan');
    $page->assertActionDataSet(['external_reference' => 'Anterior-123', 'capital' => '200', 'original_cashier_name' => $cashier->name]);
    expect(Loan::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});

it('shows an actionable confirmation error when the fund changes while reviewing', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $this->actingAs($actor);
    $page = Livewire::test(LoanList::class)->callAction('registerHistoricalLoan', $data)->assertActionMounted('confirmHistoricalLoan');
    app(ManageLoanFundAction::class)->execute($actor, ['kind' => 'IN', 'amount' => '1', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $page->setActionData(['review_confirmed' => true])->callMountedAction()->assertHasActionErrors(['review_confirmed']);
    expect(Loan::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100100);
});

it('distinguishes actual new settlement receipts from imported historical payments', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    $quote = app(LoanSettlementCalculator::class)->quote($loan);
    app(SettleLoanAction::class)->execute($cashier, $loan->id, ['request_key' => (string) Str::uuid(), 'expected_revision' => $quote['revision'], 'expected_paid_cents' => $quote['paid_cents'], 'payment_confirmed' => true, 'delivery_confirmed' => true]);
    $detail = app(LoanQuery::class)->detail($actor, $loan->id);
    expect(view('prestamos.settlement-receipt', ['detail' => $detail, 'logoData' => ''])->render())->toContain('Total ingresado a Yape')->not->toContain('REGISTRO HISTÓRICO IMPORTADO');
    $data['external_reference'] = 'Anterior-124';
    $data['request_key'] = (string) Str::uuid();
    $data['status'] = 'RECOGIDO_PAGADO';
    $data['closed_at'] = '2026-11-02 11:00:00';
    $data['received'] = '230';
    $past = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    $oldDetail = app(LoanQuery::class)->detail($actor, $past->id);
    expect(view('prestamos.settlement-receipt', ['detail' => $oldDetail, 'logoData' => ''])->render())->toContain('REGISTRO HISTÓRICO IMPORTADO', 'Cobro histórico declarado', 'Anterior-124');
});

it('renders an imported historical sale receipt with its original reference and amount', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $data += ['closed_at' => '2026-11-02 11:00:00', 'received' => '300'];
    $data['status'] = 'VENDIDO';
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    $detail = app(LoanQuery::class)->detail($actor, $loan->id);
    expect(view('prestamos.sale-receipt', ['sale' => $detail['sale'], 'loanId' => $loan->id, 'logoData' => ''])->render())->toContain('REGISTRO HISTÓRICO IMPORTADO', 'Anterior-123', '300');
});

it('prints a negative historical adjustment with the correct decimal sign', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $data['capital'] = '200.25';
    $review = app(HistoricalLoanImport::class)->prepare($actor, $data);
    expect($review['fund_after_cents'])->toBe(79975);
    expect(view('prestamos.historical-import-review', ['review' => $review])->render())->toContain('-200.25', '799.75')->not->toContain('-200.-25');
});

it('supports a supervisors complete two-step import without fund-administration privileges', function () {
    [$actor, $cashier, $data] = historyLoanFixture('encargado');
    $this->actingAs($actor);
    expect($actor->can('prestamos.fondo.administrar'))->toBeFalse();
    Livewire::test(LoanList::class)->callAction('registerHistoricalLoan', $data)->assertActionMounted('confirmHistoricalLoan')
        ->setActionData(['review_confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect(Loan::count())->toBe(1)->and(ImportRecord::sole()->imported_by)->toBe($actor->id)->and(LoanFund::sole()->balance_cents)->toBe(80000);
});

it('preserves the original historical telephone without enforcing the new registrations phone format', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $data['phone'] = '+591 77777777';
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    expect($loan->borrower_snapshot['phone'])->toBe('+591 77777777');
});

it('includes proven historical recovery expenses once in both the fund and the object cost', function (string $status, ?string $received, int $fund) {
    [$actor, $cashier, $data] = historyLoanFixture();
    $data['occurred_at'] = '2026-10-01 10:30:15';
    $data['due_date'] = '2026-10-29';
    $data['status'] = $status;
    $data['historical_expenses'] = '10';
    $data['expenses_at'] = '2026-11-01 09:00:00';
    $data['expenses_description'] = 'Reparación original con factura';
    if ($received) {
        $data['received'] = $received;
        $data['closed_at'] = '2026-11-06 11:00:00';
    }
    $loan = app(ImportHistoricalLoanAction::class)->execute($actor, historyLoanConfirmed($actor, $data));
    expect(LoanFund::sole()->balance_cents)->toBe($fund)->and($loan->objectExpenses()->sum('amount_cents'))->toBe(1000);
    if ($status === 'VENDIDO') {
        expect($loan->sale->summary['total_profit_cents'])->toBe(9000)->and($loan->sale->summary['invested_cents'])->toBe(21000);
    }
})->with([['PERDIDA_TOTAL', null, 79000], ['VENDIDO', '300', 109000]]);

it('does not declare an unexpired contract lost before the current eighth-day rule', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $data['status'] = 'PERDIDA_TOTAL';
    expect(fn () => app(HistoricalLoanImport::class)->prepare($actor, $data))->toThrow(ValidationException::class);
    expect(Loan::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});

it('requires a fresh review when the amount to collect changes without a fund movement', function () {
    [$actor, $cashier, $data] = historyLoanFixture();
    $data['occurred_at'] = '2026-10-01 10:00:00';
    $data['due_date'] = '2026-11-05';
    $command = historyLoanConfirmed($actor, $data);
    config(['prestamos.receipt.late_fee_cents' => 5000]);
    expect(fn () => app(ImportHistoricalLoanAction::class)->execute($actor, $command))->toThrow(ValidationException::class);
    expect(Loan::count())->toBe(0)->and(LoanFund::sole()->balance_cents)->toBe(100000);
});

it('starts the import modal with an unused request and an explicit pending status', function () {
    [$actor] = historyLoanFixture();
    $this->actingAs($actor);
    $page = Livewire::test(LoanList::class)->mountAction('registerHistoricalLoan');
    $page->assertActionDataSet(['status' => 'ACTIVO']);
    expect(Str::isUuid($page->get('mountedActions.0.data.request_key')))->toBeTrue();
    expect(Loan::count())->toBe(0);
});
