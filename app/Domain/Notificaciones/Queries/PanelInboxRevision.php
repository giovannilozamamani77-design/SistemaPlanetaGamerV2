<?php

namespace App\Domain\Notificaciones\Queries;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PanelInboxRevision
{
    private function store(): Repository
    {
        return Cache::store(config('notificaciones.revision_store', app()->runningUnitTests() ? 'array' : 'file'));
    }

    public function token(): string
    {
        try {
            return (string) $this->store()->get('panel-inbox:revision', 'initial');
        } catch (Throwable $error) {
            report($error);

            return 'cache-unavailable:'.intdiv(now()->timestamp, 60);
        }
    }

    public function afterCommit(): void
    {
        DB::afterCommit(fn () => $this->touch());
    }

    public function touch(): void
    {
        try {
            $this->store()->forever('panel-inbox:revision', (string) Str::uuid());
        } catch (Throwable $error) {
            report($error);
        }
    }
}
