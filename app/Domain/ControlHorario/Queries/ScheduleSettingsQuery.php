<?php

namespace App\Domain\ControlHorario\Queries;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;

class ScheduleSettingsQuery
{
    public function execute(User $actor): array
    {
        app(AdministrativeReview::class)->authorize($actor, 'configuraciones.personal');
        $rules = app(ScheduleConfiguration::class)->values(app(PersonnelRules::class)->current());

        $boxes = CashBox::orderBy('name')->get(['id', 'name', 'code']);
        $paused = ! app(ScheduleConfiguration::class)->enabled();
        $selected = $rules['schedule_cash_box_ids'] ?? ($paused ? [] : $boxes->modelKeys());
        $selected = array_map('intval', $selected);
        $rules['schedule_cash_box_ids'] = $selected;

        return ['version_id' => $rules['version_id'], 'paused' => $paused,
            'boxes' => $boxes->mapWithKeys(fn (CashBox $box): array => [$box->id => $box->code.' · '.$box->name])->all(),
            'box_controls' => $boxes->map(fn (CashBox $box): array => [
                'id' => $box->id, 'name' => $box->name, 'code' => $box->code,
                'selected' => in_array($box->id, $selected, true),
                'enabled' => ! $paused && in_array($box->id, $selected, true),
            ])->all(),
            'settings' => array_intersect_key($rules, array_flip(['tolerance_minutes', 'delay_multiplier', 'multiplier_minutes', 'automatic_credit', ...ScheduleConfiguration::FIELDS]))];
    }
}
