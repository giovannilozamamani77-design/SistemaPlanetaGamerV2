<?php

namespace App\Filament\Pages\ControlHorario;

use App\Domain\ControlHorario\Actions\AuthorizeShiftCoverageAction;
use App\Domain\ControlHorario\Models\ShiftCoverage;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Personal\PersonnelTable;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ShiftCoverages extends Page implements HasTable
{
    use HandlesPersonnelActionErrors;
    use InteractsWithTable;

    protected string $view = 'control_horario.shift-coverages';

    protected static ?string $title = 'Coberturas por día';

    protected static string|\UnitEnum|null $navigationGroup = 'Personal';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return AssignedShifts::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Autoriza quién cubre un turno concreto. No cambia los horarios habituales ni aprueba el permiso de la titular.';
    }

    protected function getHeaderActions(): array
    {
        $people = fn () => User::role(['cajera', 'encargado'])->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();

        return [PersonnelTable::modal(Action::make('authorizeCoverage'))->label('Autorizar cobertura')->icon('heroicon-o-user-plus')->modalHeading('Cobertura de un solo día')
            ->modalDescription('La sustituta usa su propio PIN y sesión. La caja debe estar disponible. Si cubre mañana y pertenece a tarde, puede terminar la cobertura o continuar hasta tarde; su crédito no se duplica.')
            ->schema([Section::make('Personas y horario')->columns(2)->schema([
                Select::make('source_user_id')->label('Cajera a cubrir')->options($people)->searchable()->required(),
                Select::make('user_id')->label('Cajera que cubrirá')->options($people)->searchable()->required(),
                DatePicker::make('work_date')->label('Fecha de cobertura')->default(fn () => now('America/La_Paz')->toDateString())->required(),
                Select::make('period')->label('Turno que se cubre')->options(['manana' => 'Mañana', 'tarde' => 'Tarde'])->required(),
                TimePicker::make('starts_time')->label('Inicio de cobertura')->seconds(false)->helperText('Hora desde la que debe cubrir; no se atribuye una llegada anterior.')->required(),
                Textarea::make('reason')->label('Motivo de la cobertura')->rows(2)->maxLength(2000)->required(),
                Hidden::make('request_key')->default(fn () => (string) Str::uuid()),
            ])])->action(function (array $data): void {
                app(AuthorizeShiftCoverageAction::class)->execute(auth()->user(), $data);
                Notification::make()->title('Cobertura autorizada')->success()->send();
            }),
            Action::make('habits')->label('Turnos habituales')->color('gray')->url(fn () => AssignedShifts::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query(ShiftCoverage::with(['user', 'sourceWorkday.user', 'workday', 'creditWorkday']))
            ->columns([
                TextColumn::make('work_date')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('sourceWorkday.user.name')->label('Cubre a')->searchable(),
                TextColumn::make('user.name')->label('Sustituta')->searchable()->weight('semibold'),
                TextColumn::make('workday.period')->label('Turno')->formatStateUsing(fn (string $state) => $state === 'tarde' ? 'Tarde' : 'Mañana')->badge(),
                TextColumn::make('starts_at')->label('Desde')->dateTime('H:i')->timezone('America/La_Paz'),
                TextColumn::make('ends_at')->label('Hasta')->dateTime('H:i')->timezone('America/La_Paz'),
                TextColumn::make('reason')->label('Motivo')->limit(65)->wrap(),
                TextColumn::make('created_at')->label('Autorizado')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->toggleable(isToggledHiddenByDefault: true),
            ])->filters([SelectFilter::make('user_id')->label('Sustituta')->relationship('user', 'name')->searchable()])
            ->paginated([5, 10, 25])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')->emptyStateHeading('No hay coberturas autorizadas');
    }
}
