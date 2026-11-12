<?php

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Reportes\Data\CashierTransactionPeriod;
use App\Domain\Reportes\Queries\CashBoxReportQuery;
use App\Filament\Pages\Reportes\CashBoxReport;
use App\Filament\Pages\Reportes\CashierTransactionsReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 16:00:00', 'UTC'));
    $this->seed(UsuariosPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrador');
    $this->cashier = User::factory()->create(['name' => 'Ana Cajera']);
    $this->cashier->assignRole('cajera');
    $this->manager = User::factory()->create(['name' => 'Beatriz Encargada']);
    $this->manager->assignRole('encargado');
    $this->box = CashBox::factory()->create(['created_by' => $this->admin->id, 'name' => 'Tablet Blanca']);
    $this->otherBox = CashBox::factory()->create(['created_by' => $this->admin->id, 'name' => 'Tablet Negra']);
    $this->actingAs($this->admin);
});

function cashReportSession(User $actor, CashBox $box, string $opened = '2026-11-10 12:00:00'): CashBoxSession
{
    $config = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $actor->id, 'base_capital_cents' => 1600000, 'accounts' => ['cash' => ['name' => 'Efectivo', 'initial_cents' => 1600000]], 'shifts' => [], 'reason' => 'Prueba', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => $opened]);

    return CashBoxSession::create(['cash_box_id' => $box->id, 'user_id' => $actor->id, 'configuration_id' => $config->id, 'status' => 'CERRADA', 'active_slot' => null, 'base_capital_cents' => 1600000, 'opening_balances' => ['cash' => 1600000], 'expected_balances' => ['cash' => 1600000], 'current_balances' => ['cash' => 1600000], 'comparison' => [], 'opened_at' => $opened, 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]);
}
function cashReportClose(CashBoxSession $session, array $extra = []): CashBoxClose
{
    return CashBoxClose::create(array_replace(['cash_box_session_id' => $session->id, 'cash_box_id' => $session->cash_box_id, 'user_id' => $session->user_id, 'period' => 'manana', 'shift_snapshot' => [], 'counted_balances' => [], 'real_balances' => [], 'expected_balances' => [], 'comparison' => [], 'difference_cents' => 0, 'commission_cents' => 100, 'capital_cents' => 1600000, 'requires_liquidation' => false, 'liquidation_completed' => true, 'settlement_source' => 'cash', 'created_at' => '2026-11-10 18:00:00'], $extra));
}
function cashReportOperation(CashBoxSession $session, int $fee = 100, string $date = '2026-11-10 14:00:00', string $status = 'VIGENTE'): CashBoxOperation
{
    return CashBoxOperation::create(['cash_box_session_id' => $session->id, 'user_id' => $session->user_id, 'amount_cents' => 999999, 'fee_type' => 'ACH', 'fee_cents' => $fee, 'suggested_fee_cents' => $fee, 'fee_component' => 'cash', 'deltas' => ['cash' => $fee], 'status' => $status, 'created_at' => $date]);
}
function cashReportCounter(CashBoxClose $close, array $extra = []): int
{
    return DB::table('cash_box_counter_readings')->insertGetId(array_replace(['cash_box_id' => $close->cash_box_id, 'cash_box_close_id' => $close->id, 'user_id' => $close->user_id, 'month' => '2026-11', 'reading' => 1000, 'delta' => 20, 'fixed_reading' => 100, 'fixed_delta' => 5, 'baseline' => false, 'created_at' => $close->created_at], $extra));
}
function cashReportFilters(array $extra = []): array
{
    return array_replace(['from' => '2026-11-01', 'until' => '2026-11-12'], $extra);
}

it('separates recorded operations from BCP deltas and excludes cancelled evidence and initial baselines', function () {
    $s = cashReportSession($this->cashier, $this->box);
    cashReportOperation($s, 125);
    cashReportOperation($s, 900, status: 'ANULADA');
    cashReportCounter(cashReportClose($s));
    $other = cashReportSession($this->manager, $this->otherBox);
    cashReportCounter(cashReportClose($other), ['baseline' => true, 'delta' => 999, 'fixed_delta' => null]);
    $cancelled = cashReportSession($this->cashier, $this->box);
    cashReportCounter(cashReportClose($cancelled, ['cancelled_at' => now()]));
    $r = app(CashBoxReportQuery::class)->execute($this->admin, cashReportFilters());
    expect($r['operations'])->toBe(1)->and($r['fees'])->toBe(125)->and($r['general'])->toBe(20)->and($r['fixed'])->toBe(5)
        ->and($r['baselines'])->toBe(1)->and($r['unknown_fixed'])->toBe(1)->and($r['cancelled_operations'])->toBe(1);
    expect($r['counter_records']->total())->toBe(2)->and($r['operation_records']->total())->toBe(1);
    expect($r['charts']['bcp']['datasets'][0]['data'])->toBe([20, 0]);
});

