<?php

namespace App\Filament\Resources\ControlHorario\ScheduledWorkdays\Pages;

use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\ScheduledWorkdayResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditScheduledWorkday extends EditRecord
{
    protected static string $resource = ScheduledWorkdayResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['work_date'] = $this->record->work_date;
        $data['starts_time'] = $this->record->starts_at->setTimezone('America/La_Paz')->format('H:i');
        $data['ends_time'] = $this->record->ends_at->setTimezone('America/La_Paz')->format('H:i');

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SaveScheduledWorkdayAction::class)->execute(auth()->user(), $data, $record);
    }
}
