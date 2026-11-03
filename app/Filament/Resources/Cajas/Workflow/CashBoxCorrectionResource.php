<?php

namespace App\Filament\Resources\Cajas\Workflow;

use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Filament\Support\Cajas\CashBoxCorrectionActions;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashBoxCorrectionResource extends Resource
{
    protected static ?string $model = CashBoxCorrection::class;

    protected static ?string $pluralModelLabel = 'Correcciones de operaciones';

    protected static ?string $navigationLabel = 'Correcciones de operaciones';

    protected static string|\UnitEnum|null $navigationGroup = 'Cajas';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        return (auth()->user()?->hasRole('administrador') ?? false) && (auth()->user()?->can('cajas.operar') ?? false) && (auth()->user()?->can('cajas.liberar') ?? false);
    }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(static::canViewAny(), 403);
        $query = parent::getEloquentQuery()->with(['operation', 'session.box', 'session.user', 'session.configuration', 'reviewer']);
        $user = auth()->user();
        if ($user->hasRole('administrador') && $user->can('cajas.liberar')) {
            return $query;
        }

        return $query->whereIn('cash_box_session_id', CashBoxSession::where('user_id', $user->id)->select('id'));
    }

    public static function table(Table $table): Table
    {
        return $table->description('Revisa el registro original antes de decidir. Las operaciones y solicitudes siempre se conservan.')
            ->columns([
                TextColumn::make('id')->label('Solicitud')->sortable(),
                TextColumn::make('session.box.name')->label('Caja')->searchable(),
                TextColumn::make('session.user.name')->label('Cajera')->searchable(),
                TextColumn::make('cash_box_operation_id')->label('Operación'),
                TextColumn::make('created_at')->label('Solicitada')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('status')->label('Estado')->badge()->color(fn (string $state): string => match ($state) {
                    'APROBADA' => 'success', 'RECHAZADA' => 'danger', 'DEVUELTA' => 'warning', default => 'gray'
                }),
                TextColumn::make('reason')->label('Motivo')->limit(50)->wrap(),
            ])
            ->paginated([5])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')->recordUrl(null)
            ->recordActions(CashBoxCorrectionActions::actions());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCashBoxCorrectionRecords::route('/')];
    }
}
