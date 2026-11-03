<?php

namespace App\Domain\Reportes\Actions;

use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

class RetryReportConfirmationAction
{
    public function execute(Closure $operation): mixed
    {
        $detector = new ConcurrencyErrorDetector;
        try {
            return retry(5, $operation, 50, fn (Throwable $error): bool => $error instanceof QueryException && $detector->causedByConcurrencyError($error));
        } catch (QueryException $error) {
            if ($detector->causedByConcurrencyError($error)) {
                throw ValidationException::withMessages(['preview' => 'Hay otra confirmación en curso. Intenta nuevamente; no se duplicará el registro.']);
            }
            throw $error;
        }
    }
}
