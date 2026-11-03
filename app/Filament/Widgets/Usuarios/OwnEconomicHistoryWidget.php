<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\Incidencias\Actions\AppealEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReportOwnRepaymentAction;
use App\Domain\Incidencias\Data\IncidentOrigin;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Queries\OwnEconomicHistoryQuery;
use App\Domain\Incidencias\Queries\OwnIncidentDetailQuery;
use App\Domain\Incidencias\Queries\RepaymentDestinationQuery;
use App\Filament\Support\Personal\PersonnelLabels;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class OwnEconomicHistoryWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    #[On('personnel-record-updated')]
    public function refreshRepayments(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $globalPending = GlobalRecovery::where('user_id', auth()->id())->whereNull('confirmed_at')->whereNull('rejected_at')->exists();
        $active = fn (EconomicIncident $record): bool => in_array($record->status, ['DETECTADA', 'EN_REVISION', 'CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'], true);

        return $table->paginated([5, 10, 25])->defaultPaginationPageOption(5)->emptyStateHeading('No tienes faltantes registrados')
            ->heading('Mis faltantes')->description('Los cinco más recientes. Explicar o informar un depósito no reduce el saldo hasta la revisión de Administración.')
            ->query(app(OwnEconomicHistoryQuery::class)->execute(auth()->user()))->columns([
                TextColumn::make('occurred_at')->label('Fecha y hora')->wrap()->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('box_label')->label('Caja')->wrap()->placeholder('Sin caja registrada')->description(fn (EconomicIncident $record): string => IncidentOrigin::label($record)),
                TextColumn::make('amount_cents')->label('Importe')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::format($state))->weight('semibold'),
                TextColumn::make('pending_cents')->label('Por reponer')->formatStateUsing(fn (int $state, EconomicIncident $record): string => in_array($record->status, ['DETECTADA', 'EN_REVISION'], true) ? 'Por confirmar' : 'Bs '.Bolivianos::format($state)),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'CONFIRMADA' => 'Activa', 'PAGO_PARCIAL' => 'Activa · pago parcial', 'DESCUENTO_PROGRAMADO' => 'Descuento por aplicar', default => PersonnelLabels::text($state),
                })->color(PersonnelLabels::color(...))->description(fn (EconomicIncident $record): ?string => $record->receipt_justified_at ? (($record->policy_snapshot['appeal_decision'] ?? null) === 'RECHAZAR' ? 'Apelación rechazada: debes reponer. '.($record->policy_snapshot['appeal_response'] ?? '') : (($record->policy_snapshot['appeal_decision'] ?? null) === 'ACEPTAR' ? 'Apelación aceptada' : 'Apelación enviada')) : ($record->has_pending_report ? 'Pago por verificar' : ($record->latestRecovery?->rejected_at ? 'Reporte rechazado: '.Str::limit($record->latestRecovery->rejection_reason, 65) : null))),
                TextColumn::make('reason')->label('Observación')->limit(55)->wrap(),
            ])->recordActions([ActionGroup::make([
                Action::make('declararAporte')->label('Reponer')->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (EconomicIncident $record): bool => $active($record) && ! $globalPending && ! $record->has_pending_report && auth()->user()->can('incidencias.declarar_aporte'))
                    ->modalHeading('Informar reposición por Yape')->modalWidth('lg')->modalDescription('Deposita el importe indicado a la cuenta de comisiones e informa el ID. Administración verificará el ingreso; no necesitas adjuntar captura.')
                    ->modalContent(fn (EconomicIncident $record) => view('incidencias.repayment-destination', app(RepaymentDestinationQuery::class)->quote(auth()->user(), $record->id)))
                    ->schema([Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()), TextInput::make('operation_reference')->label('ID de transacción')->required()->maxLength(120)])
                    ->modalSubmitActionLabel('Enviar para verificación')->modalCancelActionLabel('Volver')
                    ->action(fn (EconomicIncident $record, array $data) => app(ReportOwnRepaymentAction::class)->execute(auth()->user(), $record->id, $data)),
                Action::make('apelar')->label('Explicar o apelar')->icon('heroicon-o-chat-bubble-left-ellipsis')->visible(fn (EconomicIncident $record): bool => $active($record) && ! $record->receipt_justified_at)
                    ->modalHeading('Explica lo ocurrido')->modalDescription('Si consideras que no te corresponde, explica por qué. Puedes apelar una sola vez. Administración revisará tu explicación; el importe original y el historial se conservan.')
                    ->modalWidth('lg')->modalSubmitActionLabel('Enviar explicación')->modalCancelActionLabel('Volver')
                    ->fillForm(fn (EconomicIncident $record): array => ['text' => $record->receipt_justification, 'request_key' => (string) Str::uuid()])
                    ->schema([Textarea::make('text')->label('Tu explicación')->required()->minLength(3)->maxLength(2000), Hidden::make('request_key')])
                    ->action(fn (EconomicIncident $record, array $data) => app(AppealEconomicIncidentAction::class)->execute(auth()->user(), $record->id, $data)),
                Action::make('historialReposiciones')->label('Detalle')->icon('heroicon-o-eye')->iconButton()->modalHeading('Detalle del faltante y sus movimientos')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->modalContent(fn (EconomicIncident $record): View => view('incidencias.personal-recoveries', app(OwnIncidentDetailQuery::class)->execute(auth()->user(), $record->id))),
            ])->label('Opciones')->iconButton()->tooltip('Reponer, explicar o ver detalle')])->defaultSort('id', 'desc');
    }
}
