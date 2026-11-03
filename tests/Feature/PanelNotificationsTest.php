<?php

use App\Domain\Cajas\Actions\SetCashBoxCommissionAccountAction;
use App\Domain\ControlHorario\Actions\DetectTimeIncidentAction;
use App\Domain\ControlHorario\Actions\ReviewTimeAppealAction;
use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Actions\SubmitTimeReasonAction;
use App\Domain\ControlHorario\Actions\TimeHistoryAudit;
use App\Domain\ControlHorario\Queries\TimeAppealQuery;
use App\Domain\Incidencias\Actions\AppealEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReportOwnRepaymentAction;
use App\Domain\Incidencias\Actions\ReviewRepaymentReportAction;
use App\Domain\Notificaciones\Actions\ReadPanelNoticeAction;
use App\Domain\Notificaciones\Models\PanelNotice;
use App\Domain\Notificaciones\Queries\PanelInboxQuery;
use App\Domain\Notificaciones\Queries\PanelInboxRevision;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Filament\Resources\Cajas\Workflow\CashBoxCorrectionResource;
use App\Filament\Resources\Notificaciones\PanelNotices\Pages\ListPanelNotices;
use App\Filament\Resources\Notificaciones\PanelNotices\PanelNoticeResource;
use App\Filament\Support\Notificaciones\NoticeDestination;
use App\Livewire\Navigation\Sidebar;
use App\Livewire\Notificaciones\InboxUpdates;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function c104Fixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, CajasPermissionsSeeder::class]);
    $admin = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $admin->assignRole('administrador');
    $owner = User::factory()->create();
    $owner->assignRole('cajera');
    $incoming = User::factory()->create();
    $incoming->assignRole('cajera');
    app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['destination' => '70000104', 'holder_name' => 'Cuenta ficticia', 'pin' => '1234', 'reason' => 'Destino de pruebas', 'request_key' => (string) Str::uuid()]);
    $case = app(ReportEconomicIncidentAction::class)->execute($admin, $owner, ['source_module' => 'cajas', 'source_reference' => 'receipt:104', 'component' => 'total', 'box_label' => 'Caja ficticia C104', 'amount_cents' => 2000, 'reason' => 'Diferencia detectada al recibir', 'occurred_at' => now()->toDateTimeString()]);
    $case->update(['status' => 'CONFIRMADA', 'pending_cents' => 2000, 'reported_by' => $incoming->id]);

    return [$admin, $owner, $incoming, $case->fresh()];
}
it('C104 shows an opening shortage only to its responsible cashier', function () {
    [$admin,$owner,$incoming,$case] = c104Fixture();
    $before = $case->getAttributes();
    $rows = app(PanelInboxQuery::class)->execute($owner)->get();
    expect($rows->pluck('id')->all())->toContain('opening-shortage:'.$case->id);
    expect(app(PanelInboxQuery::class)->execute($incoming)->count())->toBe(0);
    expect($case->fresh()->getAttributes())->toBe($before);
});
it('C104 prioritizes repayment and appeal requests for Administration over information', function () {
    [$admin,$owner,$incoming,$case] = c104Fixture();
    app(ReportOwnRepaymentAction::class)->execute($owner, $case->id, ['operation_reference' => 'C104-ONE', 'request_key' => (string) Str::uuid()]);
    app(AppealEconomicIncidentAction::class)->execute($owner, $case->id, ['text' => 'Solicito revisar el origen', 'request_key' => (string) Str::uuid()]);
    $rows = app(PanelInboxQuery::class)->execute($admin)->orderBy('priority')->get();
    expect($rows->first()->priority)->toBe(0)->and($rows->where('priority', 0))->toHaveCount(2)->and($rows->pluck('id')->all())->toContain('shortage-info:'.$case->id);
    expect(app(PanelInboxQuery::class)->execute($owner)->where('priority', 0)->count())->toBe(0);
});
it('C104 reading is private and idempotent and cannot approve a request or reduce debt', function () {
    [$admin,$owner,$incoming,$case] = c104Fixture();
    $action = app(ReadPanelNoticeAction::class);
    $key = 'opening-shortage:'.$case->id;
    expect(fn () => $action->execute($incoming, $key))->toThrow(AuthorizationException::class);
    $action->execute($owner, $key);
    $action->execute($owner, $key);
    expect(DB::table('panel_notice_reads')->count())->toBe(1)->and($case->fresh()->pending_cents)->toBe(2000);
    expect(app(PanelInboxQuery::class)->execute($owner)->whereNull('read_at')->count())->toBe(0);
});
it('C104 removes administrative messages immediately when the administrative role is revoked', function () {
    [$admin,$owner,$incoming,$case] = c104Fixture();
    expect(app(PanelInboxQuery::class)->execute($admin)->count())->toBeGreaterThan(0);
    $admin->syncRoles(['cajera']);
    expect(app(PanelInboxQuery::class)->execute($admin)->count())->toBe(0);
    expect(fn () => app(ReadPanelNoticeAction::class)->execute($admin, 'shortage-info:'.$case->id))->toThrow(AuthorizationException::class);
});
it('C104 resolves a repayment request in the inbox and shows the response to its owner without duplicating it', function () {
    [$admin,$owner,$incoming,$case] = c104Fixture();
    $report = app(ReportOwnRepaymentAction::class)->execute($owner, $case->id, ['operation_reference' => 'C104-RESPONSE', 'request_key' => (string) Str::uuid()]);
    expect(app(PanelInboxQuery::class)->execute($admin)->where('id', 'repayment-review:'.$report->id)->count())->toBe(1);
    app(ReviewRepaymentReportAction::class)->execute($admin, $report->id, 'RECHAZAR', 'Referencia no localizada');
    expect(app(PanelInboxQuery::class)->execute($admin)->where('id', 'repayment-review:'.$report->id)->count())->toBe(0);
    expect(app(PanelInboxQuery::class)->execute($owner)->where('id', 'repayment-response:'.$report->id)->count())->toBe(1)->and($case->fresh()->pending_cents)->toBe(2000);
});

