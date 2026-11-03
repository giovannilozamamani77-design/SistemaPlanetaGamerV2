<?php

namespace App\Filament\Resources\Notificaciones\PanelNotices;

use App\Domain\Notificaciones\Actions\ReadPanelNoticeAction;
use App\Domain\Notificaciones\Models\PanelNotice;
use App\Domain\Notificaciones\Queries\PanelInboxQuery;
use App\Filament\Support\Notificaciones\NoticeDestination;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PanelNoticeResource extends Resource
{
    protected static ?string $model = PanelNotice::class;

    protected static ?string $pluralModelLabel = 'Notificaciones';

    protected static ?string $navigationLabel = 'Notificaciones';

    protected static ?string $slug = 'notificaciones';

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        return (auth()->user()?->is_active ?? false) && (auth()->user()?->can('panel.acceder') ?? false);
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
        return app(PanelInboxQuery::class)->execute(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereNull('notice_reads.read_at')->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table->heading('Tu bandeja')->description('Pendientes de atención primero. Leer un aviso no aprueba ni paga ninguna solicitud.')->poll(null)
            ->columns([
                TextColumn::make('priority')->label('Prioridad')->badge()->formatStateUsing(fn (int $state): string => $state === 0 ? 'Requiere atención' : 'Información')->color(fn (int $state): string => $state === 0 ? 'danger' : 'info'),
                TextColumn::make('title')->label('Aviso')->description(fn (PanelNotice $record): string => $record->summary)->wrap()->searchable(),
                TextColumn::make('occurred_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('read_state')->label('Lectura')->state(fn (PanelNotice $record): string => $record->read_at ? 'Leída' : 'Nueva')->badge()->color(fn (PanelNotice $record): string => $record->read_at ? 'gray' : 'warning'),
            ])->filters([
                SelectFilter::make('priority')->label('Prioridad')->options([0 => 'Requiere atención', 1 => 'Información'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('notice_items.priority', (int) $data['value']) : $query),
                TernaryFilter::make('lectura')->label('Lectura')->placeholder('Todas')->trueLabel('Leídas')->falseLabel('No leídas')->queries(true: fn (Builder $q) => $q->whereNotNull('notice_reads.read_at'), false: fn (Builder $q) => $q->whereNull('notice_reads.read_at')),
            ])->recordActions([
                Action::make('abrir')->label('Abrir')->icon('heroicon-o-arrow-top-right-on-square')->action(function (PanelNotice $record, $livewire) {
                    try {
                        $notice = app(ReadPanelNoticeAction::class)->execute(auth()->user(), $record->id);
                    } catch (AuthorizationException) {
                        Notification::make()->warning()->title('El aviso ya no está disponible. Actualiza la bandeja.')->send();
                        $livewire->resetTable();

                        return null;
                    }

                    $livewire->dispatch('panel-inbox-check');

                    return redirect(NoticeDestination::url($notice));
                }),
                Action::make('leida')->label('Marcar como leída')->icon('heroicon-o-check')->color('gray')->visible(fn (PanelNotice $record): bool => ! $record->read_at)->action(function (PanelNotice $record, $livewire): void {
                    try {
                        app(ReadPanelNoticeAction::class)->execute(auth()->user(), $record->id);
                    } catch (AuthorizationException) {
                        Notification::make()->warning()->title('El aviso ya no está disponible. Actualiza la bandeja.')->send();
                    }
                    $livewire->flushCachedTableRecords();
                    $livewire->dispatch('panel-inbox-check');
                }),
            ])->defaultSort(fn (Builder $query) => $query->orderBy('priority')->orderByRaw('notice_reads.read_at IS NOT NULL')->orderByDesc('occurred_at')->orderBy('notice_items.id'))
            ->paginated([5, 10, 25])->defaultPaginationPageOption(5)->recordUrl(null)->emptyStateHeading('No tienes avisos disponibles');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPanelNotices::route('/')];
    }
}
