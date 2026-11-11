<?php

use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Filament\Pages\ControlHorario\PersonnelRecord;
use App\Models\User;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrador');
    $this->cashier = User::factory()->create();
    $this->cashier->assignRole('cajera');
    $this->manager = User::factory()->create();
    $this->manager->assignRole('encargado');
    $this->actingAs($this->admin);
});

it('opens every section for the selected employee and manager without exposing other employees', function () {
    $incidents = collect([$this->cashier, $this->manager])->map(function ($user) {
        $day = app(SaveScheduledWorkdayAction::class)->execute($this->admin, ['user_id' => $user->id, 'work_date' => now()->addDay()->toDateString(), 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);

        return TimeIncident::create(['user_id' => $user->id, 'scheduled_workday_id' => $day->id, 'kind' => 'RETRASO', 'status' => 'CONFIRMADA', 'source_key' => (string) Str::uuid(), 'real_minutes' => 2, 'nominal_minutes' => 4, 'pending_minutes' => 4, 'observed_at' => $day->starts_at->addMinutes(17), 'calculation' => ['tolerance' => 15]]);
    });
    foreach ([$this->cashier, $this->manager] as $index => $employee) {
        $screen = Livewire::withQueryParams(['employee' => $employee->id])->test(PersonnelRecord::class)
            ->assertSuccessful()->assertSee($employee->name)
            ->assertCanSeeTableRecords([$incidents[$index]])->assertCanNotSeeTableRecords([$incidents[1 - $index]]);
        foreach (['money', 'leave', 'days', 'openings', 'commissions', 'time'] as $section) {
            $screen->call('selectSection', $section)->assertSuccessful();
        }
        $screen->assertCanSeeTableRecords([$incidents[$index]])->assertCanNotSeeTableRecords([$incidents[1 - $index]]);
    }
});

it('rejects non administrators inactive administrators and unrelated profiles', function () {
    foreach ([$this->cashier, $this->manager] as $actor) {
        $this->actingAs($actor)->get(PersonnelRecord::getUrl(['employee' => $this->cashier->id]))->assertForbidden();
    }
    $this->actingAs($this->admin)->get(PersonnelRecord::getUrl(['employee' => $this->admin->id]))->assertNotFound();
    $this->admin->forceFill(['is_active' => false])->save();
    $this->get(PersonnelRecord::getUrl(['employee' => $this->cashier->id]))->assertForbidden();
});

it('resolves a delay with an audited reason without deleting it or touching another employee', function () {
    $cases = collect([$this->cashier, $this->manager])->map(function ($user) {
        $day = app(SaveScheduledWorkdayAction::class)->execute($this->admin, ['user_id' => $user->id, 'work_date' => now()->addDay()->toDateString(), 'period' => 'manana', 'starts_time' => '08:00', 'ends_time' => '14:00']);

        return TimeIncident::create(['user_id' => $user->id, 'scheduled_workday_id' => $day->id, 'kind' => 'RETRASO', 'status' => 'CONFIRMADA', 'source_key' => (string) Str::uuid(), 'real_minutes' => 2, 'nominal_minutes' => 4, 'pending_minutes' => 4, 'observed_at' => $day->starts_at->addMinutes(17), 'calculation' => ['tolerance' => 15]]);
    });
    $screen = Livewire::withQueryParams(['employee' => $this->cashier->id])->test(PersonnelRecord::class);
    $screen->callTableAction('revisar', $cases[0], data: ['decision' => 'PERDONAR', 'reason' => 'Revisión administrativa y llamada de atención', 'exception' => false])->assertHasNoTableActionErrors();
    expect($cases[0]->fresh()->status)->toBe('PERDONADA')->and($cases[0]->fresh()->pending_minutes)->toBe(0)
        ->and($cases[1]->fresh()->pending_minutes)->toBe(4)->and(TimeIncident::count())->toBe(2);
    $this->assertDatabaseHas('time_movements', ['time_incident_id' => $cases[0]->id, 'delta_minutes' => -4]);
    $screen->mountTableAction('revisar', $cases[1])->assertTableActionNotMounted('revisar');
});

it('shows the recorded opening and its tolerance calculation separately from unlinked openings', function () {
    $day = app(SaveScheduledWorkdayAction::class)->execute($this->admin, ['user_id' => $this->manager->id, 'work_date' => now()->addDay()->toDateString(), 'period' => 'tarde', 'starts_time' => '14:00', 'ends_time' => '20:00']);
    TimeIncident::create(['user_id' => $this->manager->id, 'scheduled_workday_id' => $day->id, 'kind' => 'RETRASO', 'status' => 'CONFIRMADA', 'source_key' => (string) Str::uuid(), 'real_minutes' => 2, 'nominal_minutes' => 4, 'pending_minutes' => 4, 'observed_at' => $day->starts_at->addMinutes(16)->addSeconds(36), 'calculation' => ['tolerance' => 15]]);
    $audit = DB::table('user_audits')->insertGetId(['actor_user_id' => $this->manager->id, 'subject_user_id' => $this->manager->id, 'action' => 'pin_verified', 'changes' => '{}', 'created_at' => now()]);
    foreach (['DONE', 'SKIPPED'] as $status) {
        CashBoxScheduleEvent::create(['user_id' => $this->manager->id, 'source_key' => (string) Str::uuid(), 'kind' => 'OPENING', 'pin_audit_id' => $audit, 'occurred_at' => $day->starts_at->addMinutes(16)->addSeconds(36), 'status' => $status, 'scheduled_workday_id' => $status === 'DONE' ? $day->id : null]);
    }
    Livewire::withQueryParams(['employee' => $this->manager->id])->test(PersonnelRecord::class)->call('selectSection', 'openings')
        ->assertSee('14:16:36')->assertSee('2 min reales')->assertSee('4 pendientes')->assertSee('tolerancia 15 min')->assertSee('Revisar jornada y control de la caja');
});
