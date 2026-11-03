<?php

namespace App\Filament\Support\Cajas;

use App\Domain\Cajas\Actions\MarkCashBoxCorrectionReadAction;
use App\Domain\Cajas\Actions\RecordCashBoxOperationAction;
use App\Domain\Cajas\Actions\RequestCashBoxCorrectionAction;
use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Cajas\Models\CashBoxOperation;
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

class CashBoxOperatingActions
{
    private static function key(): Hidden
    {
        return Hidden::make('request_key')->default(fn (): string => (string) Str::uuid());
    }

    private static function accounts(CashBoxOperations $page): array
    {
        return array_map(fn (array $a): string => $a['name'], $page->workspace['accounts']);
    }

    public static function movement(CashBoxOperations $page, ?string $preset = null): Action
    {
        return Action::make(match ($preset) {
            'ACH' => 'registrarAch','FACTURAS' => 'registrarServicios',default => 'registrarOperacion'
        })->label(match ($preset) {
            'ACH' => 'Registrar ACH','FACTURAS' => 'Registrar servicios',default => 'Otro movimiento'
        })->modalWidth('4xl')->stickyModalHeader()->stickyModalFooter()
            ->modalDescription('Registra el movimiento entre componentes y su comisión. No realiza transferencias bancarias por sí mismo.')
            ->schema(fn (): array => [
                Section::make('Movimiento principal')->columns(['default' => 1, 'md' => 3])->schema([
                    TextInput::make('amount')->label('Importe principal')->prefix('Bs')->default('0')->required(),
                    Select::make('source')->label('Componente que disminuye')->options(self::accounts($page)),
                    Select::make('destination')->label('Componente que aumenta')->options(self::accounts($page))]),
                Section::make('Comisión separada')->columns(['default' => 1, 'md' => 2])->schema([
                    Select::make('fee_type')->label('Cálculo')->options(['NINGUNA' => 'Sin comisión automática', 'FACTURAS' => 'Facturas distintas × Bs 0,50', 'ACH' => 'Transferencia ACH externa'])->default($preset ?? 'NINGUNA')->required(),
                    TextInput::make('bill_count')->label('Cantidad de facturas distintas')->integer()->default(0),
                    Toggle::make('many_bills_confirmed')->label('Comprobé la cantidad mayor a cinco'),
                    Toggle::make('ach_external_confirmed')->label('ACH: destino externo distinto de BCP/Yape'),
                    Select::make('fee_component')->label('Dónde se recibió la comisión')->options(self::accounts($page))->required(),
                    TextInput::make('manual_fee')->label('Comisión manual opcional')->prefix('Bs')]),
                Section::make('Constancia')->schema([Textarea::make('reason')->label('Motivo de comisión manual / observación')->maxLength(2000), self::key()])])
            ->action(function (#[\SensitiveParameter] array $data) use ($page): void {
                app(RecordCashBoxOperationAction::class)->execute(auth()->user(), $page->workspace['session_id'], $data);
                $page->operationsPage = 1;
                unset($page->workspace);
                Notification::make()->title('Operación registrada')->success()->send();
            });
    }

