<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Configuraciones\Actions\SavePersonnelRulesAction;
use App\Domain\Configuraciones\Models\PersonnelRuleVersion;
use App\Domain\ControlHorario\Queries\ScheduleSettingsQuery;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class SaveScheduleSectionAction
{
    private const FIELDS = [
        'control' => ['cash_box_controls_enabled'],
        'morning' => ['morning_control_enabled', 'morning_start', 'morning_end', 'tolerance_minutes', 'morning_closing_minutes'],
        'afternoon' => ['afternoon_control_enabled', 'afternoon_start', 'afternoon_end', 'afternoon_tolerance_minutes', 'afternoon_closing_minutes'],
        'delay' => ['delay_multiplier', 'multiplier_minutes', 'automatic_credit'],
        'boxes' => ['schedule_cash_box_ids'],
    ];

    public function execute(User $actor, string $section, #[SensitiveParameter] array $data): PersonnelRuleVersion
    {
        $state = app(ScheduleSettingsQuery::class)->execute($actor);
        if (! isset(self::FIELDS[$section])) {
            throw ValidationException::withMessages(['section' => 'Configuración no reconocida.']);
        }
        Validator::make($data, ['expected_version_id' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $existing = PersonnelRuleVersion::where('request_key', $data['request_key'])->where('created_by', $actor->id)->first();
        $tolerance = $existing ? ($existing->values['tolerance_minutes'] ?? 15) : $state['settings']['tolerance_minutes'];
        $patch = Arr::only($data, self::FIELDS[$section]);
        if ($section === 'boxes') {
            if ($data['all_boxes'] ?? false) {
                throw ValidationException::withMessages(['schedule_cash_box_ids' => 'Selecciona explícitamente las cajas que tendrán control.']);
            }
            $patch['schedule_cash_box_ids'] = $data['schedule_cash_box_ids'] ?? [];
        }
        if ($section === 'control') {
            $patch['schedule_cash_box_ids'] = $existing ? ($existing->values['schedule_cash_box_ids'] ?? []) : $state['settings']['schedule_cash_box_ids'];
        }

        return app(SavePersonnelRulesAction::class)->execute($actor, [
            'tolerance_minutes' => $tolerance,
            ...$patch,
            ...Arr::only($data, ['reason', 'pin', 'request_key', 'expected_version_id']),
        ]);
    }
}
