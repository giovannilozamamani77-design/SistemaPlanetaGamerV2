<?php

namespace App\Domain\Notificaciones\Observers;

use App\Domain\Notificaciones\Queries\PanelInboxRevision;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class NoticeSourceObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged()) {
            app(PanelInboxRevision::class)->touch();
        }
    }

    public function deleted(Model $model): void
    {
        app(PanelInboxRevision::class)->touch();
    }
}
