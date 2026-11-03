<?php

namespace App\Domain\Configuraciones\Actions;

use App\Domain\Configuraciones\Models\PersonnelRuleVersion;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class SavePersonnelRulesAction
{
    public function __construct(private PersonnelRules $rules, private VerifyPersonalPinAction $verify) {}

    /** @param array<string,mixed> $data */
    public function execute(User $actor, #[SensitiveParameter] array $data): PersonnelRuleVersion
    {
        app(AdministrativeReview::class)->authorize($actor, 'configuraciones.personal');
        $v = Validator::make($data, ['tolerance_minutes' => ['required', 'integer', 'min:0', 'max:1440'], 'high_difference_cents' => ['sometimes', 'integer', 'min:1', 'max:99999999900'], 'reason' => ['required', 'string', 'min:3', 'max:2000'], 'pin' => ['required', 'string'], 'request_key' => ['required', 'uuid'], 'delay_multiplier' => ['sometimes', 'integer', 'in:1,2,3'], 'multiplier_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'], 'automatic_credit' => ['sometimes', 'boolean'], 'expected_version_id' => ['sometimes', 'integer', 'min:0'],
            'morning_start' => ['sometimes', 'date_format:H:i'], 'morning_end' => ['sometimes', 'date_format:H:i'],
            'afternoon_start' => ['sometimes', 'date_format:H:i'], 'afternoon_end' => ['sometimes', 'date_format:H:i'],
            'morning_closing_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'afternoon_closing_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'afternoon_tolerance_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'morning_control_enabled' => ['sometimes', 'boolean'], 'afternoon_control_enabled' => ['sometimes', 'boolean'],
            'cash_box_controls_enabled' => ['sometimes', 'boolean'], 'schedule_enabled' => ['sometimes', 'boolean'], 'schedule_cash_box_ids' => ['sometimes', 'nullable', 'array'],
            'schedule_cash_box_ids.*' => ['integer', 'distinct', 'exists:cash_boxes,id']])->validate();
        if (array_key_exists('cash_box_controls_enabled', $v)) {
            $v['schedule_enabled'] = $v['cash_box_controls_enabled'];
        }
        if (isset($v['schedule_cash_box_ids'])) {
            $v['schedule_cash_box_ids'] = array_map('intval', $v['schedule_cash_box_ids']);
            sort($v['schedule_cash_box_ids']);
        }
        $this->verify->execute($actor, $v['pin']);
        $version = DB::transaction(function () use ($actor, $v): PersonnelRuleVersion {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(AdministrativeReview::class)->authorize($actor, 'configuraciones.personal');
            if ($existing = PersonnelRuleVersion::where('request_key', $v['request_key'])->first()) {
                if ($existing->created_by !== $actor->id) {
                    throw new AuthorizationException;
                }

                foreach (['tolerance_minutes', 'high_difference_cents', 'delay_multiplier', 'multiplier_minutes', 'automatic_credit', ...ScheduleConfiguration::FIELDS] as $key) {
                    $stored = in_array($key, ScheduleConfiguration::FIELDS, true)
                        ? (app(ScheduleConfiguration::class)->values($existing->values)[$key] ?? null)
                        : ($existing->values[$key] ?? null);
                    $changed = $key === 'schedule_cash_box_ids' ? $stored !== ($v[$key] ?? null) : $stored != ($v[$key] ?? null);
                    if (array_key_exists($key, $v) && $changed) {
                        throw ValidationException::withMessages(['request_key' => 'Este envío ya se utilizó con otros valores. Recarga los ajustes.']);
                    }
                }
                if ($existing->reason !== $v['reason']) {
                    throw ValidationException::withMessages(['request_key' => 'Este envío ya tiene otro motivo registrado.']);
                }

                return $existing;
            }
            $this->rules->forget();
            $before = $this->rules->current();
            if (isset($v['expected_version_id']) && (int) $v['expected_version_id'] !== $before['version_id']) {
                throw ValidationException::withMessages(['reason' => 'La configuración cambió mientras la revisabas. Recarga antes de guardar.']);
            }
            $values = array_merge($before, array_intersect_key($v, array_flip(['tolerance_minutes', 'high_difference_cents', 'delay_multiplier', 'multiplier_minutes', 'automatic_credit', ...ScheduleConfiguration::FIELDS])), ['grace_minutes' => 0]);
            if (array_intersect(ScheduleConfiguration::FIELDS, array_keys($v))) {
                app(ScheduleConfiguration::class)->validate($values);
            }
            unset($values['version_id']);
            $version = PersonnelRuleVersion::create(['created_by' => $actor->id, 'request_key' => $v['request_key'], 'reason' => $v['reason'], 'values' => $values, 'effective_from' => now()]);
            DB::table('user_audits')->insert(['actor_user_id' => $actor->id, 'subject_user_id' => $actor->id, 'action' => 'personnel_rules_updated', 'changes' => json_encode(['before' => $before, 'after' => $values, 'version_id' => $version->id, 'reason' => $v['reason']], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $version;
        });
        $this->rules->forget();

        return $version;
    }
}
