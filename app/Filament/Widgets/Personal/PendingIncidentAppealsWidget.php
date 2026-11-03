<?php

namespace App\Filament\Widgets\Personal;

use App\Domain\Incidencias\Actions\ReviewIncidentAppealAction;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Queries\RepaymentPanelQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\Locked;

class PendingIncidentAppealsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    #[Locked]
    public array $panelFilters = [];

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('administrador') && auth()->user()->can('incidencias.confirmar');
    }

    public function table(Table $table): Table
    {
        return $table->query(app(RepaymentPanelQuery::class)->query(auth()->user(), 'appeals', $this->panelFilters))->heading('Apelaciones por revisar')->description('El faltante ya está activo. Solo se revisa la explicación presentada; no hace falta volver a confirmar la deuda.')->paginated([5, 10, 25])->defaultPaginationPageOption(5)->defaultSort('receipt_justified_at', 'desc')->emptyStateHeading('No hay apelaciones pendientes')
            ->columns([
                TextColumn::make('user.name')->label('Cajera')->wrap(),
                TextColumn::make('box_label')->label('Caja')->wrap(),
                TextColumn::make('receipt_justified_at')->label('Apelación')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('pending_cents')->label('Pendiente')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::format($state)),
                TextColumn::make('receipt_justification')->label('Explicación')->wrap()->limit(90),
            ])->recordActions([
                Action::make('revisarApelacion')->label('Revisar apelación')->icon('heroicon-o-chat-bubble-left-ellipsis')->modalWidth('lg')->modalHeading('Resolver apelación única')
                    ->modalDescription(fn (EconomicIncident $record): string => 'Motivo original: '.$record->reason.' | Explicación: '.$record->receipt_justification)
                    ->schema([
                        Select::make('decision')->label('Decisión')->options(['RECHAZAR' => 'Rechazar: debe reponer', 'ACEPTAR' => 'Aceptar y cancelar faltante'])->default('RECHAZAR')->required()->live(),
                        Textarea::make('reason')->label('Respuesta (opcional)'),
                        TextInput::make('pin')->label('PIN personal de Administración')->password()->revealable()->maxLength(4)->required(fn (Get $get): bool => $get('decision') === 'ACEPTAR')->helperText('Obligatorio para cancelar el faltante.'),
                    ])->modalSubmitActionLabel('Guardar decisión')->modalCancelActionLabel('Volver')->action(function (EconomicIncident $record, array $data): void {
                        app(ReviewIncidentAppealAction::class)->execute(auth()->user(), $record->id, $data['decision'], $data['reason'] ?? '', $data['pin'] ?? null);
                        $this->dispatch('personnel-record-updated');
                    }),
            ]);
    }
}