it('applies the same employee and box filters without counting transferred capital as commission', function () {
    $a = cashReportSession($this->cashier, $this->box);
    $b = cashReportSession($this->manager, $this->box);
    $c = cashReportSession($this->cashier, $this->otherBox);
    cashReportOperation($a, 100);
    cashReportOperation($b, 200);
    cashReportOperation($c, 300);
    $r = app(CashBoxReportQuery::class)->execute($this->admin, cashReportFilters(['user_id' => $this->cashier->id, 'box_id' => $this->box->id]));
    expect($r['fees'])->toBe(100)->and($r['operations'])->toBe(1)->and($r['ranking'][0]['name'])->toBe('Ana Cajera');
    expect($r['operation_records']->items()[0]->box_name)->toBe('Tablet Blanca');
});

it('uses Bolivia date boundaries and keeps totals independent of the detail page', function () {
    $s = cashReportSession($this->cashier, $this->box);
    cashReportOperation($s, 700, '2026-11-01 03:59:59');
    for ($i = 0; $i < 12; $i++) {
        cashReportOperation($s, 100, '2026-11-01 04:00:00');
    }
    cashReportOperation($s, 900, '2026-11-13 04:00:00');
    $r = app(CashBoxReportQuery::class)->execute($this->admin, cashReportFilters(), ['operations' => 2]);
    expect($r['fees'])->toBe(1200)->and($r['operations'])->toBe(12)->and($r['operation_records']->count())->toBe(2);
});

it('reports current confirmed shortages and under review cases separately without duplicating opening and closing links', function () {
    $s = cashReportSession($this->cashier, $this->box);
    foreach ([['CONFIRMADA', 1000, 600], ['EN_REVISION', 2000, 0], ['ANULADA', 3000, 0], ['CARGO_BANCARIO', 4000, 0]] as [$status,$amount,$pending]) {
        $case = EconomicIncident::create(['user_id' => $this->cashier->id, 'reported_by' => $this->admin->id, 'source_module' => 'cajas', 'source_reference' => (string) Str::uuid(), 'component' => 'total', 'status' => $status, 'amount_cents' => $amount, 'pending_cents' => $pending, 'reason' => 'Prueba de faltante', 'box_label' => 'Tablet Blanca', 'occurred_at' => '2026-11-02 16:00:00']);
        $linked = cashReportSession($this->cashier, $this->box);
        cashReportClose($linked, ['economic_incident_id' => $case->id]);
        if ($status === 'CONFIRMADA') {
            DB::table('cash_box_receipt_discrepancies')->insert(['cash_box_session_id' => $linked->id, 'reported_by' => $this->cashier->id, 'difference_cents' => -$amount, 'comparison' => '{}', 'reason' => 'Mismo caso conservado', 'status' => 'DEUDA_REGISTRADA', 'classification' => 'FALTANTE', 'economic_incident_id' => $case->id, 'created_at' => '2026-11-02 16:00:00']);
        }
    }
    $r = app(CashBoxReportQuery::class)->execute($this->admin, cashReportFilters(['from' => '2026-11-10', 'box_id' => $this->box->id]));
    expect($r['shortages'])->toBe(['total' => 1000, 'pending' => 600, 'review' => 2000]);
    expect($r['shortage_records']->total())->toBe(4);
});