it('C104 does not duplicate the same personal opening event for an administrator who is its owner', function () {
    [$admin,$owner,$incoming,$case] = c104Fixture();
    $owner->syncRoles(['administrador']);
    $rows = app(PanelInboxQuery::class)->execute($owner)->get();
    expect($rows->pluck('id')->all())->toContain('opening-shortage:'.$case->id)->not->toContain('shortage-info:'.$case->id);
});
it('C104 rejects disabled users even if their role still contains panel permissions', function () {
    [$admin,$owner,$incoming,$case] = c104Fixture();
    $owner->forceFill(['is_active' => false])->save();
    expect($owner->fresh()->is_active)->toBeFalse();
    expect(fn () => app(PanelInboxQuery::class)->execute($owner))->toThrow(AuthorizationException::class);
});
it('C104 renders the inbox and marks a notice read through its table without paying debt', function () {
    [$admin, $owner, $incoming, $case] = c104Fixture();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->actingAs($owner);
    $notice = app(PanelInboxQuery::class)->execute($owner)->firstOrFail();
    Livewire::test(ListPanelNotices::class)
        ->assertSuccessful()->assertCanSeeTableRecords([$notice])
        ->callTableAction('leida', $notice)->assertHasNoTableActionErrors();
    expect(DB::table('panel_notice_reads')->count())->toBe(1)->and($case->fresh()->pending_cents)->toBe(2000);
});
it('C104 opens the personal shortage tab from an owned notification', function () {
    [$admin, $owner, $incoming, $case] = c104Fixture();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->actingAs($owner);
    $notice = app(PanelInboxQuery::class)->execute($owner)->firstOrFail();
    Livewire::test(ListPanelNotices::class)
        ->callTableAction('abrir', $notice)->assertRedirect(NoticeDestination::url($notice));
});
it('C104 paginates older notifications instead of hiding them', function () {
    [$admin, $owner, $incoming, $case] = c104Fixture();
    for ($i = 0; $i < 6; $i++) {
        $copy = $case->replicate();
        $copy->source_reference = 'receipt:'.(200 + $i);
        $copy->save();
    }
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->actingAs($owner);
    $page = Livewire::test(ListPanelNotices::class);
    expect($page->instance()->getTableRecords()->count())->toBe(5);
    $page->call('gotoPage', 2);
    expect($page->instance()->getTableRecords()->count())->toBe(2);
});

