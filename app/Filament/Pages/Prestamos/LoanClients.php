<?php

namespace App\Filament\Pages\Prestamos;

use App\Domain\Prestamos\Actions\SaveBorrowerAction;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Prestamos\LoanForms;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class LoanClients extends Page implements HasTable
{
    use HandlesPersonnelActionErrors,InteractsWithTable;

    protected string $view = 'prestamos.clients';

    protected static ?string $title = 'Clientes';

    protected static string|\UnitEnum|null $navigationGroup = 'Préstamos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user()?->fresh();

        return $user && Gate::forUser($user)->allows('viewAny', Loan::class);
    }

    private function canRegister(): bool
    {
        $user = auth()->user()?->fresh();

        return $user && Gate::forUser($user)->allows('create', Loan::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('loans')->label('Préstamos')->icon('heroicon-o-banknotes')->color('gray')->url(fn () => RegisterLoan::canAccess() ? RegisterLoan::getUrl() : LoanList::getUrl()), $this->clientAction('createClient')];
    }

    private function clientAction(string $name): Action
    {
        return Action::make($name)->label($name === 'createClient' ? 'Registrar cliente' : 'Editar')->icon($name === 'createClient' ? 'heroicon-o-user-plus' : 'heroicon-o-pencil-square')->visible(fn () => $this->canRegister())->modalHeading($name === 'createClient' ? 'Registrar cliente' : 'Editar datos del cliente')->modalDescription('Este catálogo es el mismo que utiliza Préstamos. El CI evita duplicados; actualizar el contacto conserva los comprobantes anteriores.')->modalSubmitActionLabel('Guardar cliente')
            ->fillForm(fn (?Borrower $record) => $record ? $record->only(['ci', 'name', 'phone']) + ['expected_version' => $record->profileVersion()] : [])
            ->schema([
                TextInput::make('ci')->label('CI')->required()->maxLength(60)->disabled(fn (?Borrower $record) => $record !== null)->dehydrated(true)->helperText('Conserva los ceros iniciales. Si ya existe, se reutiliza el cliente.'),
                TextInput::make('name')->label('Nombre completo')->required()->maxLength(160), TextInput::make('phone')->label('Teléfono (opcional)')->tel()->maxLength(40), Hidden::make('expected_version'), LoanForms::key(),
            ])->action(function (array $data, ?Borrower $record): void {
                app(SaveBorrowerAction::class)->execute(auth()->user(), $data, $record);
                Notification::make()->title('Datos del cliente guardados')->success()->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table->query(app(LoanQuery::class)->clients(auth()->user()))->heading('Clientes de Préstamos')->description('Busca por CI, nombre o teléfono. Se reutilizan los mismos registros al prestar.')->columns([
            TextColumn::make('ci')->label('CI')->searchable()->sortable(), TextColumn::make('name')->label('Nombre completo')->searchable()->sortable(), TextColumn::make('phone')->label('Teléfono')->searchable()->placeholder('Sin teléfono'),
        ])->recordActions([$this->clientAction('editClient')])->defaultSort('id', 'desc')->paginated([5, 10, 25, 50])->defaultPaginationPageOption(5)->emptyStateHeading('Todavía no hay clientes')->emptyStateDescription('Los clientes registrados desde Préstamos aparecen aquí automáticamente.');
    }
}
