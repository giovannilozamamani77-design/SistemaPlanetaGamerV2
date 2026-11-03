<?php

namespace App\Filament\Pages\Prestamos;

use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\SaveLoanAccountAction;
use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Prestamos\LoanForms;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

class LoanSettings extends Page
{
    use HandlesPersonnelActionErrors;

    protected string $view = 'prestamos.settings';

    protected static ?string $navigationLabel = 'Capital en Yape';

    protected static string|\UnitEnum|null $navigationGroup = 'Préstamos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Capital en Yape de Préstamos';

    public static function canAccess(): bool
    {
        $user = auth()->user()?->fresh();

        return $user && Gate::forUser($user)->allows('manageFund', Loan::class);
    }

    protected function getViewData(): array
    {
        app(LoanAuthorization::class)->authorize(auth()->user(), 'manageFund');

        return ['summary' => app(LoanQuery::class)->summary(auth()->user()), 'movements' => app(LoanQuery::class)->fundMovements(auth()->user())->latest('id')->limit(5)->get()];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('movements')->label('Movimientos del fondo')->icon('heroicon-o-clock')->url(LoanCapitalHistory::getUrl()), Action::make('back')->label('Préstamos')->icon('heroicon-o-arrow-left')->url(LoanList::getUrl()), Action::make('objects')->label('Tipos de objeto')->icon('heroicon-o-archive-box')->url(LoanObjectTypes::getUrl())->visible(fn () => LoanObjectTypes::canAccess()),
            Action::make('fund')->label('Registrar capital')->icon('heroicon-o-banknotes')->modalDescription('Solo dinero realmente aportado o retirado de Yape Préstamos. Un aporte no es una ganancia ni modifica otros módulos.')->schema([Select::make('kind')->label('Movimiento')->options(fn () => LoanFund::where('code', 'YAPE')->value('initialized_at') ? ['IN' => 'Aporte de capital', 'OUT' => 'Retiro de capital'] : ['INITIAL' => 'Saldo inicial real'])->required(), TextInput::make('amount')->label('Monto')->prefix('Bs')->required(), TextInput::make('reference')->label('Referencia (opcional)')->maxLength(200), Checkbox::make('confirmed')->label('Confirmo el movimiento real en Yape Préstamos.')->accepted()->required(), LoanForms::key()])->action(function (array $data): void {
                app(ManageLoanFundAction::class)->execute(auth()->user(), $data);
                Notification::make()->title('Movimiento de capital registrado')->success()->send();
            }),
            Action::make('account')->label('Cuenta y QR')->icon('heroicon-o-qr-code')->fillForm(fn () => array_merge(LoanFund::where('code', 'YAPE')->first()?->only(['bank', 'number', 'holder', 'qr_path']) ?? [], ['expected_revision' => LoanFund::where('code', 'YAPE')->value('revision') ?? 0]))->schema([TextInput::make('bank')->label('Banco o billetera')->default('Yape Préstamos')->required()->maxLength(120), TextInput::make('number')->label('Número de cuenta')->required()->maxLength(120), TextInput::make('holder')->label('Titular')->required()->maxLength(120), FileUpload::make('qr_path')->label('QR (opcional)')->disk('local')->visibility('private')->directory(fn () => 'loan-qr/'.auth()->id())->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(4096)->previewable(false), Hidden::make('expected_revision'), LoanForms::key()])->action(function (array $data): void {
                app(SaveLoanAccountAction::class)->execute(auth()->user(), $data);
                Notification::make()->title('Cuenta y QR guardados')->success()->send();
            }),
        ];
    }
}
