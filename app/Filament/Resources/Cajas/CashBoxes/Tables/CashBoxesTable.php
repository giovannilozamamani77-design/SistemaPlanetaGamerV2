<?php

namespace App\Filament\Resources\Cajas\CashBoxes\Tables;

use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\UpdateCashBoxDetailsAction;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Queries\ReceiptObservationsQuery;
use App\Filament\Resources\Cajas\CashBoxes\Schemas\CashBoxConfigurationForm;
use App\Filament\Resources\Cajas\CashBoxes\Schemas\CashBoxForm;
use App\Filament\Support\Cajas\CashBoxTemporaryFundActions;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CashBoxesTable
{
    public static function configure(Table $table): Table
    {
        return $table->heading('Cajas registradas')->description('Cinco registros por página. El capital base no representa el saldo disponible.')
            ->paginated([5])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')
            ->recordUrl(null)->recordAction('historial')
            ->columns([
                TextColumn::make('name')->label('Caja')->searchable()->weight('semibold')->description(fn (CashBox $record): string => $record->code),
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn (string $state): string => CashBoxForm::types()[$state])->badge(),
                TextColumn::make('configurations_exists')->label('Preparación')->formatStateUsing(fn (bool $state): string => $state ? 'Configurada' : 'Falta configurar')->badge()->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                TextColumn::make('base_capital_cents')->label('Capital base')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::format($state)),
                TextColumn::make('is_active')->label('Disponibilidad')->formatStateUsing(fn (bool $state): string => $state ? 'Activa' : 'Inactiva')->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])->filters([
                SelectFilter::make('type')->label('Tipo de caja')->options(CashBoxForm::types()),
                TernaryFilter::make('is_active')->label('Disponibilidad')->trueLabel('Activas')->falseLabel('Inactivas'),
            ])->filtersLayout(FiltersLayout::AboveContent)->filtersFormColumns(['default' => 1, 'md' => 2])
            ->emptyStateHeading('Todavía no hay cajas')->emptyStateDescription('Administración puede registrar la primera caja con su capital de referencia.')
            ->recordActions([
                CashBoxTemporaryFundActions::forAdministration(),
                self::configurationAction(),

                Action::make('recepciones')->label('Recepciones con diferencia')->icon('heroicon-o-exclamation-triangle')
                    ->modalHeading('Diferencias de recepción')->modalDescription('Últimas cinco observaciones; no equivalen a deuda confirmada de la cajera entrante.')
                    ->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                    ->schema(function (CashBox $record): array {
                        $items = app(ReceiptObservationsQuery::class)->execute(auth()->user(), $record->id);
                        if ($items->isEmpty()) {
                            return [Section::make('Sin diferencias de recepción')];
                        }

                        return $items->map(fn ($item): Section => Section::make('Apertura #'.$item->cash_box_session_id)
                            ->description('En revisión administrativa')->columnSpanFull()->schema([
                                TextEntry::make('amount_'.$item->id)->label($item->difference_cents < 0 ? 'Faltante recibido' : 'Sobrante recibido')->state('Bs '.Bolivianos::format(abs($item->difference_cents))),
                                TextEntry::make('reason_'.$item->id)->label('Observación')->state($item->reason),
                            ]))->all();
                    }),
                Action::make('historial')->label('Ver cambios')->icon('heroicon-o-clock')->modalHeading('Historial de configuración')
                    ->modalDescription('Últimos cinco cambios. Se conservan los registros anteriores.')
                    ->modalWidth('4xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                    ->schema(function (CashBox $record): array {
                        Gate::authorize('view', $record);
                        $audits = $record->audits()->orderByDesc('id')->limit(5)->get();
                        if ($audits->isEmpty()) {
                            return [Section::make('Sin cambios registrados')->description('No se encontraron cambios de configuración.')];
                        }

                        return $audits->map(fn ($audit): Section => Section::make(match ($audit->action) {
                            'created' => 'Registro de caja', 'configured' => 'Cuentas, capital y turnos', default => 'Cambio de nombre o disponibilidad'
                        })
                            ->description($audit->created_at->setTimezone('America/La_Paz')->format('d/m/Y H:i').' · Administración #'.$audit->actor_user_id)
                            ->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                                TextEntry::make('before_'.$audit->id)->label('Antes')->state(self::describe($audit->before)),
                                TextEntry::make('after_'.$audit->id)->label('Después')->state(self::describe($audit->after)),
                                TextEntry::make('reason_'.$audit->id)->label('Motivo')->state($audit->reason)->columnSpanFull(),
                            ]))->all();
                    }),
                EditAction::make()->label('Editar caja')->modal()->modalHeading('Editar nombre y disponibilidad')
                    ->modalWidth('4xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar cambios')->modalCancelActionLabel('Cancelar')
                    ->schema(CashBoxForm::edit())
                    ->fillForm(fn (CashBox $record): array => ['request_key' => (string) Str::uuid(), 'name' => $record->name, 'is_active' => $record->is_active, 'expected_revision' => $record->revision])
                    ->using(fn (CashBox $record, #[\SensitiveParameter] array $data): CashBox => app(UpdateCashBoxDetailsAction::class)->execute(auth()->user(), $record, $data)),
            ]);
    }

    public static function configurationAction(): Action
    {
        return Action::make('configurar')->label('Cuentas y turnos')->icon('heroicon-o-cog-6-tooth')
            ->visible(fn (CashBox $record): bool => Gate::allows('update', $record))
            ->modalHeading('Cuentas, capital y turnos')->modalWidth('4xl')->stickyModalHeader()->stickyModalFooter()
            ->modalDescription('Solo con caja cerrada. Configura las cuentas. El dinero se cuenta en la primera apertura; después se recibe del último cierre.')
            ->modalSubmitActionLabel('Guardar configuración')->modalCancelActionLabel('Cancelar')
            ->fillForm(fn (CashBox $record): array => CashBoxConfigurationForm::values($record))
            ->schema(fn (CashBox $record): array => CashBoxConfigurationForm::fields($record))
            ->action(function (CashBox $record, #[\SensitiveParameter] array $data): void {
                app(ConfigureCashBoxAction::class)->execute(auth()->user(), $record, $data);
                Notification::make()->title('Configuración guardada')->body('Las cuentas y el horario están listos. Los saldos se comprobarán al abrir la caja.')->success()->send();
            });
    }

    private static function describe(?array $values): string
    {
        if ($values === null) {
            return 'Sin registro previo';
        }

        return $values['name'].' · '.($values['is_active'] ? 'Activa' : 'Inactiva')
            .(isset($values['base_capital_cents']) ? ' · Capital Bs '.Bolivianos::format($values['base_capital_cents']) : '');
    }
}