function c120TimeAppeal(): array
{
    [$admin, $owner, $other] = c104Fixture();
    test()->travelTo(CarbonImmutable::parse('2026-10-14 23:00', 'UTC'));
    config(['cajas.schedule_controls_enabled' => false]);
    $day = app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $owner->id, 'work_date' => '2026-10-14', 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);
    $case = app(DetectTimeIncidentAction::class)->opening($owner, $day, CarbonImmutable::parse('2026-10-14 12:20', 'UTC'), 'c120-opening');
    app(SubmitTimeReasonAction::class)->execute($owner, $case, 'administracion_tardia', 'La tienda todavía estaba cerrada');

    return [$admin, $owner, $other, $case];
}
it('c120 prioritizes a single pending time appeal and highlights a new version without changing debt', function () {
    [$admin, $owner, $other, $case] = c120TimeAppeal();
    $query = app(PanelInboxQuery::class);
    $notice = $query->execute($admin)->where('category', 'time-appeal-review')->firstOrFail();
    expect($notice->priority)->toBe(0);
    expect($notice->actor_name)->toBe($owner->name);
    expect(NoticeDestination::url($notice))->toContain('time-incidents');
    app(ReadPanelNoticeAction::class)->execute($admin, $notice->id);
    app(ReadPanelNoticeAction::class)->execute($admin, $notice->id);
    app(SubmitTimeReasonAction::class)->execute($owner, $case, 'administracion_tardia', 'La tienda todavía estaba cerrada');
    expect($query->execute($admin)->where('category', 'time-appeal-review')->count())->toBe(1);
    expect($query->execute($admin)->where('category', 'time-appeal-review')->first()->read_at)->not->toBeNull();
    app(SubmitTimeReasonAction::class)->execute($owner, $case, 'administracion_tardia', 'Aclaro que Administración abrió después');
    $next = $query->execute($admin)->where('category', 'time-appeal-review')->firstOrFail();
    expect($next->id)->not->toBe($notice->id);
    expect($next->read_at)->toBeNull();
    expect($query->execute($admin)->where('category', 'time-appeal-review')->count())->toBe(1);
    expect(fn () => app(ReadPanelNoticeAction::class)->execute($other, $next->id))->toThrow(AuthorizationException::class);
    expect($case->fresh()->pending_minutes)->toBe(10);
    expect(config('cajas.schedule_controls_enabled'))->toBeFalse();
});
it('c120 notifies only the owner of an appeal decision and keeps one notice on retry', function (string $decision) {
    [$admin, $owner, $other, $case] = c120TimeAppeal();
    $key = app(TimeAppealQuery::class)->execute($owner, $case)['appeal_key'];
    $review = app(ReviewTimeAppealAction::class);
    $review->execute($admin, $case, $key, $decision, 'Respuesta verificada por Administración');
    $review->execute($admin, $case, $key, $decision, 'Respuesta verificada por Administración');
    $query = app(PanelInboxQuery::class);
    expect($query->execute($admin)->where('category', 'time-appeal-review')->count())->toBe(0);
    $notice = $query->execute($owner)->where('category', 'time-appeal-response')->firstOrFail();
    expect($notice->source_status)->toBe($decision);
    expect($notice->summary)->toContain($decision === 'ACEPTAR' ? 'Apelación aceptada' : 'Apelación rechazada');
    expect($query->execute($owner)->where('category', 'time-appeal-response')->count())->toBe(1);
    expect($query->execute($other)->where('category', 'time-appeal-response')->count())->toBe(0);
    expect($query->execute($admin)->where('category', 'time-appeal-response')->count())->toBe(0);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($owner);
    Livewire::test(ListPanelNotices::class)->assertCanSeeTableRecords([$notice])->callTableAction('abrir', $notice)->assertRedirect(NoticeDestination::url($notice));
    expect(NoticeDestination::url($notice))->toContain('profileTab=profile-time-delays');
    $this->get(NoticeDestination::url($notice))->assertOk();
    expect($case->fresh()->pending_minutes)->toBe($decision === 'ACEPTAR' ? 0 : 10);
    expect(DB::table('panel_notice_reads')->where('user_id', $owner->id)->where('notice_key', $notice->id)->count())->toBe(1);
})->with(['ACEPTAR', 'RECHAZAR']);
it('c120 rechecks time permissions before showing or reading notifications', function () {
    [$admin, $owner, $other, $case] = c120TimeAppeal();
    $query = app(PanelInboxQuery::class);
    $notice = $query->execute($admin)->where('category', 'time-appeal-review')->firstOrFail();
    $admin->restrictedPermissions()->attach(Permission::findByName('controlhorario.revisar', 'web'));
    expect($query->execute($admin)->where('category', 'time-appeal-review')->count())->toBe(0);
    expect(fn () => app(ReadPanelNoticeAction::class)->execute($admin, $notice->id))->toThrow(AuthorizationException::class);
});

