<?php

namespace App\Filament\Pages\Accesorios;

use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Actions\ReleasePhoneReservationAction;
use App\Domain\Accesorios\Data\AccessorySaleInput;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Domain\Accesorios\Queries\PhoneSaleQuery;
use App\Domain\Accesorios\Queries\SaleCustomerQuery;
use App\Filament\Support\Accesorios\AccessoryCashDepositForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class AccessoryQuickSale extends Page
{
    use WithPagination;

    protected string $view = 'accesorios.quick-sale';

    protected static ?string $title = 'Venta rápida';

    protected static string|\UnitEnum|null $navigationGroup = 'Accesorios';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = -1;

    #[Locked]
    public string $catalogueMode = 'ACCESSORY';

    #[Locked]
    public array $phoneCart = [];

    #[Locked]
    public array $reviewedPhoneCart = [];

    public string $search = '';

    public string $code = '';

    #[Locked]
    public array $cart = [];

    #[Locked]
    public array $reviewedCart = [];

    #[Locked]
    public ?array $reviewSummary = null;

    #[Locked]
    public string $requestKey;

    #[Locked]
    public ?int $lastSaleId = null;

    public static function canAccess(): bool
    {
        $u = auth()->user()?->fresh();

        return $u && Gate::forUser($u)->allows('create', AccessorySale::class);
    }

    public function getHeading(): string|Htmlable|null
    {
        return view('accesorios.quick-sale-heading', ['responsible' => auth()->user()->name]);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        app(AccessorySaleQuery::class)->authorize(auth()->user());
        $this->requestKey = (string) Str::uuid();
    }

    public function updatedSearch(): void
    {
        $this->resetPage('productsPage');
        $this->resetPage('phonesPage');
    }

    protected function getViewData(): array
    {
        $q = app(AccessorySaleQuery::class);
        $summary = null;
        $warning = null;
        if ($this->cart || $this->phoneCart) {
            try {
                $summary = $q->preview(auth()->user(), ['lines' => $this->cart, 'phone_lines' => $this->phoneCart, 'request_key' => $this->requestKey]);
            } catch (ValidationException $e) {
                $warning = collect($e->errors())->flatten()->first();
            }
        }

        return ['products' => $this->catalogueMode === 'ACCESSORY' ? $q->catalogue(auth()->user(), $this->search) : null, 'phones' => $this->catalogueMode === 'PHONE' ? app(PhoneSaleQuery::class)->catalogue(auth()->user(), $this->search) : null, 'summary' => $summary, 'cartWarning' => $warning, 'cashCents' => $q->cash(auth()->user())];
    }

    protected function getHeaderActions(): array
    {
        return [
            AccessoryCashDepositForms::action(),
            Action::make('history')->label('Historial')->icon('heroicon-o-clock')->color('gray')->visible(fn () => Gate::forUser(auth()->user()->fresh())->allows('viewAny', AccessorySale::class))->modalHeading('Historial de ventas de accesorios y teléfonos')->modalSubmitAction(false)->modalWidth(Width::SevenExtraLarge)->modalContent(fn () => view('accesorios.sale-history-modal', ['historyKey' => (string) Str::uuid()])),
            Action::make('inventory')->label('Inventario')->icon('heroicon-o-cube')->color('gray')->visible(fn () => AccessoryInventory::canAccess())->url(fn () => AccessoryInventory::getUrl()),
        ];
    }

    public function setCatalogue(string $mode): void
    {
        Validator::make(['mode' => $mode], ['mode' => ['required', Rule::in(['ACCESSORY', 'PHONE'])]])->validate();
        app(AccessorySaleQuery::class)->authorize(auth()->user());
        if ($mode === 'PHONE') {
            app(AccessorySaleQuery::class)->authorize(auth()->user(), 'viewAny');
        }
        $this->catalogueMode = $mode;
        $this->search = '';
        $this->code = '';
        $this->updatedSearch();
    }

    public function addPhone(int $unitId): void
    {
        if (count($this->cart) + count($this->phoneCart) >= 25 && ! collect($this->phoneCart)->contains('unit_id', $unitId)) {
            throw ValidationException::withMessages(['cart' => 'La venta admite hasta 25 productos distintos.']);
        }
        $this->phoneCart = app(PhoneSaleQuery::class)->add(auth()->user(), $this->phoneCart, $unitId, $this->requestKey);
        $this->reviewedPhoneCart = [];
        $this->reviewedCart = [];
        $this->reviewSummary = null;
        $this->resetValidation();
    }

    public function increasePhone(int $unitId): void
    {
        $this->resetValidation();
        $next = app(PhoneSaleQuery::class)->nextMatchingUnit(auth()->user(), $this->phoneCart, $unitId, $this->requestKey);
        $this->addPhone($next);
    }

    public function removePhone(int $unitId): void
    {
        app(ReleasePhoneReservationAction::class)->execute(auth()->user(), $this->requestKey, $unitId);
        $this->phoneCart = array_values(array_filter($this->phoneCart, fn ($line) => $line['unit_id'] !== $unitId));
        $this->reviewedPhoneCart = [];
        $this->reviewedCart = [];
        $this->reviewSummary = null;
        $this->resetValidation();
    }

    public function phoneReceiptAction(): Action
    {
        return Action::make('phoneReceipt')->label('Recibo de venta')->modalHeading('Venta registrada')->modalSubmitAction(false)->modalCancelAction(false)->extraModalWindowAttributes(['class' => 'pg-receipt-ready-modal'])->modalWidth(Width::TwoExtraLarge)->modalContent(fn () => view('accesorios.sale-receipt-ready', ['receipt' => app(AccessorySaleQuery::class)->receipt(auth()->user(), $this->lastSaleId)]));
    }

    public function addProduct(int $productId, mixed $quantity = 1): void
    {
        $this->resetValidation();
        try {
            if (count($this->cart) + count($this->phoneCart) >= 25 && ! collect($this->cart)->contains('product_id', $productId)) {
                throw ValidationException::withMessages(['cart' => 'La venta admite hasta 25 productos distintos.']);
            }
            $next = app(AccessorySaleQuery::class)->add(auth()->user(), $this->cart, $productId, $quantity);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['cart' => collect($e->errors())->flatten()->first()]);
        }
        $this->cart = $next;
        $this->reviewedPhoneCart = [];
        $this->reviewedCart = [];
        $this->reviewSummary = null;
        $this->dispatch('accessory-item-added', productId: $productId);
    }

    public function addByCode(): void
    {
        app(AccessorySaleQuery::class)->authorize(auth()->user());
        $this->code = trim($this->code);
        Validator::make(['code' => $this->code], ['code' => ['required', 'string', 'max:60']])->validate();
        if ($this->catalogueMode === 'PHONE') {
            $this->addPhone(app(PhoneSaleQuery::class)->byImei(auth()->user(), $this->code));
        } else {
            $this->addProduct(app(AccessorySaleQuery::class)->byCode(auth()->user(), $this->code));
        }
        $this->code = '';
        $this->dispatch('accessory-scan-ready');
    }

    public function scanCode(string $value): bool
    {
        $this->code = $value;
        try {
            $this->addByCode();

            return true;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            Notification::make()->title('Código no agregado')->body(collect($e->errors())->flatten()->first())->warning()->send();

            return false;
        }
    }

    public function canViewReceipts(): bool
    {
        return Gate::forUser(auth()->user()->fresh())->allows('viewAny', AccessorySale::class);
    }

    public function decreaseProduct(int $productId): void
    {
        $this->cart = app(AccessorySaleQuery::class)->decrease(auth()->user(), $this->cart, $productId);
        $this->reviewedPhoneCart = [];
        $this->reviewedCart = [];
        $this->reviewSummary = null;
        $this->resetValidation();
    }

    public function removeProduct(int $productId): void
    {
        app(AccessorySaleQuery::class)->authorize(auth()->user());
        $this->cart = array_values(array_filter($this->cart, fn ($l) => $l['product_id'] !== $productId));
        $this->reviewedPhoneCart = [];
        $this->reviewedCart = [];
        $this->reviewSummary = null;
        $this->resetValidation();
    }

    public function clearCart(): void
    {
        app(AccessorySaleQuery::class)->authorize(auth()->user());
        if ($this->phoneCart) {
            app(ReleasePhoneReservationAction::class)->execute(auth()->user(), $this->requestKey);
        }
        $this->phoneCart = [];
        $this->cart = [];
        $this->reviewedPhoneCart = [];
        $this->reviewedCart = [];
        $this->reviewSummary = null;
        $this->requestKey = (string) Str::uuid();
        $this->resetValidation();
    }

    public function newSale(): void
    {
        $this->clearCart();
        $this->lastSaleId = null;
        if ($this->getMountedAction()?->getName() === 'phoneReceipt') {
            $this->unmountAction();
        }
        $this->dispatch('accessory-scan-ready');
    }

    public function reviewSale(): void
    {
        $this->resetValidation();
        try {
            $this->reviewSummary = app(AccessorySaleQuery::class)->preview(auth()->user(), ['lines' => $this->cart, 'phone_lines' => $this->phoneCart, 'request_key' => $this->requestKey]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['cart' => collect($e->errors())->flatten()->first()]);
        }
        $this->reviewedPhoneCart = $this->phoneCart;
        $this->reviewedCart = $this->cart;
        $this->mountAction('confirmSale');
    }

    public function discountPreview(array $prices): array
    {
        try {
            $data = AccessorySaleInput::withFinalPrices(['lines' => $this->reviewedCart, 'phone_lines' => $this->reviewedPhoneCart, 'request_key' => $this->requestKey], $prices);

            return ['summary' => app(AccessorySaleQuery::class)->preview(auth()->user(), $data), 'discountError' => null];
        } catch (ValidationException $e) {
            return ['summary' => $this->reviewSummary, 'discountError' => collect($e->errors())->flatten()->first()];
        }
    }

    public function confirmSaleAction(): Action
    {
        return Action::make('confirmSale')->label('Confirmar cobro')->modalHeading('Confirmar venta')->modalWidth(fn () => $this->phoneCart ? Width::SevenExtraLarge : Width::ThreeExtraLarge)->stickyModalHeader()->stickyModalFooter()->modalCancelActionLabel('Volver al carrito')->schema([
            Grid::make()->columns(fn () => $this->phoneCart ? ['default' => 1, 'lg' => 3] : 1)->extraAttributes(['class' => 'pg-sale-confirm-grid'])->schema([
                Group::make([
                    Group::make([])->visible(fn () => (bool) $this->phoneCart)->schema(function (): array {
                        $fields = [];
                        foreach ($this->reviewSummary['lines'] ?? [] as $line) {
                            if (($line['kind'] ?? '') !== 'PHONE') {
                                continue;
                            }
                            $id = $line['phone_unit_id'];
                            $fields[] = Section::make($line['product_name'].' · #'.$id)->compact()->schema([
                                TextInput::make('phone_imeis.'.$id.'.imei1')->label('IMEI 1')->prefixIcon('heroicon-o-qr-code')->prefixIconColor('info')->required()->regex('/\A[0-9]{15}\z/')->length(15)->inputMode('numeric')->default($line['phone_snapshot']['imeis'][0] ?? null)->readOnly(filled($line['phone_snapshot']['imeis'][0] ?? null)),
                                TextInput::make('phone_imeis.'.$id.'.imei2')->label('IMEI 2 (opcional)')->prefixIcon('heroicon-o-qr-code')->prefixIconColor('warning')->regex('/\A[0-9]{15}\z/')->length(15)->inputMode('numeric')->default($line['phone_snapshot']['imeis'][1] ?? null)->readOnly(filled($line['phone_snapshot']['imeis'][1] ?? null)),
                            ])->columns(2);
                        }

                        return $fields;
                    }),
                    Section::make('Cliente')->compact()->icon('heroicon-o-user')->iconColor('info')->visible(fn () => (bool) $this->phoneCart)->schema([
                        TextInput::make('customer_ci')->prefixIcon('heroicon-o-identification')->prefixIconColor('info')->label('CI del cliente')->required(fn () => (bool) $this->phoneCart)->maxLength(60)->live(onBlur: true)->afterStateUpdated(function ($state, Set $set): void {
                            foreach (['customer_name', 'customer_phone', 'customer_email', 'customer_email2'] as $field) {
                                $set($field, '');
                            }
                            $customer = app(SaleCustomerQuery::class)->find(auth()->user(), (string) $state);
                            if ($customer) {
                                $set('customer_name', $customer['name']);
                                $set('customer_phone', $customer['phone']);
                            }
                        }),
                        TextInput::make('customer_name')->prefixIcon('heroicon-o-user')->prefixIconColor('warning')->label('Nombre completo')->required(fn () => (bool) $this->phoneCart)->maxLength(160),
                        TextInput::make('customer_phone')->prefixIcon('heroicon-o-phone')->prefixIconColor('success')->label('Teléfono / WhatsApp')->prefix('+591')->required(fn () => (bool) $this->phoneCart)->length(8)->regex('/\A[0-9]{8}\z/')->inputMode('numeric'),
                        Section::make('Correos (opcional)')->compact()->collapsible()->collapsed()->schema([
                            TextInput::make('customer_email')->prefixIcon('heroicon-o-envelope')->prefixIconColor('primary')->label('Correo (opcional)')->email()->maxLength(160),
                            TextInput::make('customer_email2')->prefixIcon('heroicon-o-envelope')->prefixIconColor('primary')->label('Segundo correo (opcional)')->email()->maxLength(160),
                        ])->columns(2)->columnSpanFull(),
                    ])->columns(2),
                ])->visible(fn () => (bool) $this->phoneCart)->columnSpan(['default' => 1, 'lg' => 2]),
                Group::make([
                    Section::make('Precio final a cobrar')->compact()->description('Edita el total de cada producto. El sistema valida la rebaja permitida.')->schema(function (): array {
                        $fields = [];
                        foreach ($this->reviewSummary['lines'] ?? [] as $line) {
                            $key = ($line['kind'] ?? '') === 'PHONE' ? 'p_'.$line['phone_unit_id'] : 'a_'.$line['product_id'];
                            $fields[] = TextInput::make('final_prices.'.$key)->label($line['product_name'].' × '.$line['quantity'])->prefix('Bs')->inputMode('decimal')->required()->default(number_format($line['total_cents'] / 100, 2, '.', ''))->live(debounce: 500);
                        }

                        return $fields;
                    }),
                    View::make('accesorios.sale-review')->viewData(fn (Get $get) => $this->discountPreview($get('final_prices') ?? [])),
                ])->columnSpan(1),
            ]),
        ])->action(function (array $data): void {
            app(AccessorySaleQuery::class)->authorize(auth()->user());
            if ((! $this->reviewedCart && ! $this->reviewedPhoneCart) || $this->cart !== $this->reviewedCart || $this->phoneCart !== $this->reviewedPhoneCart) {
                throw ValidationException::withMessages(['cart' => 'Revisa primero los productos y el total.']);
            }
            try {
                $phoneLines = $this->reviewedPhoneCart;
                foreach ($phoneLines as &$phoneLine) {
                    foreach (['imei1', 'imei2'] as $key) {
                        if (filled($data['phone_imeis'][$phoneLine['unit_id']][$key] ?? null)) {
                            $phoneLine[$key] = $data['phone_imeis'][$phoneLine['unit_id']][$key];
                        }
                    }
                }
                unset($phoneLine);
                $saleData = ['lines' => $this->reviewedCart, 'phone_lines' => $phoneLines, 'customer' => ['ci' => $data['customer_ci'] ?? '', 'name' => $data['customer_name'] ?? '', 'phone' => $data['customer_phone'] ?? '', 'email' => $data['customer_email'] ?? null, 'email2' => $data['customer_email2'] ?? null], 'warranty_accepted' => true, 'request_key' => $this->requestKey, 'payment_method' => 'CASH', 'cash_received' => true, 'rules_revisions' => $this->reviewSummary['rules_revisions'] ?? []];
                $sale = app(RegisterAccessorySaleAction::class)->execute(auth()->user(), AccessorySaleInput::withFinalPrices($saleData, $data['final_prices'] ?? []));
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->first();
                $this->addError('cart', $message);
                Notification::make()->title('Venta sin registrar')->body($message)->danger()->send();

                throw ValidationException::withMessages(['cart' => $message]);
            } catch (QueryException $e) {
                report($e);
                Notification::make()->title('Venta sin registrar')->body('No se pudo completar el registro. Se conservaron los datos; revisa el historial antes de reintentar.')->danger()->send();

                return;
            }
            $this->clearCart();
            $this->lastSaleId = $sale->id;
            Notification::make()->title('Venta #'.$sale->id.' registrada · Bs '.Bolivianos::formatCompact($sale->total_cents))->success()->send();
            $this->dispatch('accessory-sale-registered');
            if ($sale->receipt_required || $this->canViewReceipts()) {
                $this->replaceMountedAction('phoneReceipt');
            }
        });
    }
}
