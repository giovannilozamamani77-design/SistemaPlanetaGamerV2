<?php

namespace App\Filament\Pages\Prestamos;

use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Filament\Support\Prestamos\LoanDetailActions;
use App\Filament\Support\Prestamos\LoanForms;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class RegisterLoan extends Page
{
    protected string $view = 'prestamos.register-loan';

    protected static ?string $navigationLabel = 'Realizar préstamo';

    protected static string|\UnitEnum|null $navigationGroup = 'Préstamos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Realizar préstamo';

    public array $data = [];

    #[Locked]
    public array $reviewedData = [];

    #[Locked]
    public array $reviewedTerms = [];

    #[Locked]
    public ?int $registeredLoanId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user()?->fresh();

        return $user && Gate::forUser($user)->allows('create', Loan::class) && Gate::forUser($user)->allows('viewAny', Loan::class);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        Gate::authorize('create', Loan::class);
        $this->form->fill();
    }

    protected function getViewData(): array
    {
        $status = app(LoanQuery::class)->capitalAvailability(auth()->user(), $this->data['capital'] ?? null);

        return ['availableCapital' => $status['available_cents'], 'capitalStatus' => $status, 'registrationTerms' => app(LoanQuery::class)->preview(auth()->user(), $this->data)];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->schema(LoanForms::schema());
    }

    public function objectDetailsAction(): Action
    {
        return Action::make('objectDetails')->label('Detalles adicionales (opcional)')->modalHeading('Detalles adicionales (opcional)')->modalWidth('lg')->modalSubmitActionLabel('Aplicar al formulario')->modalCancelActionLabel('Cancelar')->fillForm(fn () => array_intersect_key($this->data, array_flip(['serial', 'condition', 'accessories'])))->schema([
            TextInput::make('serial')->label('Serie o IMEI')->maxLength(120),
            Textarea::make('condition')->label('Estado físico')->rows(2)->maxLength(2000),
            Textarea::make('accessories')->label('Accesorios recibidos')->rows(2)->maxLength(2000),
        ])->action(function (array $data): void {
            app(LoanAuthorization::class)->authorize(auth()->user(), 'create');
            $v = Validator::make($data, ['serial' => ['nullable', 'string', 'max:120'], 'condition' => ['nullable', 'string', 'max:2000'], 'accessories' => ['nullable', 'string', 'max:2000']])->validate();
            foreach (['serial', 'condition', 'accessories'] as $key) {
                $this->data[$key] = $v[$key] ?? null;
            }
        });
    }

    public function confirmLoanAction(): Action
    {
        return Action::make('confirmLoan')->modalHeading('¿Los datos son correctos?')->modalWidth('2xl')->modalContent(fn () => view('prestamos.registration-review', ['review' => $this->reviewedData, 'terms' => $this->reviewedTerms]))->modalSubmitActionLabel('Confirmar y registrar')->modalCancelActionLabel('Volver y corregir')->closeModalByClickingAway(false)->action(function (): void {
            try {
                app(LoanAuthorization::class)->authorize(auth()->user(), 'create');
                $current = $this->form->getState();
                if (! $this->reviewedData || $current !== $this->reviewedData) {
                    throw ValidationException::withMessages(['capital' => 'Los datos cambiaron. Revísalos de nuevo antes de confirmar.']);
                }
                $loan = app(RegisterLoanAction::class)->execute(auth()->user(), $this->reviewedData);
            } catch (ValidationException $e) {
                $this->unmountAction();
                $this->reviewedData = [];
                $this->reviewedTerms = [];
                throw $this->formErrors($e);
            }
            $this->registeredLoanId = $loan->id;
            $this->reviewedData = [];
            $this->reviewedTerms = [];
            $this->data = [];
            $this->form->fill();
            $this->replaceMountedAction('registeredLoan');
        });
    }

    public function registeredLoanAction(): Action
    {
        return Action::make('registeredLoan')->modalHeading('Registro realizado')->modalDescription('El préstamo se guardó correctamente. El formulario ya está listo para un nuevo registro.')->modalSubmitAction(false)->modalCancelActionLabel('Ahora no')->modalWidth('lg')->closeModalByClickingAway(false)->extraModalFooterActions([
            LoanDetailActions::make('viewRecord')->label('Ver detalles')->icon('heroicon-o-eye')->color('gray')->record(fn () => $this->registeredLoanId ? app(LoanQuery::class)->loans(auth()->user())->findOrFail($this->registeredLoanId) : null),
            Action::make('printReceipt')->label('Imprimir recibo')->icon('heroicon-o-printer')->color('primary')->extraAttributes(['class' => 'pg-loan-print-action'])->url(fn () => $this->registeredLoanId ? route('prestamos.recibo-pdf', ['loan' => $this->registeredLoanId, 'print' => 1, 'original' => 1]) : null)->openUrlInNewTab(),
        ])->visible(fn () => $this->registeredLoanId !== null);
    }

    public function register(): void
    {
        app(LoanAuthorization::class)->authorize(auth()->user(), 'create');
        try {
            $data = $this->form->getState();
            $terms = app(LoanQuery::class)->reviewRegistration(auth()->user(), $data);
        } catch (ValidationException $e) {
            throw $this->formErrors($e);
        }
        $this->reviewedData = $data;
        $this->reviewedTerms = $terms;
        $this->mountAction('confirmLoan');
    }

    private function formErrors(ValidationException $exception): ValidationException
    {
        $errors = [];
        foreach ($exception->errors() as $key => $messages) {
            $errors[str_starts_with($key, 'data.') ? $key : 'data.'.($key === 'request_key' ? 'capital' : $key)] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