it('c120 retains earlier responses and separates unread notices for a later appeal', function () {
    [$admin, $owner, $other, $case] = c120TimeAppeal();
    $query = app(PanelInboxQuery::class);
    $key = app(TimeAppealQuery::class)->execute($owner, $case)['appeal_key'];
    app(ReviewTimeAppealAction::class)->execute($admin, $case, $key, 'RECHAZAR', 'Primera explicación no verificada');
    $old = $query->execute($owner)->where('category', 'time-appeal-response')->firstOrFail();
    app(ReadPanelNoticeAction::class)->execute($owner, $old->id);
    app(SubmitTimeReasonAction::class)->execute($owner, $case, 'administracion_tardia', 'Aporto la hora de apertura verificada');
    $newKey = app(TimeAppealQuery::class)->execute($owner, $case)['appeal_key'];
    app(ReviewTimeAppealAction::class)->execute($admin, $case, $newKey, 'ACEPTAR', 'Se verificó la demora');
    $responses = $query->execute($owner)->where('category', 'time-appeal-response')->get();
    expect($responses)->toHaveCount(2);
    expect($responses->whereNull('read_at'))->toHaveCount(1);
    expect($responses->firstWhere('id', $old->id)->read_at)->not->toBeNull();
    $new = $responses->firstWhere('source_status', 'ACEPTAR');
    $owner->restrictedPermissions()->attach(Permission::findByName('controlhorario.ver_propios', 'web'));
    expect($query->execute($owner)->where('category', 'time-appeal-response')->count())->toBe(0);
    expect(fn () => app(ReadPanelNoticeAction::class)->execute($owner, $new->id))->toThrow(AuthorizationException::class);
    expect($case->fresh()->pending_minutes)->toBe(0);
});

it('c120 opens the delays tab by stable key even when another personal tab is hidden', function () {
    [, $owner] = c120TimeAppeal();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($owner);
    $url = EditOwnProfile::getUrl().'?profileTab=profile-time-delays';
    $response = $this->get($url)->assertOk();
    preg_match('/activeTab:\s*(\d+)/', $response->getContent(), $matches);
    expect((int) ($matches[1] ?? 0))->toBe(5);
    $owner->restrictedPermissions()->attach(Permission::findByName('incidencias.ver_propios', 'web'));
    $response = $this->get($url)->assertOk();
    preg_match('/activeTab:\s*(\d+)/', $response->getContent(), $matches);
    expect((int) ($matches[1] ?? 0))->toBe(5);
    $response->assertSee('data-tab-key="profile-time-delays"', false);
});

