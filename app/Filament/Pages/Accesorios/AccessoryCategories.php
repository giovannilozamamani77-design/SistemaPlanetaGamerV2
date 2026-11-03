<?php

namespace App\Filament\Pages\Accesorios;

use App\Domain\Accesorios\Actions\CreateAccessoryCategoryAction;
use App\Domain\Accesorios\Actions\InstallDefaultAccessoryCategoriesAction;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Queries\AccessoryRegistrationQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessoryCategories extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'accesorios.categories';

    protected static ?string $title = 'Categorías de inventario';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $u = auth()->user()?->fresh();

        return $u && Gate::forUser($u)->allows('manage', AccessoryProduct::class);
    }

    public function mount(): void
    {
        app(AccessoryRegistrationQuery::class)->categories(auth()->user());
    }

    public static function fields(bool $phone = true): array
    {
        return [Hidden::make('request_key')->default(fn () => (string) Str::uuid()), TextInput::make('name')->label('Nombre de la categoría')->required()->maxLength(100), $phone ? Select::make('tracking')->label('Forma de control')->options(['QUANTITY' => 'Accesorios por cantidad', 'IMEI' => 'Teléfonos por IMEI (siguiente etapa)'])->default('QUANTITY')->required()->native() : Hidden::make('tracking')->default('QUANTITY')];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('createCategory')->label('Nueva categoría')->icon('heroicon-o-plus')->schema(self::fields())->action(function (array $data): void {
            try {
                app(CreateAccessoryCategoryAction::class)->execute(auth()->user(), $data);
            } catch (ValidationException $e) {
                $errors = [];
                $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                foreach ($e->errors() as $key => $messages) {
                    $errors[$path.'.'.$key] = $messages;
                }throw ValidationException::withMessages($errors);
            }
        }), Action::make('installDefaults')->label('Agregar categorías sugeridas')->icon('heroicon-o-squares-plus')->color('gray')->action(function (): void {
            $result = app(InstallDefaultAccessoryCategoriesAction::class)->execute(auth()->user());
            Notification::make()->title('Categorías agregadas: '.$result['created'])->body('Se conservaron '.$result['existing'].' existentes.')->success()->send();
        }), Action::make('inventory')->label('Inventario')->color('gray')->url(fn () => AccessoryInventory::getUrl())];
    }

    public function table(Table $table): Table
    {
        return $table->query(app(AccessoryRegistrationQuery::class)->categories(auth()->user(), false))->columns([TextColumn::make('name')->label('Categoría')->searchable()->sortable(), TextColumn::make('tracking')->label('Control')->formatStateUsing(fn ($state) => $state === 'IMEI' ? 'Teléfonos por IMEI · Próximamente' : 'Por cantidades')->badge(), TextColumn::make('actor_name')->label('Creada por'), TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i', timezone: 'America/La_Paz')])->paginated([10, 25, 50])->emptyStateHeading('Crea tu primera categoría');
    }
}
