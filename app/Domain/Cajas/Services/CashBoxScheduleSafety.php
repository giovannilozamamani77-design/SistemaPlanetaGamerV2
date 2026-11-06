<?php

namespace App\Domain\Cajas\Services;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Only schedule work belongs here; financial authorization and validation stay outside. */
class CashBoxScheduleSafety
{
    public function attempt(Closure $work, mixed $fallback = null, bool $preserveRejections = false): mixed
    {
        try {
            return DB::transaction($work);
        } catch (Throwable $error) {
            if ($preserveRejections && ($error instanceof AuthorizationException || $error instanceof ValidationException)) {
                throw $error;
            }
            try {
                report($error);
            } catch (Throwable) {
                // Reporting a schedule outage must not stop the financial operation either.
            }

            return $fallback;
        }
    }
}