    public static function correction(CashBoxOperations $page): Action
    {
        return Action::make('solicitarCorreccion')->label('Solicitar corrección')->color('gray')
            ->afterFormFilled(fn (array $arguments) => self::markCorrectionRead($page, $arguments))
            ->modalHeading('Solicitar o completar corrección')->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()
            ->modalSubmitActionLabel('Enviar solicitud')->modalCancelActionLabel('Cancelar')
            ->modalDescription('La operación no se elimina. Administración revisará el motivo y podrá autorizar su anulación. Después podrás registrar el dato correcto.')
            ->fillForm(fn (array $arguments): array => ['operation_id' => $arguments['operation_id'] ?? null, 'reason' => collect($page->workspace['correction_feedback'])->firstWhere('id', $arguments['operation_id'] ?? null)['correction_reason'] ?? null, 'request_key' => (string) Str::uuid()])
            ->modalContent(fn (array $arguments) => ($request = self::correctionRecord($page, $arguments)) ? view('cajas.correction-detail', ['request' => $request]) : null)
            ->schema(fn (array $arguments): array => [
                Section::make('Operación a revisar')->schema([
                    Select::make('operation_id')->label('Operación')->options(collect([...$page->workspace['operations'], ...$page->workspace['correction_feedback']])->unique('id')
                        ->filter(fn (array $op): bool => $op['status'] === 'VIGENTE' && $op['fee_type'] !== 'INTERNACIONAL' && in_array($op['correction_status'], [null, 'DEVUELTA'], true))
                        ->mapWithKeys(fn (array $op): array => [$op['id'] => '#'.$op['id'].' · '.$op['label'].' · comisión Bs '.Bolivianos::format($op['fee_cents'])])->all())
                        ->disabled(isset($arguments['operation_id']))->dehydrated()->required(),
                    Textarea::make('reason')->label('¿Qué ocurrió y cuál es el dato correcto?')->helperText('Explica el error para que Administración pueda revisarlo.')->required()->minLength(3)->maxLength(2000), self::key()])])
            ->action(function (array $arguments, #[\SensitiveParameter] array $data) use ($page): void {
                $operation = CashBoxOperation::where('cash_box_session_id', $page->workspace['session_id'])->findOrFail($arguments['operation_id'] ?? $data['operation_id']);
                $data['operation_id'] = $operation->id;
                app(RequestCashBoxCorrectionAction::class)->execute(auth()->user(), $operation, $data);
                unset($page->workspace);
                $page->dispatch('cash-box-correction-changed', accessId: $page->accessId);
                Notification::make()->title('Solicitud enviada a Administración')->body('La operación se conserva mientras se revisa.')->success()->send();
            });
    }

    private static function correctionRecord(CashBoxOperations $page, array $arguments): ?CashBoxCorrection
    {
        return CashBoxCorrection::where('cash_box_session_id', $page->workspace['session_id'])
            ->where('cash_box_operation_id', $arguments['operation_id'] ?? 0)
            ->with(['operation', 'session.box', 'session.user', 'session.configuration', 'reviewer'])->first();
    }

    private static function markCorrectionRead(CashBoxOperations $page, array $arguments): void
    {
        $request = CashBoxCorrection::where('cash_box_session_id', $page->workspace['session_id'])
            ->where('cash_box_operation_id', $arguments['operation_id'] ?? 0)
            ->first(['id', 'cash_box_session_id', 'response_version', 'viewed_response_version']);
        if ($request && $request->response_version > $request->viewed_response_version) {
            app(MarkCashBoxCorrectionReadAction::class)->execute(auth()->user(), $request, $request->response_version);
            unset($page->workspace);
            $page->dispatch('cash-box-correction-changed', accessId: $page->accessId);
        }
    }

    public static function feedback(CashBoxOperations $page): Action
    {
        return Action::make('historialSolicitudes')->label('Historial de solicitudes y respuestas')->icon(fn (): string => $page->workspace['correction_alerts']['unread'] > 0 ? 'heroicon-o-bell-alert' : 'heroicon-o-clock')->iconButton()
            ->color(fn (): string => $page->workspace['correction_alerts']['unread'] > 0 ? 'primary' : ($page->workspace['correction_alerts']['returned'] > 0 ? 'warning' : 'gray'))
            ->extraAttributes(fn (): array => ['class' => $page->workspace['correction_alerts']['unread'] > 0 ? 'pg-history-unread' : ''])
            ->tooltip(fn (): string => 'Respuestas nuevas: '.$page->workspace['correction_alerts']['unread'].' · Devueltas para completar: '.$page->workspace['correction_alerts']['returned'])
            ->badge(fn (): ?string => $page->workspace['correction_alerts']['returned'] > 0 ? (string) $page->workspace['correction_alerts']['returned'] : null)
            ->modalHeading('Historial de solicitudes y respuestas')->modalDescription('Se muestran hasta cinco respuestas, priorizando las devueltas y las nuevas. Abre el detalle para marcar una respuesta como revisada.')
            ->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
            ->modalContent(fn () => view('cajas.correction-feedback', ['feedback' => $page->workspace['correction_feedback'], 'alerts' => $page->workspace['correction_alerts'], 'accessId' => $page->accessId]));
    }

    public static function response(CashBoxOperations $page): Action
    {
        return Action::make('respuestaCorreccion')->mountUsing(fn (array $arguments) => self::markCorrectionRead($page, $arguments))->label('Ver respuesta')->icon('heroicon-o-chat-bubble-left-right')->color('gray')
            ->modalHeading('Respuesta de Administración')->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()
            ->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
            ->modalContent(fn (array $arguments) => ($request = self::correctionRecord($page, $arguments)) ? view('cajas.correction-detail', ['request' => $request]) : null);
    }
}