it('c121 checks a cheap revision without querying the inbox when nothing changed', function () {
    [$admin] = c120TimeAppeal();
    $this->actingAs($admin);
    $screen = Livewire::test(InboxUpdates::class);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $screen->call('checkForUpdates')->assertNotDispatched('panel-inbox-refresh');
    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'notice_items')))->toHaveCount(0);
});
it('c121 signals committed appeals and responses and refreshes only once per revision', function () {
    [$admin, $owner, , $case] = c120TimeAppeal();
    $this->actingAs($admin);
    $screen = Livewire::test(InboxUpdates::class);
    app(SubmitTimeReasonAction::class)->execute($owner, $case, 'administracion_tardia', 'Explicación actualizada');
    $screen->call('checkForUpdates')->assertDispatched('panel-inbox-refresh');
    $screen->call('checkForUpdates')->assertNotDispatched('panel-inbox-refresh');
    $this->actingAs($owner);
    $cashierScreen = Livewire::test(InboxUpdates::class);
    $key = app(TimeAppealQuery::class)->execute($owner, $case)['appeal_key'];
    app(ReviewTimeAppealAction::class)->execute($admin, $case, $key, 'RECHAZAR', 'No se verificó el motivo');
    $cashierScreen->call('checkForUpdates')->assertDispatched('panel-inbox-refresh');
});
it('c121 does not publish rolled back sources and reading changes only the notice signal', function () {
    [$admin, $owner, , $case] = c120TimeAppeal();
    $revision = app(PanelInboxRevision::class);
    $before = $revision->token();
    expect(function () use ($case) {
        DB::transaction(function () use ($case) {
            $case->update(['reason' => 'No debe publicarse']);
            app(TimeHistoryAudit::class)->record($case->user_id, $case->user_id, 'MOTIVO_O_REVISION', 'c121-rollback', ['incident_id' => $case->id, 'reason' => 'No debe publicarse']);
            throw new RuntimeException('Interrupción de prueba');
        });
    })->toThrow(RuntimeException::class);
    expect($revision->token())->toBe($before);
    $notice = app(PanelInboxQuery::class)->execute($admin)->where('category', 'time-appeal-review')->firstOrFail();
    app(ReadPanelNoticeAction::class)->execute($admin, $notice->id);
    expect($revision->token())->not->toBe($before);
    expect($case->fresh()->pending_minutes)->toBe(10);
});
it('c121 detects a permission revocation without needing a new source event', function () {
    [$admin] = c120TimeAppeal();
    $this->actingAs($admin);
    $screen = Livewire::test(InboxUpdates::class);
    $admin->restrictedPermissions()->attach(Permission::findByName('controlhorario.revisar', 'web'));
    $screen->call('checkForUpdates')->assertDispatched('panel-inbox-refresh');
    expect(app(PanelInboxQuery::class)->execute($admin)->where('category', 'time-appeal-review')->count())->toBe(0);
});

it('c121 preserves pagination and filters during automatic refresh and updates the counter on reading', function () {
    [$admin, $owner, , $case] = c104Fixture();
    for ($i = 0; $i < 6; $i++) {
        $copy = $case->replicate();
        $copy->source_reference = 'receipt:c121-'.$i;
        $copy->save();
    }
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($owner);
    $screen = Livewire::test(ListPanelNotices::class)->filterTable('priority', '1')->call('gotoPage', 2);
    expect($screen->instance()->getTableRecords()->count())->toBe(2);
    $screen->dispatch('panel-inbox-refresh');
    expect($screen->instance()->getTableRecords()->count())->toBe(2);
    $notice = $screen->instance()->getTableRecords()->first();
    $screen->callTableAction('leida', $notice)->assertDispatched('panel-inbox-check');
    expect(PanelNoticeResource::getNavigationBadge())->toBe('6');
    Livewire::test(Sidebar::class)->dispatch('panel-inbox-refresh')->assertSuccessful();
});
it('c121 cache failure cannot roll back an appeal and disabling the user stops the updater', function () {
    [$admin, $owner, , $case] = c120TimeAppeal();
    $this->actingAs($owner);
    $screen = Livewire::test(InboxUpdates::class);
    Exceptions::fake();
    config(['notificaciones.revision_store' => 'missing-test-store']);
    app(SubmitTimeReasonAction::class)->execute($owner, $case, 'administracion_tardia', 'Explicación guardada aunque falle la caché');
    Exceptions::assertReported(InvalidArgumentException::class);
    expect($case->fresh()->reason)->toBe('Explicación guardada aunque falle la caché');
    expect($case->fresh()->pending_minutes)->toBe(10);
    config(['notificaciones.revision_store' => 'array']);
    $owner->forceFill(['is_active' => false])->save();
    $screen->call('checkForUpdates')->assertSet('active', false);
    $screen->call('checkForUpdates')->assertNotDispatched('panel-inbox-refresh');
});

it('C124 sends correction responses to the cashier workspace and keeps review links administrative', function () {
    [$admin, $owner] = c104Fixture();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($owner);
    $notice = new PanelNotice;
    $notice->forceFill(['target' => 'correcciones', 'category' => 'correction-response']);
    expect(NoticeDestination::url($notice))->toBe(CashBoxOperations::getUrl(panel: 'inicio'));
    $this->actingAs($admin);
    $notice->forceFill(['category' => 'correction-review']);
    expect(NoticeDestination::url($notice))->toBe(CashBoxCorrectionResource::getUrl(panel: 'inicio'));
});
