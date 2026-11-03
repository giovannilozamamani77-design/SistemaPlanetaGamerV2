<?php

namespace App\Filament\Resources\Cajas\Types;

use App\Domain\Cajas\Actions\SaveCashBoxTypeAction;
use App\Domain\Cajas\Models\CashBoxType;
use App\Filament\Resources\Cajas\Types\Pages\ListCashBoxTypes;
use App\Filament\Resources\Cajas\Types\Schemas\CashBoxTypeForm;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CashBoxTypeResource extends Resource
{
    protected static ?string $model = CashBoxType::class;

    protected static ?string $navigationLabel = 'Tipos y plantillas de cajas';

    protected static ?string $modelLabel = 'tipo de caja';

    protected static ?string $pluralModelLabel = 'Tipos y plantillas de cajas';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuraciones';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('administrador') && auth()->user()->can('cajas.catalogo.gestionar');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(CashBoxTypeForm::fields());
    }

    public static function table(Table $table): Table
    {
        return $table->heading('Plantillas de componentes')->description('Cambiar una plantilla afecta a nuevas cajas. Las existentes conservan su configuración hasta una confirmación explícita.')
            ->paginated([5])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')->recordUrl(null)->recordAction('editar')
            ->columns([
                TextColumn::make('name')->label('Tipo de caja')->searchable()->description(fn (CashBoxType $record): string => $record->code),
                TextColumn::make('family')->label('Circuito')->badge(),
                TextColumn::make('component_count')->label('Componentes')->state(fn (CashBoxType $record): int => count($record->components)),
                TextColumn::make('is_active')->label('Disponibilidad')->formatStateUsing(fn (bool $state): string => $state ? 'Activo' : 'Inactivo')->badge(),
            ])->recordActions([
                Action::make('editar')->label('Editar plantilla')->icon('heroicon-o-pencil-square')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()
                    ->modalHeading('Editar tipo y saldos predeterminados')->modalSubmitActionLabel('Guardar plantilla')->schema(CashBoxTypeForm::fields(true))
                    ->fillForm(fn (CashBoxType $record): array => $record->only(['code', 'name', 'family', 'is_active', 'components']) + ['request_key' => (string) Str::uuid(), 'expected_revision' => $record->revision])
                    ->action(fn (#[\SensitiveParameter] array $data) => app(SaveCashBoxTypeAction::class)->execute(auth()->user(), $data)),
                Action::make('historial')->label('Ver cambios')->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                    ->schema(function (CashBoxType $record): array {
                        abort_unless(self::canViewAny(), 403);
                        $rows = DB::table('cash_box_type_audits')->where('cash_box_type_id', $record->id)->latest('id')->limit(5)->get();
                        if ($rows->isEmpty()) {
                            return [Section::make('Sin cambios administrativos')->description('Plantilla inicial del sistema.')];
                        }

                        return $rows->map(fn ($row): Section => Section::make('Cambio #'.$row->id)->description($row->created_at)->schema([
                            TextEntry::make('reason_'.$row->id)->label('Motivo')->state($row->reason),
                            TextEntry::make('actor_'.$row->id)->label('Administración')->state('#'.$row->actor_user_id),
                        ]))->all();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCashBoxTypes::route('/')];
    }
}