it('averages actual opening times only under the linked historical shift', function () {
    foreach ([['2026-11-10 12:10:00', '2026-11-10'], ['2026-11-11 12:30:00', '2026-11-11']] as [$opened,$date]) {
        $s = cashReportSession($this->cashier, $this->box, $opened);
        $day = ScheduledWorkday::create(['user_id' => $this->cashier->id, 'created_by' => $this->admin->id, 'work_date' => $date, 'period' => 'manana', 'starts_at' => $date.' 12:00:00', 'ends_at' => $date.' 18:00:00']);
        $audit = DB::table('user_audits')->insertGetId(['actor_user_id' => $this->cashier->id, 'subject_user_id' => $this->cashier->id, 'action' => 'pin_verified', 'changes' => '{}', 'created_at' => $opened]);
        DB::table('cash_box_schedule_events')->insert(['source_key' => (string) Str::uuid(), 'user_id' => $this->cashier->id, 'kind' => 'OPENING', 'pin_audit_id' => $audit, 'cash_box_session_id' => $s->id, 'scheduled_workday_id' => $day->id, 'occurred_at' => $opened, 'status' => 'DONE']);
    }
    cashReportSession($this->manager, $this->otherBox, '2026-11-10 18:15:00');
    $r = app(CashBoxReportQuery::class)->execute($this->admin, cashReportFilters());
    expect($r['opening_averages'][0]['average'])->toBe('08:20')->and($r['opening_averages'][0]['count'])->toBe(2)->and($r['opening_averages'][0]['offset_minutes'])->toBe(20.0);
    expect($r['opening_averages'][1]['period'])->toBe('unknown')->and($r['opening_averages'][1]['scheduled'])->toBeNull();
});

it('protects the report and validates applied filters before changing the visible scope', function () {
    foreach ([$this->cashier, $this->manager] as $user) {
        expect(fn () => app(CashBoxReportQuery::class)->execute($user, cashReportFilters()))->toThrow(AuthorizationException::class);
    }
    $screen = Livewire::test(CashBoxReport::class)->assertSuccessful()->assertSee('Consulta por cajera y caja')->assertSee('Contadores declarados en cierres BCP');
    $screen->set('fromDate', '2026-11-13')->call('applyCashFilters')->assertHasErrors(['from'])->assertSet('appliedFilters.from', '2026-11-01');
    $this->admin->forceFill(['is_active' => false])->save();
    expect(fn () => app(CashBoxReportQuery::class)->execute($this->admin, cashReportFilters()))->toThrow(AuthorizationException::class);
});

it('refreshes the applied employee scope and preserves source data while querying', function () {
    $a = cashReportSession($this->cashier, $this->box);
    $b = cashReportSession($this->manager, $this->otherBox);
    cashReportOperation($a, 100);
    cashReportOperation($b, 250);
    $before = DB::table('cash_box_operations')->orderBy('id')->get()->toJson();
    $screen = Livewire::test(CashBoxReport::class);
    $screen->set('cashierId', (string) $this->cashier->id)->set('cashBoxId', (string) $this->box->id)->call('applyCashFilters')->assertHasNoErrors();
    expect($screen->instance()->appliedFilters['user_id'])->toBe((string) $this->cashier->id);
    expect($screen->instance()->appliedFilters['box_id'])->toBe((string) $this->box->id);
    expect(DB::table('cash_box_operations')->orderBy('id')->get()->toJson())->toBe($before);
});

it('does not assign unlinked shortages to a box by its mutable name', function () {
    EconomicIncident::create(['user_id' => $this->cashier->id, 'reported_by' => $this->admin->id, 'source_module' => 'cajas', 'source_reference' => 'manual-case', 'component' => 'total', 'status' => 'CONFIRMADA', 'amount_cents' => 1000, 'pending_cents' => 1000, 'reason' => 'Sin enlace histórico', 'box_label' => 'Tablet Blanca', 'occurred_at' => '2026-11-10 16:00:00']);
    $all = app(CashBoxReportQuery::class)->execute($this->admin, cashReportFilters());
    $selected = app(CashBoxReportQuery::class)->execute($this->admin, cashReportFilters(['box_id' => $this->box->id]));
    expect($all['shortages']['pending'])->toBe(1000)->and($all['unlinked_shortages'])->toBe(1)
        ->and($selected['shortages']['pending'])->toBe(0)->and($selected['unlinked_shortages'])->toBe(1);
});

it('offers a dedicated transaction page without commission shortage or opening sections and applies its own filters', function () {
    $a = cashReportSession($this->cashier, $this->box);
    $b = cashReportSession($this->manager, $this->otherBox);
    cashReportOperation($a, 100);
    cashReportOperation($b, 250);
    $page = Livewire::test(CashierTransactionsReport::class)->assertSuccessful()
        ->assertSee('Consultar transacciones por cajera')->assertSee('Transacciones por responsable')
        ->assertDontSee('Comisiones operativas acumuladas')->assertDontSee('Promedio real de aperturas')->assertDontSee('Casos de faltantes del mes');
    $page->set('cashierId', (string) $this->cashier->id)->set('cashBoxId', (string) $this->box->id)->call('applyCashFilters')->assertHasNoErrors();
    expect($page->instance()->appliedFilters['user_id'])->toBe((string) $this->cashier->id);
    $this->actingAs($this->cashier)->get(CashierTransactionsReport::getUrl())->assertForbidden();
});

