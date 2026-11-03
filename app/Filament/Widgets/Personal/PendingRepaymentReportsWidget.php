<?php

namespace App\Filament\Widgets\Personal;

use App\Domain\Incidencias\Actions\ReviewRepaymentReportAction;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Incidencias\Queries\RepaymentPanelQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\Locked;

class PendingRepaymentReportsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    #[Locked]
    public array $panelFilters = [];

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('administrador') && auth()->user()->can('incidencias.reposiciones.confirmar');
    }

    public function table(Table $table): Table
    {
        return $table->query(app(RepaymentPanelQuery::class)->query(auth()->user(), 'reports', $this->panelFilters))
            ->heading('Reposiciones por verificar')->description('Comprueba el depósito con su ID. Al confirmar se aplica solo el dinero recibido, sin borrar el faltante original.')
            ->emptyStateHeading('No hay reposiciones por verificar')->paginated([5, 10, 25])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('economicIncident.user.name')->label('Cajera')->searchable(),
                TextColumn::make('created_at')->label('Reportado')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('economicIncident.box_label')->label('Caja')->placeholder('Otro origen'),
                TextColumn::make('amount_cents')->label('Importe')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::format($state)),
                TextColumn::make('operation_reference')->label('ID de transacción')->copyable(),
                TextColumn::make('destination')->label('Destino'),
            ])->recordActions([
                Action::make('revisarReporte')->label('Verificar depósito')->icon('heroicon-o-check-badge')->modalHeading('Verificar reposición informada')->modalWidth('lg')
                    ->modalContent(fn (RecoveryPayment $record) => view('incidencias.admin-repayment-report', ['payment' => $record]))
                    ->schema([
                        Select::make('decision')->label('Resultado')->options(['CONFIRMAR' => 'Depósito verificado', 'RECHAZAR' => 'ID incorrecto o depósito no recibido'])->default('CONFIRMAR')->required(),
                        Toggle::make('received')->label('Verifiqué el ingreso real por este ID'),
                        TextInput::make('second_amount')->visible(fn (RecoveryPayment $record): bool => in_array($record->economicIncident->status, ['DETECTADA', 'EN_REVISION'], true))->label('Comprobación de registro anterior aún por revisar')->prefix('Bs')->helperText('Solo si el faltante todavía no estaba confirmado y supera el umbral de revisión.'),
                        Textarea::make('reason')->label('Resultado de la revisión')->required()->minLength(3),
                        Toggle::make('exception')->label('Autoaprobación excepcional: único administrador'),
                    ])->modalSubmitActionLabel('Guardar revisión')->modalCancelActionLabel('Volver sin registrar')
                    ->action(function (RecoveryPayment $record, array $data): void {
                        app(ReviewRepaymentReportAction::class)->execute(auth()->user(), $record->id, $data['decision'], $data['reason'],
                            filled($data['second_amount'] ?? null) ? Bolivianos::cents($data['second_amount']) : null, (bool) ($data['exception'] ?? false), (bool) ($data['received'] ?? false));
                        $this->dispatch('personnel-record-updated');
                    }),
            ]);
    }
}
