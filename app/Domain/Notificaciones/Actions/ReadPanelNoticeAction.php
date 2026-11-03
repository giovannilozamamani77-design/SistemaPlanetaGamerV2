<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Models\PanelNotice;
use App\Domain\Notificaciones\Queries\PanelInboxQuery;
use App\Domain\Notificaciones\Queries\PanelInboxRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ReadPanelNoticeAction
{
    public function execute(User $actor, string $key): PanelNotice
    {
        return DB::transaction(function () use ($actor, $key): PanelNotice {
            $notice = app(PanelInboxQuery::class)->execute($actor)->whereKey($key)->first();
            if (! $notice) {
                throw new AuthorizationException('Este aviso ya no está disponible para tu cuenta.');
            }
            $inserted = DB::table('panel_notice_reads')->insertOrIgnore(['user_id' => $actor->id, 'notice_key' => $notice->id, 'version' => $notice->version, 'read_at' => now()]);

            if ($inserted) {
                app(PanelInboxRevision::class)->afterCommit();
            }

            return $notice;
        });
    }
}
