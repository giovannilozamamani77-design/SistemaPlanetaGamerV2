<?php

namespace App\Filament\Resources\ControlHorario\ScheduledWorkdays\Pages;

use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\ScheduledWorkdayResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateScheduledWorkday extends CreateRecord
{
    protected static string $resource = ScheduledWorkdayResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveScheduledWorkdayAction::class)->execute(auth()->user(), $data);
    }
}
