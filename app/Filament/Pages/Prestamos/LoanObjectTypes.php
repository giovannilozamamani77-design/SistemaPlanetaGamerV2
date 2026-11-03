<?php

namespace App\Filament\Pages\Prestamos;

use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Prestamos\LoanForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class LoanObjectTypes extends Page implements HasTable
{
    use HandlesPersonnelActionErrors,InteractsWithTable;

    protected string $view = 'prestamos.object-types';

    protected static ?string $title = 'Tipos de objeto';

    protected static string|\UnitEnum|null $navigationGroup = 'Préstamos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        $user = auth()->user()?->fresh();

        return $user && Gate::forUser($user)->allows('viewAny', Loan::class) && Gate::forUser($user)->allows('configureObjects', Loan::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('Préstamos')->color('gray')->icon('heroicon-o-arrow-left')->url(LoanList::getUrl()), $this->typeAction('createType')];
    }

    private function typeAction(string $name): Action
    {
        return Action::make($name)->label($name === 'createType' ? 'Nuevo tipo de objeto' : 'Editar')->icon('heroicon-o-archive-box')->modalHeading($name === 'createType' ? 'Crear tipo de objeto' : 'Editar tipo de objeto')->modalDescription('Crea una categoría, por ejemplo Garrafa o Teléfono. El objeto concreto y sus características se registran al hacer el préstamo.')->modalSubmitActionLabel('Guardar tipo')->fillForm(fn (?LoanObjectType $record) => $record ? ['name' => $record->name, 'rate' => Bolivianos::formatCompact($record->rate_bps), 'is_active' => $record->is_active, 'expected_revision' => $record->revision] : ['rate' => '15', 'is_active' => true, 'expected_revision' => 0])->schema([TextInput::make('name')->label('Nombre del tipo')->placeholder('Ejemplo: Garrafa')->required()->maxLength(120), TextInput::make('rate')->label('Interés para 30 días')->suffix('%')->default('15')->helperText('Para 15 días se aplica la mitad. Cambiar esta tasa solo afecta préstamos nuevos.')->required(), Toggle::make('is_active')->label('Disponible para préstamos nuevos')->helperText('Desactívalo si ya no se acepta este tipo. Sus préstamos anteriores se conservan.')->default(true), Hidden::make('expected_revision'), LoanForms::key()])->action(function (array $data, ?LoanObjectType $record): void {
            app(SaveLoanTypeAction::class)->execute(auth()->user(), $data, $record);
            Notification::make()->title('Tipo y tarifa guardados; préstamos anteriores conservados')->success()->send();
        });
    }

    public function table(Table $table): Table
    {
        app(LoanAuthorization::class)->authorize(auth()->user(), 'configureObjects');

        return $table->query(app(LoanQuery::class)->types(auth()->user()))->heading('Objetos que aceptamos en garantía')->description('Configura el nombre, interés y disponibilidad. Los cambios no modifican préstamos anteriores.')->columns([
            TextColumn::make('name')->label('Tipo de objeto')->searchable()->sortable(),
            TextColumn::make('rate_bps')->label('Interés · 30 días')->formatStateUsing(fn ($state) => Bolivianos::formatCompact($state).' %'),
            TextColumn::make('is_active')->label('Disponibilidad')->formatStateUsing(fn ($state) => $state ? 'Disponible' : 'Desactivado')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
        ])->filters([SelectFilter::make('is_active')->label('Disponibilidad')->options(['1' => 'Disponibles', '0' => 'Desactivados'])])->recordActions([$this->typeAction('editType')])->paginated([5, 10, 25])->defaultPaginationPageOption(5)->defaultSort('name')->emptyStateHeading('Crea el primer tipo de objeto')->emptyStateDescription('Registra una categoría y su interés. No necesitas configurar el fondo para crearla.');
    }
}
