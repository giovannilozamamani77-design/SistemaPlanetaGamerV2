<?php

namespace App\Filament\Support\Personal;

use Illuminate\Validation\ValidationException;

trait HandlesPersonnelActionErrors
{
    public function callMountedAction(array $arguments = []): mixed
    {
        try {
            return parent::callMountedAction($arguments);
        } catch (ValidationException $exception) {
            $name = $this->getMountedActionSchemaName();
            $path = $name ? $this->getSchema($name)?->getStatePath() : null;
            if (! $path) {
                throw $exception;
            }
            $messages = [];
            foreach ($exception->errors() as $field => $errors) {
                if (! str_starts_with($field, $path.'.')) {
                    $field = $path.'.'.(str_starts_with($field, 'data.') ? substr($field, 5) : $field);
                }
                $messages[$field] = $errors;
            }
            throw ValidationException::withMessages($messages)->errorBag($exception->errorBag);
        }
    }
}
