<?php

namespace App\Domain\Accesorios\Queries;

use App\Domain\Accesorios\Calculators\AccessorySaleCalculator;
use App\Domain\Accesorios\Calculators\SaleDiscountCalculator;
use App\Domain\Accesorios\Data\AccessorySaleInput;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialRules;
use App\Domain\Comisiones\Calculators\CommissionCalculator;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessorySaleQuery
{
    public function authorize(User $actor, string $ability = 'create'): User
    {
        $u = User::findOrFail($actor->id);
        Gate::forUser($u)->authorize($ability, AccessorySale::class);

        return $u;
    }

    public function products(User $u, string $search = ''): Builder
    {
        $this->authorize($u);
        $q = AccessoryProduct::where('quantity', '>', 0);
        $normalized = Str::lower(Str::ascii(mb_substr(trim($search), 0, 80)));
        $tokens = array_slice(preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY), 0, 8);
        foreach ($tokens as $token) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $token).'%';
            $q->where(function (Builder $match) use ($term): void {
                foreach (['name', 'code', 'category', 'description'] as $column) {
                    $expression = "COALESCE($column, '')";
                    foreach (['á' => 'a', 'Á' => 'a', 'é' => 'e', 'É' => 'e', 'í' => 'i', 'Í' => 'i', 'ó' => 'o', 'Ó' => 'o', 'ú' => 'u', 'Ú' => 'u', 'ü' => 'u', 'Ü' => 'u', 'ñ' => 'n', 'Ñ' => 'n'] as $from => $to) {
                        $expression = "REPLACE($expression, '$from', '$to')";
                    }
                    $match->orWhereRaw("LOWER($expression) LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }

        return $q->orderBy('name')->orderBy('id');
    }

    public function catalogue(User $u, string $search = ''): LengthAwarePaginator
    {
        return $this->products($u, $search)->paginate(12, ['*'], 'productsPage')->through(fn (AccessoryProduct $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'category' => $p->category, 'stock' => $p->quantity, 'price_cents' => $p->sale_price_cents, 'image_url' => $p->image_path ? route('accesorios.producto-imagen', ['product' => $p->id]) : null, 'sale_allowed' => ! app(AccessoryInventoryQuery::class)->belowCost($p)]);
    }

    public function add(User $u, array $basket, int $productId, mixed $quantity): array
    {
        $this->authorize($u);
        $v = Validator::make(['quantity' => $quantity], ['quantity' => ['required', 'integer', 'min:1', 'max:100000']])->validate();
        $p = AccessoryProduct::find($productId);
        if (! $p) {
            throw ValidationException::withMessages(['cart' => 'El accesorio no está disponible.']);
        }
        $found = false;
        foreach ($basket as &$line) {
            if ($line['product_id'] === $productId) {
                $line['quantity'] += (int) $v['quantity'];
                $found = true;
                break;
            }
        }
        unset($line);
        if (! $found) {
            $basket[] = ['product_id' => $p->id, 'quantity' => (int) $v['quantity'], 'expected_revision' => $p->revision, 'expected_price_cents' => $p->sale_price_cents];
        }
        $this->preview($u, ['lines' => $basket]);

        return AccessorySaleInput::basket(['lines' => $basket]);
    }

    public function decrease(User $u, array $basket, int $productId): array
    {
        $this->authorize($u);
        $basket = AccessorySaleInput::basket(['lines' => $basket]);
        foreach ($basket as &$line) {
            if ($line['product_id'] === $productId) {
                $line['quantity']--;
            }
        }
        unset($line);

        return array_values(array_filter($basket, fn ($line) => $line['quantity'] > 0));
    }

    public function byCode(User $u, string $code): int
    {
        $this->authorize($u);
        $id = AccessoryProduct::where('code', strtoupper(trim($code)))->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['code' => 'No se encontró ese código. Busca por nombre o categoría.']);
        }

        return $id;
    }

    public function preview(User $u, array $data): array
    {
        $this->authorize($u);
        $basket = AccessorySaleInput::basket($data);
        $discounts = CommercialRules::current('DISCOUNTS');
        $commissions = CommercialRules::current('COMMISSIONS');
        $products = AccessoryProduct::whereIn('id', array_column($basket, 'product_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($basket as $line) {
            $p = $products->get($line['product_id']);
            if (! $p) {
                throw ValidationException::withMessages(['cart' => 'Un accesorio ya no está disponible.']);
            }
            $lines[] = app(AccessorySaleCalculator::class)->line($p, $line, $discounts['rules']);
        }
        $lines = array_merge($lines, app(PhoneSaleQuery::class)->preview($u, $data, $discounts['rules']));
        $totals = app(AccessorySaleCalculator::class)->totals($lines);

        foreach ($lines as &$line) {
            $line['commission_cents'] = app(CommissionCalculator::class)->calculate($line, $commissions['rules']);
        }
        unset($line);

        return ['rules_revisions' => ['DISCOUNTS' => $discounts['revision'], 'COMMISSIONS' => $commissions['revision']], 'commission_cents' => array_sum(array_column($lines, 'commission_cents')), 'commission_pending' => in_array(null, array_column($lines, 'cost_cents'), true)] + app(SaleDiscountCalculator::class)->summary($lines) + ['quantity' => $totals['quantity'], 'total_cents' => $totals['total_cents'], 'lines' => array_map(fn ($line) => app(SaleDiscountCalculator::class)->amounts($line) + array_intersect_key($line, array_flip(['commission_cents', 'product_id', 'product_code', 'product_name', 'product_description', 'quantity', 'unit_price_cents', 'total_cents', 'kind', 'phone_unit_id', 'phone_snapshot', 'warranty'])), $lines)];
    }

    public function cash(User $u): int
    {
        $this->authorize($u);

        return CommercialFund::where('code', 'GENERAL')->value('balance_cents') ?? 0;
    }

    public function history(User $u): Builder
    {
        $this->authorize($u, 'viewAny');

        return AccessorySale::with('lines.warranty')->orderByDesc('id');
    }

    public function detail(User $u, AccessorySale $sale): array
    {
        $user = $this->authorize($u, 'viewAny');
        $sale = $sale->fresh(['lines.warranty']);
        $costs = Gate::forUser($user)->allows('manage', AccessoryProduct::class);
        $out = $sale->only(['id', 'actor_name', 'quantity', 'total_cents', 'payment_method', 'pricing_revision']);
        $out['date'] = $sale->created_at->timezone('America/La_Paz')->format('d/m/Y H:i');
        $out['customer'] = $sale->customer_snapshot;
        $out['receipt_required'] = $sale->receipt_required;
        $out['lines'] = [];
        $resolved = $costs ? DB::table('accessory_cost_resolutions')
            ->whereIn('product_id', $sale->lines->whereNull('cost_cents')->pluck('product_id'))
            ->pluck('unit_cost_cents', 'product_id') : collect();
        foreach ($sale->lines as $line) {
            $row = $line->only(['product_code', 'product_name', 'product_description', 'quantity', 'unit_price_cents', 'total_cents']);
            $row += app(SaleDiscountCalculator::class)->amounts($line->toArray());
            $row['kind'] = $line->kind;
            $row['phone'] = $line->phone_snapshot;
            $row['warranty'] = $this->warranty($line);
            $row['image_url'] = $line->product_image_path ? route('accesorios.venta-imagen', ['line' => $line->id]) : null;
            if ($costs) {
                $row['cost_cents'] = $line->cost_cents ?? (isset($resolved[$line->product_id]) ? (int) $resolved[$line->product_id] * $line->quantity : null);
            }$out['lines'][] = $row;
        }
        if ($costs) {
            $lineCosts = array_column($out['lines'], 'cost_cents');
            $out['cost_cents'] = in_array(null, $lineCosts, true) ? null : array_sum($lineCosts);
            $out['profit_cents'] = $out['cost_cents'] === null ? null : $sale->total_cents - $out['cost_cents'];
        }

        return $out + app(SaleDiscountCalculator::class)->summary($out['lines']);
    }

    private function warranty(AccessorySaleLine $line): ?array
    {
        $w = $line->warranty;

        return $w ? ['starts_on' => $w->starts_on->toDateString(), 'expires_on' => $w->expires_on->toDateString(), 'months' => $w->months, 'terms_version' => $w->terms_version, 'terms' => $w->terms] : null;
    }

    public function receipt(User $u, int $saleId): array
    {
        $this->authorize($u, 'viewAny');
        $sale = AccessorySale::with('lines.warranty')->findOrFail($saleId);
        $out = $sale->only(['id', 'actor_name', 'quantity', 'total_cents', 'payment_method']);
        $out['date'] = $sale->created_at->timezone('America/La_Paz')->format('d/m/Y H:i');
        $out['customer'] = $sale->customer_snapshot;
        $out['receipt_required'] = $sale->receipt_required;

        $out['lines'] = $sale->lines->map(fn ($line) => $line->only(['product_code', 'product_name', 'product_description', 'quantity', 'unit_price_cents', 'total_cents']) + app(SaleDiscountCalculator::class)->amounts($line->toArray()) + ['kind' => $line->kind, 'phone' => $line->phone_snapshot, 'warranty' => $this->warranty($line)])->all();

        return $out + app(SaleDiscountCalculator::class)->summary($out['lines']);
    }
}
