<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Cajas\Actions\RecordCashBoxTemporaryFundAction;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\CashBoxOperatingCapital;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

class CashBoxTemporaryFundActions
{
    public static function fields(array $accounts): array
    {
        return [Select::make('component')->label('¿Dónde entra o sale el dinero?')->helperText('Elige efectivo o la cuenta donde ocurrió el movimiento real.')->options(collect($accounts)->mapWithKeys(fn (array $a, string $code): array => [$code => $a['name']])->all())->required(),
            TextInput::make('amount')->label('Monto')->prefix('Bs')->inputMode('decimal')->required()->helperText('Escribe el dinero que realmente recibiste o entregaste.'),
            Textarea::make('reason')->label('Motivo')->placeholder('Ej.: fondos enviados por Administración')->helperText('Anota brevemente por qué se agregó o retiró el dinero.')->rows(2)->required()->minLength(3)->maxLength(2000),
            Toggle::make('occurred')->label('Confirmo que el dinero ya ingresó o salió')->accepted(),
            Hidden::make('request_key')->default(fn (): string => (string) Str::uuid())];
    }

    public static function forOwner(CashBoxOperations $page, string $kind): Action
    {
        $deposit = $kind === 'DEPOSITO';

        return Action::make($deposit ? 'depositoTemporal' : 'retiroTemporal')->label($deposit ? 'Agregar fondos' : 'Retirar fondos')
            ->icon($deposit ? 'heroicon-o-plus-circle' : 'heroicon-o-minus-circle')->color($deposit ? 'success' : 'danger')->modalWidth('2xl')
            ->modalHeading($deposit ? 'Agregar fondos temporales' : 'Retirar fondos temporales')
            ->modalSubmitActionLabel($deposit ? 'Agregar fondos' : 'Registrar retiro')->modalCancelActionLabel('Cancelar')->stickyModalHeader()->stickyModalFooter()
            ->modalDescription($deposit ? 'El dinero que recibes de Administración aumenta el capital operativo de esta caja. No es una comisión ni un sobrante.' : 'El dinero que entregas a Administración reduce el capital operativo de esta caja. No es un faltante ni reduce tus comisiones.')
            ->schema(function () use ($page, $deposit): array {
                $fields = self::fields($page->workspace['accounts']);
                $fields[3]->label($deposit ? 'Confirmo que el dinero ya ingresó' : 'Confirmo que el dinero ya salió');

                return [Section::make('Datos del movimiento')->schema(array_slice($fields, 0, 3)),
                    Section::make('Antes de guardar')->description('Registra solo dinero que ya se movió, no una solicitud pendiente.')->schema(array_slice($fields, 3))];
            })
            ->action(function (array $data) use ($page, $kind): void {
                app(RecordCashBoxTemporaryFundAction::class)->execute(auth()->user(), $page->workspace['session_id'], array_merge($data, ['kind' => $kind]));
                unset($page->workspace);
                Notification::make()->title('Movimiento temporal registrado')->success()->send();
            });
    }

    public static function forAdministration(): Action
    {
        return Action::make('fondosTemporales')->label('Fondos temporales')->icon('heroicon-o-arrows-up-down')->visible(fn (CashBox $record): bool => (auth()->user()->hasRole('administrador') && auth()->user()->can('cajas.liberar')) && $record->has_open_session)
            ->modalSubmitActionLabel('Registrar movimiento')->modalCancelActionLabel('Cancelar')->stickyModalHeader()->stickyModalFooter()->modalWidth('3xl')->modalDescription('Solo movimientos reales con caja abierta. La titular conserva el control de su sesión.')
            ->schema(function (CashBox $record): array {
                $session = CashBoxSession::where('cash_box_id', $record->id)->where('status', 'ABIERTA')->whereNotNull('active_slot')->firstOrFail();
                $accounts = CashBoxConfiguration::findOrFail($session->configuration_id)->accounts;

                return [Hidden::make('session_id')->default($session->id)->required(), Select::make('kind')->label('Movimiento')->options(['DEPOSITO' => 'Depósito temporal', 'RETIRO' => 'Retiro temporal', 'DEVOLUCION' => 'Devolución de un fondo'])->required(),
                    Select::make('fund_id')->label('Fondo a devolver (solo devolución)')->options(collect(app(CashBoxOperatingCapital::class)->funds($record->id))->mapWithKeys(fn (array $fund): array => [$fund['id'] => '#'.$fund['id'].' '.$fund['kind'].' · Bs '.Bolivianos::format($fund['remaining_cents'])])->all()), ...self::fields($accounts)];
            })->action(function (CashBox $record, array $data): void {
                $session = CashBoxSession::where('cash_box_id', $record->id)->findOrFail($data['session_id']);
                app(RecordCashBoxTemporaryFundAction::class)->execute(auth()->user(), $session->id, $data);
                Notification::make()->title('Fondo temporal registrado')->success()->send();
            });
    }
}
