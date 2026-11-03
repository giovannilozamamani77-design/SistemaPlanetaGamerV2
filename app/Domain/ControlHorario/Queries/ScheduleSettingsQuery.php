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

        return ['version_id' => $rules['version_id'], 'paused' => ! app(ScheduleConfiguration::class)->enabled(),
            'boxes' => CashBox::orderBy('name')->pluck('name', 'id')->all(),
            'settings' => array_intersect_key($rules, array_flip(['tolerance_minutes', 'delay_multiplier', 'multiplier_minutes', 'automatic_credit', ...ScheduleConfiguration::FIELDS]))];
    }
}
