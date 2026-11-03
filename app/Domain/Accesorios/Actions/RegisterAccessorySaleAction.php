<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Calculators\AccessorySaleCalculator;
use App\Domain\Accesorios\Calculators\PhoneSaleCalculator;
use App\Domain\Accesorios\Calculators\PhoneWarrantyCalculator;
use App\Domain\Accesorios\Data\AccessorySaleInput;
use App\Domain\Accesorios\Data\PhoneSaleInput;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Models\CommercialRules;
use App\Domain\Accesorios\Models\PhoneImei;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PhoneWarranty;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Domain\Comisiones\Calculators\CommissionCalculator;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Notificaciones\Actions\RecordAccessoryTelegramNoticeAction;
use App\Domain\Prestamos\Models\Borrower;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterAccessorySaleAction
{
    public function execute(User $actor, array $data): AccessorySale
    {
        app(AccessorySaleQuery::class)->authorize($actor);
        $payment = AccessorySaleInput::payment($data);
        $basket = AccessorySaleInput::basket($data);
        $phones = PhoneSaleInput::basket($data);
        $customer = $phones ? PhoneSaleInput::customer($data) : null;
        $payload = ['payment_method' => 'CASH', 'lines' => $basket];
        if ($phones) {
            app(AccessorySaleQuery::class)->authorize($actor, 'viewAny');
            $payload += ['phone_lines' => $phones, 'customer' => $customer];
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $payment, $basket, $phones, $customer, $hash): AccessorySale {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessorySaleQuery::class)->authorize($actor);
            if ($phones) {
                app(AccessorySaleQuery::class)->authorize($u, 'viewAny');
            }
            if ($old = AccessorySale::where('request_key', $payment['request_key'])->first()) {
                if ($old->user_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['cart' => 'Este envío ya se registró con otros datos. Revisa el historial.']);
                }

                return $old;
            }
            $settings = [];
            foreach (['COMMISSIONS', 'DISCOUNTS'] as $scope) {
                CommercialRules::firstOrCreate(['scope' => $scope], ['rules' => CommercialRules::defaults($scope), 'revision' => 0]);
                $settings[$scope] = CommercialRules::where('scope', $scope)->lockForUpdate()->firstOrFail();
                if (array_key_exists($scope, $payment['rules_revisions'] ?? []) && (int) $payment['rules_revisions'][$scope] !== $settings[$scope]->revision) {
                    throw ValidationException::withMessages(['cart' => 'Los descuentos o comisiones cambiaron. Vuelve al carrito y revisa la venta nuevamente.']);
                }
            }
            CommercialFund::firstOrCreate(['code' => 'GENERAL'], ['balance_cents' => 0, 'revision' => 0]);
            $fund = CommercialFund::where('code', 'GENERAL')->lockForUpdate()->firstOrFail();
            $products = AccessoryProduct::whereIn('id', array_column($basket, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            foreach ($basket as $line) {
                $p = $products->get($line['product_id']);
                if (! $p) {
                    throw ValidationException::withMessages(['cart' => 'Un accesorio ya no está disponible.']);
                }
                $lines[] = app(AccessorySaleCalculator::class)->line($p, $line, $settings['DISCOUNTS']->rules);
            }
            $units = $phones ? PhoneUnit::with(['variant.model.brand', 'imeis'])->whereIn('id', array_column($phones, 'unit_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
            $reservations = $phones ? PhoneReservation::whereIn('id', array_column($phones, 'reservation_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
            $at = CarbonImmutable::now();
            foreach ($phones as $input) {
                $p = $units->get($input['unit_id']);
                if (! $p) {
                    throw ValidationException::withMessages(['cart' => 'El teléfono ya no está disponible.']);
                }
                $lines[] = app(PhoneSaleCalculator::class)->line($p, $reservations->get($input['reservation_id']), $input, $u->id, $payment['request_key'], $at, true, $settings['DISCOUNTS']->rules);
                foreach (PhoneSaleInput::imeis($p, $input, true) as $slot => $imei) {
                    PhoneImei::firstOrCreate(['unit_id' => $p->id, 'slot' => $slot], ['imei' => $imei]);
                }
            }
            $totals = app(AccessorySaleCalculator::class)->totals($lines);
            if ($fund->balance_cents + $totals['total_cents'] > PricingInput::MAX_CENTS) {
                throw ValidationException::withMessages(['cart' => 'El efectivo acumulado supera el límite permitido. Contacta con Administración.']);
            }
            $client = $customer ? Borrower::firstOrCreate(['ci' => $customer['ci']], ['name' => $customer['name'], 'phone' => $customer['phone']]) : null;
            $sale = AccessorySale::create($totals + ['customer_id' => $client?->id, 'customer_snapshot' => $customer, 'receipt_required' => (bool) $phones] + ['user_id' => $u->id, 'actor_name' => $u->name, 'payment_method' => 'CASH', 'pricing_revision' => PricingSettings::where('code', 'GENERAL')->value('revision') ?? 0, 'request_key' => $payment['request_key'], 'payload_hash' => $hash, 'created_at' => $at]);
            foreach ($lines as $line) {
                $savedLine = AccessorySaleLine::create($line + ['sale_id' => $sale->id]);
                CommissionEntry::create([
                    'sale_line_id' => $savedLine->id, 'user_id' => $u->id, 'actor_name' => $u->name,
                    'amount_cents' => app(CommissionCalculator::class)->calculate($line, $settings['COMMISSIONS']->rules),
                    'snapshot' => ['discount_rules' => $settings['DISCOUNTS']->rules, 'discount_revision' => $settings['DISCOUNTS']->revision, 'commission_rules' => $settings['COMMISSIONS']->rules, 'commission_revision' => $settings['COMMISSIONS']->revision, 'enabled' => $settings['COMMISSIONS']->rules['enabled']],
                    'created_at' => $at,
                ]);
                if (($line['kind'] ?? 'ACCESSORY') === 'PHONE') {
                    $p = $units[$line['phone_unit_id']];
                    PhoneWarranty::create(app(PhoneWarrantyCalculator::class)->calculate($p, $at) + ['sale_line_id' => $savedLine->id, 'unit_id' => $p->id, 'created_at' => $at]);
                    $reservations[$p->reservation_id]->update(['status' => 'CONSUMED', 'ended_at' => $at]);
                    $p->update(['status' => 'SOLD', 'reservation_id' => null]);

                    continue;
                }
                $products[$line['product_id']]->update(['quantity' => $line['after_quantity'], 'stock_value_cents' => $line['after_value_cents'], 'revision' => $line['previous_revision'] + 1]);
            }
            CommercialMovement::create(['fund_id' => $fund->id, 'sale_id' => $sale->id, 'user_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'SALE_CASH', 'amount_cents' => $totals['total_cents'], 'before_cents' => $fund->balance_cents, 'after_cents' => $fund->balance_cents + $totals['total_cents'], 'previous_revision' => $fund->revision, 'created_at' => $at]);
            $fund->update(['balance_cents' => $fund->balance_cents + $totals['total_cents'], 'revision' => $fund->revision + 1]);

            if ($phones) {
                app(RecordAccessoryTelegramNoticeAction::class)->sale($sale->id);
            }

            return $sale;
        }, 3);
    }
}
