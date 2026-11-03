<?php

namespace App\Filament\Support\Envios;

use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Envios\Actions\CreateInternationalTransferAction;
use App\Domain\Envios\Actions\ManageInternationalTransferAction;
use App\Domain\Envios\Actions\SyncExchangeReferenceAction;
use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Calculators\TransferQuoteCalculator;
use App\Domain\Envios\Models\ExchangePolicy;
use App\Domain\Envios\Models\InternationalTransfer;
use App\Domain\Envios\Queries\InternationalTransferQuery;
use App\Filament\Pages\Envios\BrazilExchangeRates;
use App\Filament\Pages\Envios\InternationalTransfers;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InternationalTransferActions
{
    public const LABELS = [
        'collect' => 'Confirmar cobro', 'handover' => 'Entregar principal a Administración', 'ack_admin' => 'Confirmar principal recibido',
        'claim' => 'Tomar envío para ejecutar', 'confirm_pix' => 'Confirmar Pix enviado', 'receive_brl' => 'Confirmar reales recibidos', 'offer_funds' => 'Registrar aporte para pago',
        'receive_funds' => 'Confirmar aporte recibido', 'pay_local' => 'Confirmar pago al beneficiario', 'cancel' => 'Cancelar / iniciar devolución',
        'return_funds' => 'Registrar devolución de principal', 'receive_return' => 'Recibir principal devuelto', 'refund' => 'Reembolsar al cliente',
        'return_local_funds' => 'Devolver aporte sin utilizar', 'ack_local_return' => 'Confirmar aporte devuelto',
    ];

    public static function verifyBox(InternationalTransfers $page): Action
    {
        return Action::make('validarCaja')->label('Validar PIN de mi caja')->color('gray')->icon('heroicon-o-key')
            ->visible(fn (): bool => auth()->user()->can('envios.registrar'))->schema([
                Select::make('box_id')->label('Mi caja abierta')->options(fn () => CashBoxSession::query()->join('cash_boxes', 'cash_boxes.id', '=', 'cash_box_sessions.cash_box_id')->where('cash_box_sessions.user_id', auth()->id())->where('cash_box_sessions.status', 'ABIERTA')->whereNotNull('active_slot')->pluck('cash_boxes.name', 'cash_boxes.id')->all())->required(),
                TextInput::make('pin')->label('PIN personal')->password()->length(4)->required()->autocomplete('off'),
                Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
            ])->action(function (array $data) use ($page): void {
                $session = CashBoxSession::where('cash_box_id', $data['box_id'])->where('user_id', auth()->id())->where('status', 'ABIERTA')->whereNotNull('active_slot')->firstOrFail();
                $access = app(StartCashBoxAccessAction::class)->execute(auth()->user(), CashBox::findOrFail($session->cash_box_id), $data['pin'], $data['request_key']);
                $page->verifiedAccesses[$session->id] = $access->id;
                Notification::make()->title('PIN validado para tu caja')->success()->send();
            });
    }

    public static function configuration(): Action
    {
        return Action::make('configurarTasas')->label('Tasas y ajustes')->icon('heroicon-o-cog-6-tooth')
            ->visible(fn (): bool => InternationalTransferQuery::admin(auth()->user()))
            ->url(fn (): string => BrazilExchangeRates::getUrl());
    }

    public static function refreshReference(): Action
    {
        return Action::make('actualizarReferencia')->label('Actualizar referencia')->color('gray')
            ->visible(fn (): bool => InternationalTransferQuery::admin(auth()->user()))->action(function (): void {
                app(TransferAuthorization::class)->admin(auth()->user()->fresh());
                try {
                    $updated = app(SyncExchangeReferenceAction::class)->execute(auth()->user());
                } catch (\Throwable $exception) {
                    report($exception);
                    throw ValidationException::withMessages(['reference' => 'No se pudo consultar la referencia. Las tasas configuradas no se modificaron.']);
                }
                Notification::make()->title($updated ? 'Referencia actualizada' : 'Referencia vigente o actualización deshabilitada')->success()->send();
            });
    }

    public static function create(?int $fixedSession = null, ?int $fixedAccess = null, ?InternationalTransfers $page = null): Action
    {
        return Action::make('enviarBrasil')->label('Envíos a Brasil / Bolivia')->icon('heroicon-o-globe-americas')->modalWidth('4xl')->stickyModalHeader()->stickyModalFooter()
            ->modalSubmitActionLabel('Guardar cotización')->modalDescription('Guardar no cobra ni envía dinero. Revisa los montos con el cliente y después confirma el cobro o la recepción en Brasil.')
            ->fillForm(fn (): array => ['session_id' => $fixedSession, 'access_id' => $fixedAccess, 'component' => 'efectivo', 'direction' => 'BO_BR', 'currency' => 'BOB', 'request_key' => (string) Str::uuid()])
            ->schema([
                Section::make('Cotización')->schema([
                    Select::make('session_id')->label('Tu caja abierta')->options(fn () => CashBoxSession::query()->join('cash_boxes', 'cash_boxes.id', '=', 'cash_box_sessions.cash_box_id')->where('cash_box_sessions.user_id', auth()->id())->where('cash_box_sessions.status', 'ABIERTA')->whereNotNull('active_slot')->pluck('cash_boxes.name', 'cash_box_sessions.id')->all())->required()->live()->disabled($fixedSession !== null)->dehydrated(),
                    Select::make('component')->label('Cuenta que recibirá / entregará el dinero')->options(function (Get $get): array {
                        $session = CashBoxSession::where('user_id', auth()->id())->find($get('session_id'));

                        return $session ? array_map(fn ($a) => $a['name'], CashBoxConfiguration::findOrFail($session->configuration_id)->accounts) : [];
                    })->required(),
                    Select::make('direction')->label('Sentido')->options(['BO_BR' => 'Bolivia → Brasil', 'BR_BO' => 'Brasil → Bolivia'])->required()->live(),
                    Select::make('currency')->label('Ingresar monto en')->options(['BOB' => 'Bolivianos', 'BRL' => 'Reales'])->required()->live(),
                    TextInput::make('amount')->label('Monto')->inputMode('decimal')->required()->live(debounce: 400),
                    TextEntry::make('preview')->label('Resultado')->state(fn (Get $get): string => self::preview($get)),
                ])->columns(['default' => 1, 'md' => 2]),
                Section::make('Remitente y destinatario')->schema([
                    TextInput::make('sender_name')->label('Nombre de quien envía')->required()->maxLength(160),
                    TextInput::make('sender_whatsapp')->label('WhatsApp con código de país')->placeholder('591…')->required()->maxLength(20),
                    TextInput::make('recipient_name')->label('Nombre del beneficiario')->required()->maxLength(160),
                    TextInput::make('pix_key')->label('Clave Pix del beneficiario en Brasil')->visible(fn (Get $get): bool => $get('direction') === 'BO_BR')->required(fn (Get $get): bool => $get('direction') === 'BO_BR')->maxLength(160),
                    TextInput::make('bank')->label('Banco / institución (opcional)')->maxLength(160),
                    Hidden::make('access_id'), Hidden::make('request_key'),
                ])->columns(['default' => 1, 'md' => 2]),
            ])->action(function (array $data) use ($fixedSession, $fixedAccess, $page): void {
                if ($fixedSession) {
                    $data['session_id'] = $fixedSession;
                }
                $data['access_id'] = $fixedAccess ?? $page?->accessFor((int) $data['session_id']);
                if (! $data['access_id']) {
                    throw ValidationException::withMessages(['session_id' => 'Valida primero el PIN de tu caja en esta pantalla.']);
                }
                $transfer = app(CreateInternationalTransferAction::class)->execute(auth()->user(), $data);
                Notification::make()->title('Cotización #'.$transfer->id.' guardada')->body('Continúa en Envíos Brasil. Aún no se registró cobro.')->success()->send();
            });
    }

    public static function steps(InternationalTransfers $page): array
    {
        $actions = [];
        foreach (self::LABELS as $kind => $label) {
            $actions[] = Action::make($kind)->label($label)->color(in_array($kind, ['cancel', 'refund', 'return_local_funds'], true) ? 'warning' : 'primary')
                ->visible(fn (InternationalTransfer $record): bool => app(InternationalTransferQuery::class)->available(auth()->user(), $record, $kind))
                ->modalHeading($label)->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()
                ->modalDescription(fn (InternationalTransfer $record): string => 'Solicitud #'.$record->id.' · Cliente: Bs '.Bolivianos::format($record->bob_cents).' / R$ '.Bolivianos::format($record->brl_cents).' · Principal/aporte: Bs '.Bolivianos::format($record->principal_cents).' · Margen: Bs '.Bolivianos::format($record->margin_cents).'. Registra únicamente un hecho real; el sistema no ejecuta transferencias bancarias.')
                ->fillForm(function (InternationalTransfer $record) use ($page): array {
                    $context = app(InternationalTransferQuery::class)->context(auth()->user(), $record);
                    $context['access_id'] = $page->accessFor((int) $context['session_id']);

                    return [...$context, 'request_key' => (string) Str::uuid()];
                })
                ->schema([
                    Section::make('Constancia')->schema([
                        TextInput::make('reference')->label('Referencia bancaria real')->helperText('Obligatoria para confirmar Pix, recepción de reales o devolución de reales.')->maxLength(120),
                        Textarea::make('receipt')->label('Constancia / beneficiario verificado / entrega')->required()->minLength(3)->maxLength(2000),
                        FileUpload::make('receipt_file_path')->label('Comprobante adjunto (opcional)')->disk('local')->directory(fn (): string => 'envios/comprobantes/'.auth()->id())->visibility('private')->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(5120),
                        Toggle::make('confirmed')->label('Confirmo que el hecho indicado ya ocurrió y el importe es correcto')->accepted(),
                        Hidden::make('session_id'), Hidden::make('access_id'), Hidden::make('request_key'),
                    ]),
                ])->action(function (InternationalTransfer $record, array $data) use ($kind, $page): void {
                    if (in_array($kind, ManageInternationalTransferAction::OWNER_ACTIONS, true)) {
                        $data['access_id'] = $page->accessFor((int) ($data['session_id'] ?? 0));
                        if (! $data['access_id']) {
                            throw ValidationException::withMessages(['receipt' => 'Valida primero el PIN de tu caja en esta pantalla.']);
                        }
                    }
                    app(ManageInternationalTransferAction::class)->execute(auth()->user(), $record, $kind, $data);
                    Notification::make()->title('Registro guardado')->success()->send();
                });
        }

        return $actions;
    }

    public static function rateLabel(int $scaled): string
    {
        return intdiv($scaled, TransferQuoteCalculator::SCALE).'.'.str_pad((string) ($scaled % TransferQuoteCalculator::SCALE), 6, '0', STR_PAD_LEFT);
    }

    private static function preview(Get $get): string
    {
        $policy = ExchangePolicy::latest('id')->first();
        if (! $policy?->enabled) {
            return 'Administración debe configurar y habilitar las tasas.';
        }
        try {
            $q = app(TransferQuoteCalculator::class)->calculate((string) $get('direction'), (string) $get('currency'), (string) $get('amount'), $policy->base_rate, $policy->sell_rate, $policy->buy_rate);

            return 'Bs '.Bolivianos::format($q['bob_cents']).' ↔ R$ '.Bolivianos::format($q['brl_cents']).' · Tasa final: Bs '.self::rateLabel($q['commercial_rate']).' por real. Margen incluido.';
        } catch (ValidationException) {
            return 'Ingresa el monto para ver la conversión.';
        }
    }
}
