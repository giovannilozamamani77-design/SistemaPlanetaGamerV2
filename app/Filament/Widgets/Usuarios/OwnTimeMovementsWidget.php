<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\ControlHorario\Actions\UseTimeCreditAction;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\ControlHorario\Queries\OwnTimeHistoryQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

class OwnTimeMovementsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $balances = app(OwnTimeHistoryQuery::class)->balances(auth()->user());

        return $table->paginated([5, 10, 25])->defaultPaginationPageOption(5)->emptyStateHeading('No tienes movimientos de horario')->heading('Crédito y movimientos de horario')->description('Cada compensación tiene dos apuntes: crédito utilizado y retraso reducido. Representan la misma aplicación, no se suman entre sí. Las devoluciones y ajustes se conservan en el historial.')->query(app(OwnTimeHistoryQuery::class)->movements(auth()->user()))->columns([
            TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            TextColumn::make('bucket')->label('Saldo')->formatStateUsing(fn (string $state): string => ['CREDITO' => 'Crédito', 'RETRASO' => 'Retraso', 'FALTA_REAL' => 'Falta'][$state] ?? $state)->badge(),
            TextColumn::make('explanation')->label('Qué ocurrió')->state(function (TimeMovement $record): string {
                if (array_key_exists('automatic', $record->metadata ?? [])) {
                    $mode = $record->metadata['automatic'] ? 'Compensación automática' : 'Compensación manual';

                    return $mode.($record->bucket === 'CREDITO' ? ' · crédito utilizado' : ' · retraso reducido');
                }

                return $record->bucket === 'CREDITO' ? ($record->delta_minutes > 0 ? 'Crédito recibido o devuelto' : 'Ajuste de crédito') : 'Registro o ajuste de horario';
            })->wrap(),
            TextColumn::make('incident.scheduledWorkday.work_date')->label('Jornada compensada')->date('d/m/Y')->placeholder('—'),
            TextColumn::make('delta_minutes')->label('Minutos')->numeric(),
            TextColumn::make('metadata.salary_period')->label('Periodo salarial')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('metadata.amount_cents')->label('Descuento aplicado')->formatStateUsing(fn (?int $state): string => $state === null ? '—' : 'Bs '.Bolivianos::format($state))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('metadata.receipt')->label('Constancia')->limit(70)->toggleable(isToggledHiddenByDefault: true),
        ])->headerActions([
            Action::make('aplicarCredito')->label('Usar mi crédito disponible')->visible(fn (): bool => ($balances['CREDITO'] ?? 0) > 0 && ($balances['RETRASO'] ?? 0) > 0)->requiresConfirmation()
                ->modalDescription('Se aplica únicamente crédito ya trabajado a retrasos confirmados propios. No crea horas ni duplica compensaciones.')
                ->schema([Hidden::make('key')->default(fn (): string => (string) Str::uuid())])
                ->action(function (array $data): void {
                    app(UseTimeCreditAction::class)->execute(auth()->user(), auth()->user(), $data['key']);
                    $this->dispatch('own-time-balance-updated');
                }),
        ])->defaultSort('id', 'desc');
    }
}
