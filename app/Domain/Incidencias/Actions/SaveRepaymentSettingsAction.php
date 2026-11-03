<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Configuraciones\Models\PersonnelRuleVersion;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveRepaymentSettingsAction
{
    public function execute(User $actor, array $data): void
    {
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.confirmar');
        $v = Validator::make($data, ['destination_key' => ['required', 'string', 'max:120'], 'pin' => ['required', 'string'], 'request_key' => ['required', 'uuid']])->validate();
        app(VerifyPersonalPinAction::class)->execute($actor, $v['pin']);
        DB::transaction(function () use ($actor, $v): void {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(AdministrativeReview::class)->authorize($actor, 'incidencias.confirmar');
            if ($prior = PersonnelRuleVersion::where('request_key', $v['request_key'])->first()) {
                if ($prior->created_by !== $actor->id || ($prior->values['repayment_destination_key'] ?? null) !== $v['destination_key']) {
                    throw ValidationException::withMessages(['destination_key' => 'Solicitud ya utilizada con otros datos.']);
                }

                return;
            }
            if (! DB::table('cash_box_commission_accounts')->where('account_key', $v['destination_key'])->where('is_demo', false)->exists()) {
                throw ValidationException::withMessages(['destination_key' => 'Selecciona una cuenta de comisiones configurada.']);
            }
            $rules = app(PersonnelRules::class);
            $rules->forget();
            $values = $rules->current();
            unset($values['version_id']);
            PersonnelRuleVersion::create(['created_by' => $actor->id, 'request_key' => $v['request_key'], 'reason' => 'Destino de reposiciones de caja actualizado con PIN.', 'values' => [...$values, 'repayment_destination_key' => $v['destination_key']], 'effective_from' => now()]);
        });
        app(PersonnelRules::class)->forget();
    }
}
