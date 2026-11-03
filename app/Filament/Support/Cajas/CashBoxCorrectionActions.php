<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Cajas\Actions\DecideCashBoxCorrectionAction;
use App\Domain\Cajas\Models\CashBoxCorrection;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

class CashBoxCorrectionActions
{
    public static function actions(): array
    {
        $content = fn (CashBoxCorrection $record) => view('cajas.correction-detail', ['request' => $record]);

        return [
            Action::make('detalle')->label('Ver detalle')->icon('heroicon-o-eye')->color('gray')
                ->modalHeading('Detalle de la solicitud')->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()
                ->modalContent($content)->modalSubmitAction(false)->modalCancelActionLabel('Cerrar'),
            Action::make('resolver')->label('Revisar solicitud')->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (CashBoxCorrection $record): bool => (auth()->user()->hasRole('administrador') && auth()->user()->can('cajas.liberar')) && in_array($record->status, ['PENDIENTE', 'DEVUELTA'], true) && $record->session->status === 'ABIERTA')
                ->modalHeading('Revisar corrección de operación')->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()
                ->modalContent($content)->modalSubmitActionLabel('Confirmar decisión')->modalCancelActionLabel('Cancelar')
                ->schema([
                    Section::make('Decisión administrativa')->description('Aprobar anula el efecto de la operación y conserva el original. Rechazar mantiene la operación vigente. Devolver pide completar el motivo y mantiene la revisión pendiente.')
                        ->schema([
                            Select::make('decision')->label('¿Qué deseas hacer?')->native()->options(['APROBADA' => 'Aprobar anulación', 'RECHAZADA' => 'Rechazar solicitud', 'DEVUELTA' => 'Devolver para completar'])->required(),
                            Textarea::make('reason')->label('Motivo / constancia')->required()->minLength(3)->maxLength(2000), TextInput::make('pin')->label('Tu PIN personal')->password()->required()->length(4), Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
                        ]),
                ])
                ->action(function (CashBoxCorrection $record, #[\SensitiveParameter] array $data): void {
                    app(DecideCashBoxCorrectionAction::class)->execute(auth()->user(), $record, $data);
                    Notification::make()->title('Decisión registrada')->body('El registro original y la solicitud se conservan.')->success()->send();
                }),
        ];
    }
}