it('resolves quick detail periods in Bolivia using inclusive calendar days', function (string $period, string $from, string $until) {
    expect(app(CashierTransactionPeriod::class)->resolve($period))->toBe(['from' => $from, 'until' => $until]);
})->with([
    ['today', '2026-11-12', '2026-11-12'], ['yesterday', '2026-11-11', '2026-11-11'],
    ['last3', '2026-11-10', '2026-11-12'], ['last4', '2026-11-09', '2026-11-12'],
    ['week', '2026-11-09', '2026-11-12'], ['previous_week', '2026-11-02', '2026-11-08'],
    ['month', '2026-11-01', '2026-11-12'], ['previous_month', '2026-10-01', '2026-10-31'],
]);

it('filters the floating transaction details without changing the accumulated main report', function () {
    $s = cashReportSession($this->cashier, $this->box);
    cashReportOperation($s, 100, '2026-11-12 12:00:00');
    cashReportOperation($s, 200, '2026-11-11 12:00:00');
    $page = Livewire::test(CashierTransactionsReport::class)->call('showTransactionDetails', 'OPERATIONS')
        ->assertSet('transactionModalOpen', true)->assertSet('transactionFilters.from', '2026-11-12')->assertDispatched('open-modal', id: 'cashier-transaction-details');
    $data = $page->instance()->render()->getData();
    expect($data['transactionDetails']['operations'])->toBe(1)->and($data['cashReport']['operations'])->toBe(2);
    $page->set('transactionPeriod', 'yesterday')->set('transactionCashier', (string) $this->cashier->id)->call('applyTransactionFilters')->assertHasNoErrors();
    $data = $page->instance()->render()->getData();
    expect($data['transactionDetails']['operations'])->toBe(1)->and($data['transactionDetails']['operation_records']->first()->fee_cents)->toBe(200);
    $page->assertSet('transactionFilters.from', '2026-11-11')->assertSet('appliedFilters.from', '2026-11-01');
    $page->set('transactionPeriod', 'last3')->call('applyTransactionFilters')->assertHasNoErrors();
    expect($page->instance()->render()->getData()['transactionDetails']['operations'])->toBe(2);
    $page->call('closeTransactionDetails')->assertSet('transactionModalOpen', false)->assertDispatched('close-modal', id: 'cashier-transaction-details');
});

it('keeps modal validation errors inside the detail and preserves the last successful selection', function () {
    $page = Livewire::test(CashierTransactionsReport::class)->call('showTransactionDetails', 'BCP');
    $page->set('transactionPeriod', 'range')->set('transactionFrom', '2026-11-10')->set('transactionUntil', '2026-11-09')->call('applyTransactionFilters')->assertHasErrors(['transactionUntil']);
    $page->assertSet('transactionFilters.from', '2026-11-12')->assertSet('transactionModalOpen', true);
    $page->set('transactionPeriod', 'invalid')->call('applyTransactionFilters')->assertHasErrors(['transactionPeriod']);
});

it('paginates the floating details independently from the main record lists', function () {
    $s = cashReportSession($this->cashier, $this->box);
    for ($i = 0; $i < 12; $i++) {
        cashReportOperation($s, 100, '2026-11-12 12:00:00');
    }
    $page = Livewire::test(CashierTransactionsReport::class)->call('showTransactionDetails', 'OPERATIONS')->call('gotoPage', 2, 'transactionOperationsPage');
    $data = $page->instance()->render()->getData();
    expect($data['transactionDetails']['operation_records']->count())->toBe(2)->and($data['transactionDetails']['operations'])->toBe(12)
        ->and($data['cashReport']['operation_records']->currentPage())->toBe(1);
    $page->set('transactionPeriod', 'yesterday')->call('applyTransactionFilters');
    expect($page->instance()->getPage('transactionOperationsPage'))->toBe(1);
});

it('uses the Bolivia day near UTC midnight and guards access when detail is opened', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 02:00:00', 'UTC'));
    expect(app(CashierTransactionPeriod::class)->resolve('today')['from'])->toBe('2026-11-12');
    $page = Livewire::test(CashierTransactionsReport::class);
    $this->admin->forceFill(['is_active' => false])->save();
    $page->call('showTransactionDetails','BCP')->assertForbidden();
});
