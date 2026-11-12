<?php

namespace App\Filament\Pages\ControlHorario;

use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\ControlHorario\Queries\PersonnelTimePeriodQuery;
use App\Domain\Incidencias\Queries\PersonnelBalancesQuery;
use App\Filament\Resources\Usuarios\Users\UserResource;
use App\Models\User;
use App\Support\TimeDuration;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
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

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): ?string
    {
        return 'Elige un perfil para revisar su asistencia, resolver incidencias y consultar sus comisiones.';
    }

    public function table(Table $table): Table
    {
        abort_unless(static::canAccess(), 403);

        $credit = TimeMovement::query()->selectRaw('COALESCE(SUM(delta_minutes), 0)')
            ->whereColumn('user_id', 'users.id')->where('bucket', 'CREDITO');
        $today = ScheduledWorkday::query()->selectRaw('COUNT(*)')
            ->whereColumn('user_id', 'users.id')->where('work_date', now('America/La_Paz')->toDateString());

        return $table->query(User::role(['cajera', 'encargado'])->with('roles')->addSelect(['available_credit' => $credit, 'today_workdays' => $today]))
            ->columns([
                Stack::make([
                    ViewColumn::make('name')->label('Perfil')->searchable(['name', 'username'])->view('usuarios.personnel-profile-card')
                        ->viewData(fn (User $record): array => [
                            'pendingTime' => TimeDuration::format(app(PersonnelTimePeriodQuery::class)->execute(auth()->user(), $record)['pending']),
                            'money' => $this->money($record),
                        ]),
                ]),
            ])->recordActions([
                Action::make('record')->label('Entrar al perfil')->button()->icon('heroicon-o-clipboard-document-list')
                    ->url(fn (User $record) => PersonnelRecord::getUrl(['employee' => $record->id])),
                Action::make('profile')->label('Perfil y condiciones')->icon('heroicon-o-user')
                    ->visible(fn (User $record) => UserResource::canEdit($record))
                    ->url(fn (User $record) => UserResource::getUrl('edit', ['record' => $record])),
            ])->contentGrid(['md' => 2, '2xl' => 3])->recordUrl(null)
            ->extraAttributes(['class' => 'pg-user-cards pg-personnel-profiles'])
            ->searchPlaceholder('Buscar por nombre o usuario')->emptyStateHeading('No hay perfiles para mostrar')
            ->defaultSort('name')->paginated([6, 12, 24])->defaultPaginationPageOption(6);
    }

    protected function money(User $cashier): array
    {
        return $this->balances[$cashier->id] ??= app(PersonnelBalancesQuery::class)->execute(auth()->user(), $cashier);
    }
}
