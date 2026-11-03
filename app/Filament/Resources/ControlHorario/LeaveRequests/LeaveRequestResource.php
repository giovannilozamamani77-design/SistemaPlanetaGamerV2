<?php

namespace App\Filament\Resources\ControlHorario\LeaveRequests;

use App\Domain\ControlHorario\Models\LeaveRequest;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static ?string $pluralModelLabel = 'Permisos del personal';

    protected static string|\UnitEnum|null $navigationGroup = 'Personal';

    protected static ?string $navigationLabel = 'Permisos';

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    public static function table(Table $table): Table
    {
        return Tables\LeaveRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLeaveRequests::route('/')];
    }
}
