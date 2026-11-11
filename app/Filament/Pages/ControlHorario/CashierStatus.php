<?php

namespace App\Filament\Pages\ControlHorario;

use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\ControlHorario\Queries\PersonnelTimePeriodQuery;
use App\Domain\Incidencias\Queries\PersonnelBalancesQuery;
use App\Filament\Resources\Usuarios\Users\UserResource;
use App\Models\User;
use App\Support\Bolivianos;
use App\Support\TimeDuration;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class CashierStatus extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'tarjetas.inventory-movements';

    protected static ?string $title = 'Estado de cajeras';

    protected static string|\UnitEnum|null $navigationGroup = 'Personal';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected array $balances = [];

    public static function canAccess(): bool
    {
        $actor = auth()->check() ? User::find(auth()->id()) : null;

        return $actor instanceof User && $actor->is_active && $actor->hasRole('administrador')
            && $actor->can('controlhorario.revisar') && $actor->can('incidencias.ver_todos');
    }

    public function table(Table $table): Table
    {
        abort_unless(static::canAccess(), 403);

        $credit = TimeMovement::query()->selectRaw('COALESCE(SUM(delta_minutes), 0)')
            ->whereColumn('user_id', 'users.id')->where('bucket', 'CREDITO');
        $today = ScheduledWorkday::query()->selectRaw('COUNT(*)')
            ->whereColumn('user_id', 'users.id')->where('work_date', now('America/La_Paz')->toDateString());

        return $table->query(User::role(['cajera', 'encargado'])->addSelect(['available_credit' => $credit, 'today_workdays' => $today]))
            ->columns([
                TextColumn::make('name')->label('Personal de caja')->searchable()->sortable(),
                TextColumn::make('is_active')->label('Cuenta')->badge()->formatStateUsing(fn ($state) => $state ? 'Activa' : 'Inactiva'),
                TextColumn::make('today_workdays')->label('Jornada de hoy')->badge()->formatStateUsing(fn ($state) => $state ? 'Asignada' : 'Sin jornada registrada'),
                TextColumn::make('pending_time')->label('Tiempo pendiente')->state(fn (User $record) => TimeDuration::format(
                    app(PersonnelTimePeriodQuery::class)->execute(auth()->user(), $record)['pending']
                )),
                TextColumn::make('available_credit')->label('Crédito de tiempo')->formatStateUsing(fn ($state) => TimeDuration::format((int) $state)),
                TextColumn::make('pending_money')->label('Faltantes pendientes')->state(fn (User $record) => 'Bs '.Bolivianos::format($this->money($record)['pending'])),
                TextColumn::make('money_under_review')->label('Faltantes en revisión')->state(fn (User $record) => 'Bs '.Bolivianos::format($this->money($record)['under_review'])),
                TextColumn::make('salary_pay_day')->label('Día de pago')->placeholder('Sin configurar'),
            ])->recordActions([
                Action::make('record')->label('Ver expediente')->icon('heroicon-o-clipboard-document-list')
                    ->url(fn (User $record) => PersonnelRecord::getUrl(['employee' => $record->id])),
                Action::make('profile')->label('Perfil y condiciones')->icon('heroicon-o-user')
                    ->visible(fn (User $record) => UserResource::canEdit($record))
                    ->url(fn (User $record) => UserResource::getUrl('edit', ['record' => $record])),
            ])->defaultSort('name')->paginated([10, 25, 50]);
    }

    protected function money(User $cashier): array
    {
        return $this->balances[$cashier->id] ??= app(PersonnelBalancesQuery::class)->execute(auth()->user(), $cashier);
    }
}
