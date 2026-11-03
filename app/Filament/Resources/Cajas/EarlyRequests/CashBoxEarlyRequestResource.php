<?php

namespace App\Filament\Resources\Cajas\EarlyRequests;

use App\Domain\Cajas\Actions\ReviewCashBoxEarlyCloseAction;
use App\Domain\Cajas\Models\CashBoxEarlyRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CashBoxEarlyRequestResource extends Resource
{
    protected static ?string $model = CashBoxEarlyRequest::class;

    protected static ?string $pluralModelLabel = 'Solicitudes de cierre anticipado';

    protected static ?string $navigationLabel = 'Solicitudes anticipadas';

    protected static ?string $slug = 'solicitudes-cierre-anticipado';

    protected static string|\UnitEnum|null $navigationGroup = 'Cajas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 25;

    public static function canViewAny(): bool
    {
        return (auth()->user()?->hasRole('administrador') ?? false) && (auth()->user()?->can('cajas.operar') ?? false) && (auth()->user()?->can('cajas.liberar') ?? false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(static::canViewAny(), 403);
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['session.user', 'session.box']);

        return $user->hasRole('administrador') && $user->can('cajas.liberar') ? $query : $query->whereHas('session', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('session.box.name')->label('Caja'),
            TextColumn::make('session.user.name')->label('Cajera'),
            TextColumn::make('period')->label('Turno')->formatStateUsing(fn (string $state): string => $state === 'manana' ? 'Mañana' : 'Tarde'),
            TextColumn::make('created_at')->label('Solicitada')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            TextColumn::make('status')->label('Estado')->badge()->color(fn (string $state): string => match ($state) {
                'APROBADA' => 'success', 'RECHAZADA' => 'danger', default => 'warning'
            }),
        ])->filters([SelectFilter::make('status')->label('Estado')->options(['PENDIENTE' => 'Pendiente', 'APROBADA' => 'Aprobada', 'RECHAZADA' => 'Rechazada'])])
            ->defaultSort('id', 'desc')->paginated([5, 10, 25])->defaultPaginationPageOption(5)->recordUrl(null)
            ->recordActions([
                Action::make('detail')->label('Ver detalle')->icon('heroicon-o-eye')->iconButton()->modalHeading('Solicitud de cierre anticipado')
                    ->modalContent(fn (CashBoxEarlyRequest $record) => view('cajas.early-close-status', ['request' => $record]))->modalSubmitAction(false)->modalCancelActionLabel('Cerrar'),
                self::review('approve', 'Aprobar', 'APROBADA', 'success', 'heroicon-o-check-circle'),
                self::review('reject', 'Rechazar', 'RECHAZADA', 'danger', 'heroicon-o-x-circle'),
            ]);
    }

    private static function review(string $name, string $label, string $decision, string $color, string $icon): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible(fn (CashBoxEarlyRequest $record): bool => auth()->user()->hasRole('administrador') && auth()->user()->can('cajas.liberar') && $record->status === 'PENDIENTE' && $record->session->status === 'ABIERTA')
            ->modalHeading($label.' cierre anticipado')->modalDescription('La caja seguirá abierta. Autorizar no registra el cierre ni descuenta tiempo hasta que la titular lo confirme.')
            ->schema([Textarea::make('reason')->label('Motivo de la decisión')->required()->minLength(3)->maxLength(2000),
                Toggle::make('exception')->label('Confirmación excepcional de mi propia solicitud')->visible(fn (CashBoxEarlyRequest $record): bool => $record->session->user_id === auth()->id()),
                Hidden::make('request_key')->default(fn (): string => (string) Str::uuid())])
            ->modalSubmitActionLabel($label)->modalCancelActionLabel('Volver sin registrar')
            ->action(fn (CashBoxEarlyRequest $record, array $data) => app(ReviewCashBoxEarlyCloseAction::class)->execute(auth()->user(), $record->id, [...$data, 'decision' => $decision]));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCashBoxEarlyRequests::route('/')];
    }
}
