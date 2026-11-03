<?php

namespace App\Filament\Pages\Accesorios;

use App\Domain\Accesorios\Actions\RegisterPhoneUnitAction;
use App\Domain\Accesorios\Models\PhoneBrand;
use App\Domain\Accesorios\Queries\PhoneInventoryQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class RegisterPhone extends Page
{
    protected string $view = 'accesorios.register-phone';

    protected static ?string $title = 'Registrar teléfono';

    protected static bool $shouldRegisterNavigation = false;

    public array $data = [];

    #[Locked]
    public string $requestKey;

    #[Locked]
    public ?array $reviewed = null;

    #[Locked]
    public ?int $registeredId = null;

    #[Locked]
    public int $registeredQuantity = 0;

    public static function canAccess(): bool
    {
        return AccessoryInventory::canAccess() && (new AccessoryInventory)->canManage();
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        app(PhoneInventoryQuery::class)->authorize(auth()->user(), 'manage');
        $this->resetRegistration();
    }

    private function resetRegistration(): void
    {
        $this->requestKey = (string) Str::uuid();
        $this->reviewed = null;
        $this->form->fill(app(PhoneInventoryQuery::class)->defaults(auth()->user()));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('inventory')->label('Inventario de teléfonos')->icon('heroicon-o-device-phone-mobile')->url(fn () => PhoneInventory::getUrl())];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->schema([
            Group::make([
                Section::make('Modelo y variante')->icon('heroicon-o-device-phone-mobile')->iconColor('info')->extraAttributes(['class' => 'pg-registration-section pg-registration-section-identity'])->schema([
                    TextInput::make('brand')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'name']))->label('Marca')->required()->maxLength(80)->datalist(fn () => PhoneBrand::orderBy('name')->limit(50)->pluck('name')->all()),
                    TextInput::make('model')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'phone']))->label('Modelo')->required()->maxLength(100),
                    TextInput::make('ram_gb')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'ram']))->label('RAM · GB')->required()->integer()->minValue(1)->maxValue(128),
                    TextInput::make('storage_gb')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'storage']))->label('Almacenamiento · GB')->required()->integer()->minValue(1)->maxValue(8192),
                    Select::make('condition')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'condition']))->label('Condición')->options(['NEW' => 'Nuevo', 'USED' => 'Usado / seminuevo'])->required()->native()->live(),
                    TextInput::make('color')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'color']))->label('Color')->required()->maxLength(60),
                ])->columns(['default' => 1, 'sm' => 2, 'xl' => 3]),
                Section::make('Detalles del equipo')->description('El IMEI se registra únicamente al vender cada equipo.')->icon('heroicon-o-qr-code')->iconColor('warning')->extraAttributes(['class' => 'pg-registration-section pg-registration-section-identification'])->schema([
                    Textarea::make('physical_state')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'description']))->label('Estado físico (opcional)')->rows(2)->maxLength(1000)->id('phone-state'),
                    Textarea::make('included_accessories')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'quantity']))->label('Accesorios incluidos (opcional)')->rows(2)->maxLength(500),
                ])->columns(2),
            ])->columnSpan(1)->extraAttributes(['class' => 'pg-phone-column']),
            Group::make([
                Section::make('Costo y precio')->description('Los costos en USD usan el cambio global: API + diferencia.')->headerActions([Action::make('priceSettings')->label('Dólar global')->icon('heroicon-o-cog-6-tooth')->visible(fn () => PricingConfiguration::canAccess())->url(fn () => PricingConfiguration::getUrl())])->icon('heroicon-o-banknotes')->iconColor('success')->extraAttributes(['class' => 'pg-registration-section pg-registration-section-pricing'])->schema([
                    Hidden::make('expected_pricing_revision'),
                    TextInput::make('quantity')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'quantity']))->label('Stock a ingresar')->required()->integer()->minValue(1)->maxValue(1000)->default(1),
                    Hidden::make('expenses')->default('0'),
                    View::make('accesorios.phone-cost-preview')->viewData(fn () => ['preview' => app(PhoneInventoryQuery::class)->registrationPreview(auth()->user(), $this->data), 'onlyRate' => true])->visible(fn (Get $get) => $get('condition') === 'NEW'),
                    TextInput::make('purchase_cost')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'unit_cost']))->label(fn (Get $get) => $get('condition') === 'NEW' ? 'Compra por equipo · USD' : 'Compra por equipo · Bs')->required()->live(debounce: 500)->inputMode('decimal'),
                    View::make('accesorios.phone-cost-preview')->viewData(fn () => ['preview' => app(PhoneInventoryQuery::class)->registrationPreview(auth()->user(), $this->data), 'onlyRate' => false])->visible(fn (Get $get) => $get('condition') === 'NEW'),
                    Select::make('price_mode')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'mode']))->label('Precio de venta')->helperText('Manual queda fijo. El porcentaje puede actualizarse con el dólar si Administración lo activa.')->options(['MANUAL' => 'Manual', 'CONFIGURED' => 'Porcentaje configurado'])->required()->native()->live(),
                    TextInput::make('sale_price')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'sale_price']))->label('Precio de venta · Bs')->required(fn (Get $get) => $get('price_mode') === 'MANUAL')->visible(fn (Get $get) => $get('price_mode') === 'MANUAL')->inputMode('decimal'),
                    TextInput::make('origin')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'origin']))->label('Proveedor / procedencia (opcional)')->maxLength(200),
                ])->columns(2),
                Section::make('Imagen (opcional)')->collapsible()->collapsed()->icon('heroicon-o-photo')->iconColor('primary')->extraAttributes(['class' => 'pg-registration-section pg-registration-section-photo'])->schema([
                    FileUpload::make('image')->beforeLabel(fn () => view('accesorios.icons.field-image', ['name' => 'image']))->label('Foto del teléfono')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(5120)->imagePreviewHeight('80')->storeFiles(false)->preventFilePathTampering(allowFilePathUsing: fn () => false)->fetchFileInformation(false),
                ]),
            ])->columnSpan(1)->extraAttributes(['class' => 'pg-phone-column']),
        ])->columns(2);
    }

    private function state(): array
    {
        app(PhoneInventoryQuery::class)->authorize(auth()->user(), 'manage');
        if (($this->data['image'] ?? null) !== null) {
            if (! is_array($this->data['image'])) {
                throw ValidationException::withMessages(['image' => 'Selecciona una imagen válida.']);
            }
            foreach ($this->data['image'] as $file) {
                if (! $file instanceof UploadedFile) {
                    throw ValidationException::withMessages(['image' => 'Selecciona un archivo; no se admiten rutas existentes.']);
                }
            }
        }

        return array_replace($this->form->getState(), ['imei1' => null, 'imei2' => null]);
    }

    private function formErrors(ValidationException $e): ValidationException
    {
        $errors = [];
        foreach ($e->errors() as $key => $messages) {
            $errors[str_starts_with($key, 'data.') ? $key : 'data.'.$key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }

    public function reviewPhone(): void
    {
        try {
            $state = $this->state();
            $state['expected_pricing_revision'] = app(PhoneInventoryQuery::class)->defaults(auth()->user())['expected_pricing_revision'];
            $this->data['expected_pricing_revision'] = $state['expected_pricing_revision'];
            $this->reviewed = app(PhoneInventoryQuery::class)->review(auth()->user(), $state + ['request_key' => $this->requestKey], $state['image'] ?? null);
        } catch (ValidationException $e) {
            throw $this->formErrors($e);
        }
        $this->mountAction('confirmPhone');
    }

    public function confirmPhoneAction(): Action
    {
        return Action::make('confirmPhone')->label('Confirmar teléfono')->modalHeading('Revisa el equipo antes de registrarlo')->modalWidth(Width::TwoExtraLarge)->modalSubmitActionLabel('Confirmar registro')->modalCancelActionLabel('Volver y corregir')->closeModalByClickingAway(false)->modalContent(fn () => view('accesorios.phone-review', ['review' => $this->reviewed]))->action(function (): void {
            try {
                if (! $this->reviewed) {
                    throw ValidationException::withMessages(['quantity' => 'Revisa el stock antes de confirmar.']);
                }
                $state = $this->state();
                $p = app(RegisterPhoneUnitAction::class)->execute(auth()->user(), $state + ['request_key' => $this->requestKey], $state['image'] ?? null, $this->reviewed['fingerprint']);
            } catch (ValidationException $e) {
                throw $this->formErrors($e);
            } catch (QueryException $e) {
                report($e);
                Notification::make()->title('No se pudo registrar el equipo')->body('Vuelve a intentar el registro. Tus datos se conservan y el stock no se duplica.')->danger()->send();

                return;
            }
            $this->registeredId = $p->id;
            $this->registeredQuantity = (int) ($p->registration_snapshot['quantity'] ?? 1);
            $this->resetRegistration();
            Notification::make()->title('Teléfono registrado')->success()->send();
        });
    }
}
