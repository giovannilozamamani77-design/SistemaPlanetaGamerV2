<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Cajas\Data\CashBoxAccountSettings;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\CashBoxTypeCatalog;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class ConfigureCashBoxAction
{
    public function __construct(private AdministrativeReview $authorization, private VerifyPersonalPinAction $pin) {}

    public function execute(User $actor, CashBox $box, #[SensitiveParameter] array $data): CashBoxConfiguration
    {
        $this->authorization->authorize($actor, 'cajas.catalogo.gestionar');
        if (is_string($data['reason'] ?? null)) {
            $data['reason'] = trim($data['reason']);
        }
        foreach ((is_array($data['accounts'] ?? null) ? $data['accounts'] : []) as $code => $account) {
            if (is_array($account) && is_string($account['name'] ?? null)) {
                $data['accounts'][$code]['name'] = trim($account['name']);
            }
        }
        $data['shifts'] ??= CashBoxComponents::defaultShifts();
        $rules = [
            'base_capital' => ['required', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
            'expected_revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'request_key' => ['required', 'uuid'], 'pin' => ['sometimes', 'nullable', 'string'],
            'shifts' => ['required', 'array:manana,tarde'],
        ];
        foreach (['manana', 'tarde'] as $period) {
            $rules["shifts.$period"] = ['required', 'array:start,end,liquidates'];
            $rules["shifts.$period.start"] = ['required', 'date_format:H:i'];
            $rules["shifts.$period.end"] = ['required', 'date_format:H:i'];
            $rules["shifts.$period.liquidates"] = ['required', 'boolean'];
        }
        $v = Validator::make($data, $rules)->validate();
        if ($v['shifts']['manana']['start'] >= $v['shifts']['manana']['end']
            || $v['shifts']['tarde']['start'] >= $v['shifts']['tarde']['end']
            || $v['shifts']['manana']['end'] > $v['shifts']['tarde']['start']) {
            throw ValidationException::withMessages(['shifts' => 'Los turnos deben terminar después de su inicio y no superponerse.']);
        }
        foreach (['manana', 'tarde'] as $period) {
            $v['shifts'][$period]['liquidates'] = (bool) $v['shifts'][$period]['liquidates'];
        }
        if (filled($v['pin'] ?? null)) {
            $this->pin->execute($actor, $v['pin']);
        }
        $settings = CashBoxAccountSettings::normalize($box, $data);
        $accounts = $settings['accounts'];
        $capital = Bolivianos::cents($v['base_capital']);
        $hash = hash('sha256', json_encode([$box->id, $settings, $v['shifts'], $capital, (int) $v['expected_revision'], $v['reason']], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($actor, $box, $v, $accounts, $settings, $capital, $hash): CashBoxConfiguration {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, 'cajas.catalogo.gestionar');
            $current = CashBox::whereKey($box->id)->lockForUpdate()->firstOrFail();
            if ($existing = CashBoxConfiguration::where('request_key', $v['request_key'])->first()) {
                if ($existing->created_by !== $actor->id || $existing->cash_box_id !== $box->id) {
                    throw new AuthorizationException;
                }
                if ($existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['reason' => 'La solicitud ya contiene otros datos. Abre nuevamente el formulario.']);
                }

                return $existing;
            }
            if (CashBoxSession::where('cash_box_id', $box->id)->whereNotNull('active_slot')->exists()) {
                throw ValidationException::withMessages(['reason' => 'La caja debe estar cerrada y liberada antes de cambiar su configuración.']);
            }
            if ($current->revision !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['reason' => 'La configuración cambió. Cierra y vuelve a abrir el formulario.']);
            }
            if (app(ReservedCashBoxFunds::class)->forBox($box->id)) {
                throw ValidationException::withMessages(['reason' => 'Hay fondos internacionales en custodia. Completa su entrega o devolución antes de cambiar cuentas/capital.']);
            }
            CashBoxAccountSettings::assertContinuity($current, $settings['all'], $accounts);
            $version = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $actor->id, 'base_capital_cents' => $capital,
                'accounts' => $accounts, 'first_count_pending' => $settings['first_count_pending'], 'settlement_source' => $settings['source'], 'settlement_destination_key' => $current->latestConfiguration?->settlement_destination_key, 'shifts' => $v['shifts'], 'reason' => $v['reason'], 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            $before = $current->only(['name', 'is_active', 'base_capital_cents']);
            $current->update(['base_capital_cents' => $capital, 'revision' => $current->revision + 1]);
            DB::table('cash_box_accounts')->where('cash_box_id', $box->id)->update(['is_active' => false]);
            foreach (array_keys($settings['all']) as $position => $code) {
                $account = $settings['all'][$code];
                DB::table('cash_box_accounts')->updateOrInsert(['cash_box_id' => $box->id, 'code' => $code], ['name' => $account['name'], 'category' => $account['category'], 'reference' => $account['reference'], 'is_active' => $account['is_active'], 'position' => $position]);
            }
            CashBoxAudit::create(['cash_box_id' => $box->id, 'actor_user_id' => $actor->id, 'request_key' => $v['request_key'], 'action' => 'configured',
                'payload_hash' => $hash, 'before' => $before, 'after' => $current->only(['name', 'is_active', 'base_capital_cents']) + ['configuration_id' => $version->id],
                'reason' => $v['reason'], 'created_at' => now()]);

            return $version;
        });
        app(CashBoxTypeCatalog::class)->refresh();

        return $result;
    }
}
