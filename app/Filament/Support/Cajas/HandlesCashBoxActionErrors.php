<?php

namespace App\Filament\Support\Cajas;

use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

trait HandlesCashBoxActionErrors
{
    use HandlesPersonnelActionErrors { callMountedAction as private callMappedCashBoxAction; }

    public function callMountedAction(array $arguments = []): mixed
    {
        try {
            return $this->callMappedCashBoxAction($arguments);
        } catch (ValidationException $exception) {
            $messages = array_values(array_unique(array_merge(...array_values($exception->errors()))));
            Notification::make()->title('No se pudo completar la acción')->body(implode(' · ', array_map(fn (string $message): string => e($message), $messages)))->danger()->persistent()->send();
            throw $exception;
        }
    }
}
