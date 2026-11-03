<?php

namespace App\Filament\Resources\Usuarios\Users\Pages;

use App\Domain\Usuarios\Actions\SaveUserAction;
use App\Filament\Resources\Usuarios\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveUserAction::class)->execute(auth()->user(), array_replace(['permissions' => [], 'restrictions' => []], $data));
    }
}
