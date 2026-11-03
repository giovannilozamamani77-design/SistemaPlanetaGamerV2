<?php

namespace App\Filament\Pages\Envios;

use App\Domain\Envios\Models\ExchangePolicy;
use App\Domain\Envios\Models\InternationalTransfer;
use App\Domain\Envios\Queries\InternationalTransferQuery;
use App\Filament\Support\Cajas\HandlesCashBoxActionErrors;
use App\Filament\Support\Envios\InternationalTransferActions;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class InternationalTransfers extends Page implements HasTable
{
    use HandlesCashBoxActionErrors;
    use InteractsWithTable;

    protected string $view = 'envios.index';

    protected static ?string $title = 'Envíos Brasil';

    protected static string|\UnitEnum|null $navigationGroup = 'Cajas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-americas';

    #[Locked]
    public array $verifiedAccesses = [];

    public function accessFor(int $sessionId): ?int
    {
        return $this->verifiedAccesses[$sessionId] ?? null;
    }

    public static function canAccess(): bool
    {
        return config('envios.enabled', false) && (auth()->user()?->can('envios.ver') ?? false);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [InternationalTransferActions::verifyBox($this), InternationalTransferActions::create(page: $this)->visible(fn (): bool => auth()->user()->can('envios.registrar')), InternationalTransferActions::configuration(), InternationalTransferActions::refreshReference()];
    }

    #[Computed]
    public function overview(): array
    {
        $base = app(InternationalTransferQuery::class)->builder(auth()->user());

        return ['pending' => (clone $base)->whereNotIn('international_transfers.status', ['COMPLETADO', 'CANCELADO'])->count(),
            'held' => (int) (clone $base)->sum('international_transfers.local_held_cents'),
            'completed' => (clone $base)->where('international_transfers.status', 'COMPLETADO')->count()];
    }

    #[Computed]
    public function rates(): array
    {
        $policy = ExchangePolicy::latest('id')->first();
        $reference = DB::table('exchange_references')->latest('quoted_at')->first();

        return ['enabled' => (bool) $policy?->enabled, 'sell' => $policy ? InternationalTransferActions::rateLabel($policy->sell_rate) : null,
            'buy' => $policy ? InternationalTransferActions::rateLabel($policy->buy_rate) : null, 'reference' => $reference];
    }

    public static function statuses(): array
    {
        return ['COTIZADO' => 'Cotizado / por cobrar', 'COBRADO' => 'Cobrado / entregar principal', 'ENTREGA_PENDIENTE' => 'Principal entregado / confirmar recepción', 'PRINCIPAL_RECIBIDO' => 'Listo para enviar Pix',
            'BRL_RECIBIDO' => 'Reales recibidos / aportar bolivianos', 'APORTE_PENDIENTE' => 'Aporte enviado / confirmar recepción', 'LISTO_PARA_PAGAR' => 'Listo para pagar al beneficiario',
            'EN_PROCESO' => 'En ejecución por Administración', 'COMPLETADO' => 'Completado', 'CANCELADO' => 'Cancelado / devuelto', 'DEVOLUCION_ADMIN_PENDIENTE' => 'Administración debe devolver principal',
            'DEVOLUCION_EN_CAMINO' => 'Confirmar devolución recibida', 'REEMBOLSO_PENDIENTE' => 'Reembolsar al cliente', 'DEVOLUCION_LOCAL_PENDIENTE' => 'Confirmar aporte devuelto'];
    }

    public function table(Table $table): Table
    {
        return $table->query(app(InternationalTransferQuery::class)->builder(auth()->user()))->defaultSort('international_transfers.id', 'desc')->defaultPaginationPageOption(5)->paginationPageOptions([5, 10, 25])
            ->columns([
                TextColumn::make('id')->label('Solicitud')->prefix('#')->sortable(),
                TextColumn::make('direction')->label('Sentido')->formatStateUsing(fn ($state) => $state === 'BO_BR' ? 'Bolivia → Brasil' : 'Brasil → Bolivia'),
                TextColumn::make('box.name')->label('Caja'),
                TextColumn::make('sender_name')->label('Remitente')->searchable()->description(fn ($record) => $record->recipient_name),
                TextColumn::make('bob_cents')->label('Bolivianos')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::format((int) $state)),
                TextColumn::make('brl_cents')->label('Reales')->formatStateUsing(fn ($state) => 'R$ '.Bolivianos::format((int) $state)),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state),
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m H:i', 'America/La_Paz'),
            ])->filters([SelectFilter::make('direction')->label('Sentido')->options(['BO_BR' => 'Bolivia → Brasil', 'BR_BO' => 'Brasil → Bolivia']), SelectFilter::make('status')->label('Estado')->options(self::statuses())])
            ->recordActions([
                Action::make('detalle')->label('Ver expediente')->icon('heroicon-o-eye')->iconButton()->modalHeading('Expediente y comprobante')->modalWidth('4xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (InternationalTransfer $record) => view('envios.detail', ['transfer' => $record, 'events' => app(InternationalTransferQuery::class)->events($record->id), 'admin' => InternationalTransferQuery::admin(auth()->user())])),
                ActionGroup::make(InternationalTransferActions::steps($this))->label('Resolver')->icon('heroicon-o-ellipsis-horizontal'),
            ]);
    }
}
