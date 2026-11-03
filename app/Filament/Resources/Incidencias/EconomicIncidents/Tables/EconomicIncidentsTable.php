<?php

namespace App\Filament\Resources\Incidencias\EconomicIncidents\Tables;

use App\Domain\Incidencias\Actions\DeclareRecoveryAction;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReverseRecoveryAction;
use App\Domain\Incidencias\Actions\ReviewEconomicIncidentAction;
use App\Domain\Incidencias\Actions\ReviewRepaymentReportAction;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Incidencias\Queries\PersonnelBalancesQuery;
use App\Filament\Support\Personal\PersonnelDetails;
use App\Filament\Support\Personal\PersonnelLabels;
use App\Filament\Support\Personal\PersonnelModal;
use App\Filament\Support\Personal\PersonnelTable;
use App\Models\User;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class EconomicIncidentsTable
{
    public static function configure(Table $table): Table
    {
        return PersonnelTable::configure($table, 'Faltantes del personal', 'El faltante conserva su origen. Una declaración de pago no reduce la deuda hasta verificar la recepción.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user')->withExists([
                'recoveries as has_pending_recovery' => fn (Builder $query): Builder => $query->whereNull('confirmed_at')->whereNull('rejected_at'),
                'recoveries as has_verified_recovery' => fn (Builder $query): Builder => $query->where('kind', 'RECUPERACION')->whereNotNull('confirmed_at'),
            ]))->filters([
                PersonnelTable::employee(),
                SelectFilter::make('status')->label('Estado')->options(PersonnelLabels::options(['DETECTADA', 'EN_REVISION', 'CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO', 'REPUESTA', 'ANULADA', 'CONDONADA', 'CARGO_BANCARIO', 'CONDONADA_AUTOMATICA_MENOR'])),
                SelectFilter::make('component')->label('Componente')->options(['efectivo' => 'Efectivo', 'bcp' => 'Cuenta BCP', 'cuenta_bcp' => 'Cuenta BCP (referencia anterior)', 'yape' => 'Yape']),
                PersonnelTable::dates('occurred_at'),
            ])->columns([
                TextColumn::make('user.name')->label('Cajera')->searchable()->weight('semibold')
                    ->description(fn (EconomicIncident $record): string => $record->box_label ?: 'Origen: '.$record->source_module),
                TextColumn::make('occurred_at')->label('Fecha')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('component')->label('Componente')->formatStateUsing(fn (string $state): string => match (mb_strtolower($state)) {
                    'bcp','cuenta_bcp' => 'Cuenta BCP','yape' => 'Yape','efectivo' => 'Efectivo',default => $state
                }),
                TextColumn::make('amount_cents')->label('Importe original')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::format($state)),
                TextColumn::make('pending_cents')->label('Por resolver')->formatStateUsing(fn (int $state, EconomicIncident $record): string => $state === 0 && in_array($record->status, ['DETECTADA', 'EN_REVISION'], true) ? 'Por confirmar' : 'Bs '.Bolivianos::format($state))->weight('semibold')->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(PersonnelLabels::text(...))->color(PersonnelLabels::color(...)),
            ])->headerActions([
                PersonnelTable::modal(Action::make('registrarFaltante'))->label('Registrar faltante')->icon('heroicon-o-plus')->modalHeading('Nuevo faltante para revisión')->schema(PersonnelModal::fields([
                    Hidden::make('reference')->default(fn (): string => (string) Str::uuid()),
                    Select::make('user_id')->label('Responsable inicial')->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                    TextInput::make('component')->label('Componente')->helperText('Por ejemplo: efectivo, bcp o yape.')->live(onBlur: true)->required(),
                    TextInput::make('amount')->label('Importe')->prefix('Bs')->required(),
                    TextInput::make('box_label')->label('Caja / referencia de origen')->maxLength(120),
                    Select::make('cause')->label('Causa por verificar')->options(fn (Get $get): array => in_array(mb_strtolower(trim($get('component') ?? '')), ['bcp', 'cuenta_bcp'], true) ? ['CAJERA' => 'Diferencia por revisar', 'CARGO_BANCARIO' => 'Posible cargo bancario BCP'] : ['CAJERA' => 'Diferencia por revisar'])->default('CAJERA')->required(),
                    Textarea::make('reason')->label('Hecho, origen y evidencia')->helperText('Hasta Bs 2: registro breve. Más de Bs 2: motivo obligatorio.'),
                ]))->action(function (array $data): void {
                    app(ReportEconomicIncidentAction::class)->execute(auth()->user(), User::findOrFail($data['user_id']), ['source_module' => 'manual', 'source_reference' => $data['reference'], 'component' => $data['component'], 'amount_cents' => Bolivianos::cents($data['amount']), 'reason' => $data['reason'] ?? null, 'box_label' => $data['box_label'] ?? null, 'cause' => $data['cause'], 'occurred_at' => now()->format('Y-m-d H:i:s')]);
                }),
            ])->recordActions([PersonnelDetails::action(), PersonnelTable::resolve([
                Action::make('resumenPersonal')->label('Resumen de la cajera')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                    ->schema(function (EconomicIncident $record): array {
                        $balances = app(PersonnelBalancesQuery::class)->execute(auth()->user(), $record->user);
                        $entries = [];
                        foreach (['total' => 'Confirmado', 'received' => 'Recibido', 'salary' => 'Sueldo aplicado', 'forgiven' => 'Perdonado', 'pending' => 'Pendiente', 'previous' => 'Periodos anteriores', 'current' => 'Periodo actual'] as $key => $label) {
                            $entries[] = TextEntry::make($key)->label($label)->state('Bs '.Bolivianos::format($balances[$key]));
                        }

                        return [Section::make('Saldos de la cajera')->schema($entries)->columns(['default' => 1, 'md' => 2])->columnSpanFull()];
                    }),

                Action::make('registrarAporteRecibido')->label('Declarar reposición de este faltante')->icon('heroicon-o-banknotes')->modalDescription('Registra una declaración de entrega. Después usa «Verificar recepción» para confirmar el ingreso o descuento real.')->visible(fn (EconomicIncident $record): bool => $record->pending_cents > 0)->schema(PersonnelModal::fields([
                    Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('paid_by_user_id')->label('Persona que entrega')->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                    TextInput::make('amount')->label('Monto entregado')->prefix('Bs')->required(),
                    Select::make('method')->label('Medio')->options(['EFECTIVO' => 'Efectivo', 'DEPOSITO' => 'Depósito', 'QR' => 'QR', 'SUELDO' => 'Aplicación salarial'])->required(),
                    TextInput::make('operation_reference')->label('Operación'), TextInput::make('destination')->label('Destino'),
                    TextInput::make('salary_period')->label('Periodo salarial'),
                    Textarea::make('note')->label('Constancia')->required(),
                ]))->action(function (EconomicIncident $record, array $data): void {
                    $data['request_key'] = $data['key'];
                    $data['kind'] = 'RECUPERACION';
                    $data['amount_cents'] = Bolivianos::cents($data['amount']);
                    app(DeclareRecoveryAction::class)->execute(auth()->user(), $record, $data);
                }),
                Action::make('revertirRecuperacion')->label('Registrar devolución real')->visible(fn (EconomicIncident $record): bool => (bool) $record->has_verified_recovery)->icon('heroicon-o-arrow-uturn-left')->schema(PersonnelModal::fields([
                    Select::make('payment_id')->label('Recuperación verificada')->options(fn (EconomicIncident $record): array => $record->recoveries()->where('kind', 'RECUPERACION')->whereNotNull('confirmed_at')->get()->mapWithKeys(fn (RecoveryPayment $p): array => [$p->id => $p->method.' · Bs '.Bolivianos::format($p->amount_cents)])->all())->required(),
                    Toggle::make('returned')->label('La devolución/corrección real ya fue aplicada')->accepted(),
                    Textarea::make('receipt')->label('Constancia de la reversa')->required(),
                    Toggle::make('exception')->label('Autoaprobación excepcional: único administrador'),
                ]))->action(function (EconomicIncident $record, array $data): void {
                    app(ReverseRecoveryAction::class)->execute(auth()->user(), $record->recoveries()->findOrFail($data['payment_id']), (bool) $data['returned'], $data['receipt'], (bool) ($data['exception'] ?? false));
                }),
                Action::make('revisar')->label('Revisar o cambiar estado')->icon('heroicon-o-pencil-square')->visible(fn (EconomicIncident $record): bool => ! in_array($record->status, ['REPUESTA', 'ANULADA', 'CONDONADA', 'CONDONADA_AUTOMATICA_MENOR', 'CARGO_BANCARIO'], true))->modalDescription('Los faltantes de caja ya están activos. Puedes cancelar o perdonar con tu PIN, sin borrar el historial. Reponer exige dinero recibido; no se simula un pago.')->schema(PersonnelModal::fields([
                    Select::make('decision')->label('Decisión')->options(fn (EconomicIncident $record): array => ($record->source_module === 'cajas' ? [] : ['CONFIRMAR' => 'Confirmar responsabilidad']) + ['ANULAR' => 'Cancelar faltante', 'CONDONAR' => 'Perdonar pendiente', 'CARGO_BANCARIO' => 'Validar cargo bancario BCP'])->required()->live(),
                    TextInput::make('second_amount')->label(fn (EconomicIncident $record): string => 'Segunda comprobación si supera Bs '.Bolivianos::format($record->policy_snapshot['high_difference_cents'] ?? 10000))->prefix('Bs'),
                    Textarea::make('reason')->label('Observación (opcional)'),
                    TextInput::make('pin')->label('PIN personal de Administración')->password()->revealable()->visible(fn (Get $get): bool => in_array($get('decision'), ['ANULAR', 'CONDONAR', 'CARGO_BANCARIO'], true))->required(fn (Get $get): bool => in_array($get('decision'), ['ANULAR', 'CONDONAR', 'CARGO_BANCARIO'], true))->maxLength(4),
                    Toggle::make('exception')->label('Autoaprobación excepcional: único administrador'),
                ]))->action(function (EconomicIncident $record, array $data): void {
                    app(ReviewEconomicIncidentAction::class)->execute(auth()->user(), $record, $data['decision'], $data['reason'] ?? '', filled($data['second_amount'] ?? null) ? Bolivianos::cents($data['second_amount']) : null, (bool) ($data['exception'] ?? false), $data['pin'] ?? null);
                }),
                Action::make('verificarRecepcion')->label('Verificar recepción')->icon('heroicon-o-check-badge')->visible(fn (EconomicIncident $record): bool => (bool) $record->has_pending_recovery)->schema(PersonnelModal::fields([
                    Select::make('payment_id')->label('Aporte declarado')->options(fn (EconomicIncident $record): array => $record->recoveries()->whereNull('confirmed_at')->whereNull('rejected_at')->get()->mapWithKeys(fn (RecoveryPayment $p): array => [$p->id => $p->method.' · Bs '.Bolivianos::format($p->amount_cents).' · ID '.($p->operation_reference ?: 'Constancia interna')])->all())->required(),
                    Toggle::make('received')->label('Ingreso o descuento real verificado')->accepted(),
                    TextInput::make('second_amount')->label('Segunda comprobación del faltante si aún no estaba confirmado')->prefix('Bs'),
                    Textarea::make('reason')->label('Constancia de recepción/aplicación')->required(),
                    Toggle::make('exception')->label('Autoaprobación excepcional: único administrador'),
                ]))->action(function (EconomicIncident $record, array $data): void {
                    $payment = $record->recoveries()->findOrFail($data['payment_id']);
                    app(ReviewRepaymentReportAction::class)->execute(auth()->user(), $payment->id, 'CONFIRMAR', $data['reason'], filled($data['second_amount'] ?? null) ? Bolivianos::cents($data['second_amount']) : null, (bool) ($data['exception'] ?? false), (bool) $data['received']);
                }),
                Action::make('aporteAdministrativo')->label('Registrar aporte de Administración')->visible(fn (EconomicIncident $record): bool => $record->pending_cents > 0)->schema(PersonnelModal::fields([
                    Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
                    TextInput::make('amount')->label('Importe aportado')->prefix('Bs')->required(),
                    Textarea::make('note')->label('Constancia del aporte')->required(),
                ]))->action(function (EconomicIncident $record, array $data): void {
                    app(DeclareRecoveryAction::class)->execute(auth()->user(), $record, ['request_key' => $data['key'], 'kind' => 'APORTE_ADMINISTRATIVO', 'method' => 'EFECTIVO', 'amount_cents' => Bolivianos::cents($data['amount']), 'note' => $data['note']]);
                }),
            ])]);
    }
}
