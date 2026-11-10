<?php

namespace App\Filament\Pages\Accesorios;

use App\Domain\Accesorios\Actions\CreateAccessoryCategoryAction;
use App\Domain\Accesorios\Actions\RegisterAccessoryProductAction;
use App\Domain\Accesorios\Queries\AccessoryRegistrationQuery;
use App\Domain\Accesorios\Rules\AccessoryName;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class RegisterAccessoryProduct extends Page
{
    protected string $view = 'accesorios.register-product';

    protected static ?string $title = 'Registrar accesorio';

    protected static bool $shouldRegisterNavigation = false;

    public array $data = [];

    #[Locked]
    public string $requestKey;

    #[Locked]
    public bool $priceEdited = false;

    #[Locked]
    public ?array $quote = null;

    #[Locked]
    public ?array $reviewed = null;

    #[Locked]
    public ?string $reviewImageUrl = null;

    #[Locked]
    public ?int $registeredProductId = null;

    public ?string $quoteError = null;

    #[Locked]
    public ?string $confirmationError = null;

    public static function canAccess(): bool
    {
        return AccessoryCategories::canAccess();
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        $this->resetProduct();
    }

    private function resetProduct(): void
    {
        $this->requestKey = (string) Str::uuid();
        $this->priceEdited = false;
        $this->quote = null;
        $this->quoteError = null;
        $this->reviewed = null;
        $this->reviewImageUrl = null;
        $this->form->fill(app(AccessoryRegistrationQuery::class)->defaults(auth()->user()));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('inventory')->label('Inventario')->icon('heroicon-o-cube')->color('gray')->url(fn () => AccessoryInventory::getUrl()), Action::make('categories')->label('Categorías')->icon('heroicon-o-squares-2x2')->color('gray')->url(fn () => AccessoryCategories::getUrl())];
    }

    public function form(Schema $schema): Schema
    {
        $image = fn (string $name, string $label) => FileUpload::make($name)->label($label)->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => $name]))->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(5120)->storeFiles(false)->preventFilePathTampering(allowFilePathUsing: fn () => false)->fetchFileInformation(false)->imagePreviewHeight('90')->helperText('JPG, PNG o WebP · Máx. 5 MB.');

        return $schema->statePath('data')->schema([
            Group::make([
                Section::make('Identificación del accesorio')->icon('heroicon-o-cube')->iconColor('info')->extraAttributes(['class' => 'pg-registration-section pg-registration-section-identity'])->schema([
                    TextInput::make('code')->label('Código de barras o código interno')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'code']))->inputMode('text')->required()->maxLength(60)->id('registration-code')->autofocus()->extraInputAttributes(['autocomplete' => 'off', 'autocapitalize' => 'off', 'x-on:keydown.enter.prevent' => "if (\$event.target.value.trim()) document.getElementById('registration-name')?.focus()"]),
                    TextInput::make('name')->label('Nombre del accesorio')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'name']))->required()->maxLength(160)->rules([new AccessoryName])->inputMode('text')->extraInputAttributes(['autocomplete' => 'off'])->id('registration-name'),
                    Select::make('category_id')->label('Categoría')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'category']))->required()->searchable()->position('bottom')->options(fn () => app(AccessoryRegistrationQuery::class)->categories(auth()->user())->limit(50)->pluck('name', 'id')->all())->getSearchResultsUsing(fn (string $search) => app(AccessoryRegistrationQuery::class)->categories(auth()->user())->where('name', 'like', '%'.mb_substr($search, 0, 80).'%')->limit(50)->pluck('name', 'id')->all())->getOptionLabelUsing(fn ($value) => app(AccessoryRegistrationQuery::class)->categories(auth()->user())->whereKey($value)->value('name'))->createOptionForm(AccessoryCategories::fields(false))->createOptionUsing(fn (array $data) => $this->createCategory($data)),
                    Textarea::make('description')->label('Detalle (opcional)')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'description']))->rows(2)->maxLength(1000),
                ])->columnSpan(1),
            ])->columnSpan(1)->extraAttributes(['class' => 'pg-product-column pg-product-column-left']),
            Group::make([
                Section::make('Costo, precio y existencias')->icon('heroicon-o-banknotes')->iconColor('success')->extraAttributes(['class' => 'pg-registration-section pg-registration-section-pricing'])->schema([
                    Hidden::make('expected_pricing_revision'), Toggle::make('cost_pending')->label('Costo de compra pendiente')->live()->afterStateUpdated(fn () => $this->refreshQuote()), TextInput::make('unit_cost')->label('Compra por unidad · Bs')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'unit_cost']))->hidden(fn ($get) => $get('cost_pending'))->required(fn ($get) => ! $get('cost_pending'))->inputMode('decimal')->live(debounce: 500)->afterStateUpdated(fn () => $this->refreshQuote()),
                    TextInput::make('sale_price')->label('Venta por unidad · Bs')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'sale_price']))->required()->inputMode('decimal')->live(debounce: 500)->afterStateUpdated(function (): void {
                        $this->priceEdited = true;
                        $this->refreshQuote();
                    }),
                    TextInput::make('quantity')->label('Stock inicial')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'quantity']))->inputMode('numeric')->required()->integer()->minValue(0)->maxValue(100000), TextInput::make('minimum_stock')->label('Stock mínimo (opcional)')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'minimum_stock']))->inputMode('numeric')->integer()->minValue(0)->maxValue(100000),
                ])->columns(2)->columnSpan(1),
                Section::make('Imagen del producto (opcional)')->icon('heroicon-o-photo')->iconColor('primary')->extraAttributes(['class' => 'pg-registration-section pg-registration-section-photo'])->collapsible()->schema([$image('image', 'Elegir imagen desde archivos'), Group::make([$image('camera_image', 'Tomar foto con cámara')->helperText('Captura desde un móvil compatible.')->extraInputAttributes(['capture' => 'environment'])])->extraAttributes(['class' => 'pg-product-camera-container'])])->columns(1),
            ])->columnSpan(1)->extraAttributes(['class' => 'pg-product-column pg-product-column-right']),
        ])->columns(2);
    }

    public function refreshQuote(bool $force = false): void
    {
        $this->quote = null;
        $this->quoteError = null;
        if (($this->data['cost_pending'] ?? false) || blank($this->data['unit_cost'] ?? null)) {
            return;
        }
        try {
            $q = app(AccessoryRegistrationQuery::class);
            $base = $q->quote(auth()->user(), $this->data['unit_cost']);
            $this->data['expected_pricing_revision'] = $base['settings_revision'];
            if (($force || ! $this->priceEdited || blank($this->data['sale_price'] ?? null)) && $base['suggested_price_cents'] !== null) {
                $this->data['sale_price'] = Bolivianos::formatCompact($base['suggested_price_cents']);
                $this->priceEdited = false;
            }
            $this->quote = $q->quote(auth()->user(), $this->data['unit_cost'], $this->data['sale_price'] ?? null);
        } catch (ValidationException $e) {
            $this->quoteError = collect($e->errors())->flatten()->first();
        }
    }

    private function validatedState(): array
    {
        app(AccessoryRegistrationQuery::class)->categories(auth()->user());
        foreach (['image', 'camera_image'] as $field) {
            $raw = $this->data[$field] ?? null;
            if ($raw !== null && ! is_array($raw)) {
                throw ValidationException::withMessages([$field => 'Selecciona una imagen válida desde archivos o cámara.']);
            }
            foreach ($raw ?? [] as $file) {
                if (! $file instanceof UploadedFile) {
                    throw ValidationException::withMessages([$field => 'No se admiten rutas de imágenes existentes. Selecciona un archivo.']);
                }
            }
        }

        return $this->form->getState();
    }

    private function createCategory(array $data): int
    {
        try {
            return app(CreateAccessoryCategoryAction::class)->execute(auth()->user(), $data)->id;
        } catch (ValidationException $e) {
            $errors = [];
            $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
            foreach ($e->errors() as $key => $messages) {
                $errors[$path.'.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    private function selectedImage(array $state): mixed
    {
        if (! empty($state['image']) && ! empty($state['camera_image'])) {
            throw ValidationException::withMessages(['image' => 'Elige una sola imagen: archivo o cámara.']);
        }

        return $state['image'] ?? $state['camera_image'] ?? null;
    }

    private function formErrors(ValidationException $e): ValidationException
    {
        $errors = [];
        foreach ($e->errors() as $key => $messages) {
            $errors[str_starts_with($key, 'data.') ? $key : 'data.'.$key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }

    public function reviewProduct(): void
    {
        $this->registeredProductId = null;
        $this->confirmationError = null;
        try {
            $state = $this->validatedState();
            $image = $this->selectedImage($state);
            $this->reviewed = app(AccessoryRegistrationQuery::class)->review(auth()->user(), $state + ['request_key' => $this->requestKey], $image);
            $this->reviewImageUrl = $image instanceof TemporaryUploadedFile ? $image->temporaryUrl() : null;
        } catch (ValidationException $e) {
            throw $this->formErrors($e);
        }$this->mountAction('confirmProduct');
    }

    public function confirmProductAction(): Action
    {
        return Action::make('confirmProduct')->label('Confirmar registro')->modalHeading('¿Los datos del accesorio son correctos?')->modalWidth(Width::TwoExtraLarge)->modalSubmitActionLabel('Confirmar y registrar')->modalCancelActionLabel('Volver y corregir')->closeModalByClickingAway(false)->modalContent(fn () => view('accesorios.product-registration-review', ['review' => $this->reviewed, 'imageUrl' => $this->reviewImageUrl, 'confirmationError' => $this->confirmationError]))->action(function (): void {
            $this->confirmationError = null;
            try {
                $state = $this->validatedState();
                $image = $this->selectedImage($state);
                $input = app(AccessoryRegistrationQuery::class)->input(auth()->user(), $state + ['request_key' => $this->requestKey], $image);
                if (! $this->reviewed || $input !== $this->reviewed['values']) {
                    throw ValidationException::withMessages(['code' => 'Los datos cambiaron. Vuelve a revisar antes de registrar.']);
                }
                $p = app(RegisterAccessoryProductAction::class)->execute(auth()->user(), $state + ['request_key' => $this->requestKey], $image);
            } catch (ValidationException $e) {
                $this->confirmationError = collect($e->errors())->flatten()->first();

                throw $this->formErrors($e);
            } catch (QueryException $e) {
                report($e);
                $this->confirmationError = 'No se pudo guardar el accesorio. Tus datos se conservaron. Revisa el inventario y vuelve a intentar.';
                Notification::make()->title('No se pudo guardar el accesorio')->body('Tus datos se conservaron. Revisa el inventario y vuelve a intentar.')->danger()->send();

                return;
            }
            $this->registeredProductId = $p->id;
            $this->resetProduct();
            Notification::make()->title('Accesorio registrado con su stock inicial')->success()->send();
            $this->dispatch('accessory-product-ready');
        });
    }
}
