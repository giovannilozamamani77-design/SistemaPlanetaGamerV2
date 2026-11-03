<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Actions\SaveCardDepositAccountAction;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardDepositQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Tarjetas\CardInventoryForms;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CardDepositAccounts extends Page implements HasTable
{
    use HandlesPersonnelActionErrors, InteractsWithTable;

    protected string $view = 'tarjetas.deposit-accounts';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Cuentas de depósitos';

    protected static string|\UnitEnum|null $navigationGroup = 'Tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('configureDeposits', CardProduct::class);
    }

    protected function getHeaderActions(): array
    {
        return [$this->accountAction('createAccount'), Action::make('inventory')->label('Volver al inventario')->icon('heroicon-o-arrow-left')->color('gray')->visible(fn () => StoreInventory::canAccess())->url(StoreInventory::getUrl())];
    }

    private function accountAction(string $name): Action
    {
        return CardInventoryForms::modal(Action::make($name))->label($name === 'createAccount' ? 'Agregar cuenta' : 'Editar')->icon($name === 'createAccount' ? 'heroicon-o-plus' : 'heroicon-o-pencil-square')
            ->visible(fn () => self::canAccess())->fillForm(fn (?CardDepositAccount $record) => $record ? array_merge($record->only(['name', 'bank', 'number', 'holder', 'qr_path', 'is_active']), ['account_id' => $record->id, 'expected_revision' => $record->revision, 'request_key' => (string) Str::uuid()]) : ['is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()])
            ->schema([Hidden::make('account_id'), Hidden::make('expected_revision'), TextInput::make('name')->label('Nombre de la cuenta')->required()->maxLength(120), TextInput::make('bank')->label('Banco o billetera')->required()->maxLength(120), TextInput::make('number')->label('Número de cuenta')->required()->maxLength(120), TextInput::make('holder')->label('Titular')->required()->maxLength(120), FileUpload::make('qr_path')->label('QR')->disk('local')->visibility('private')->directory(fn () => 'card-deposit-qr/'.auth()->id())->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(4096)->previewable(false)->helperText('Si cambias banco, número o titular, sube el QR correspondiente. Los QR anteriores se conservan para el historial.'), Toggle::make('is_active')->label('Habilitada para depósitos nuevos')->default(true), CardInventoryForms::key()])
            ->action(function (array $data): void {
                app(SaveCardDepositAccountAction::class)->execute(auth()->user(), $data);
                Notification::make()->title('Cuenta de depósitos guardada')->success()->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table->query(app(CardDepositQuery::class)->accounts(auth()->user()))->columns([TextColumn::make('name')->label('Cuenta'), TextColumn::make('bank')->label('Banco'), TextColumn::make('number')->label('Número'), TextColumn::make('holder')->label('Titular'), TextColumn::make('is_active')->label('Estado')->formatStateUsing(fn ($state) => $state ? 'Habilitada' : 'Desactivada')->badge()->color(fn ($state) => $state ? 'success' : 'gray')])->recordActions([$this->accountAction('editAccount')])->paginated([5, 10, 25])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')->emptyStateHeading('Agrega la cuenta y el QR de Administración');
    }
}
