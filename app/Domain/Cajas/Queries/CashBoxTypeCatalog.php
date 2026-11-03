<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Models\CashBoxType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CashBoxTypeCatalog
{
    private ?array $items = null;

    private array $accounts = [];

    public function items(): array
    {
        return $this->items ??= CashBoxType::orderBy('id')->get()->keyBy('code')->all();
    }

    public function refresh(): void
    {
        $this->items = null;
        $this->accounts = [];
    }

    public function options(bool $activeOnly = false): array
    {
        $result = [];
        foreach ($this->items() as $code => $type) {
            if (! $activeOnly || $type->is_active) {
                $result[$code] = $type->name;
            }
        }

        return $result;
    }

    public function accounts(int $boxId): Collection
    {
        return $this->accounts[$boxId] ??= DB::table('cash_box_accounts')->where('cash_box_id', $boxId)->orderBy('position')->orderBy('id')->get();
    }

    public function family(string $code): string
    {
        return $this->items()[$code]->family ?? $code;
    }

    public function components(string $code): array
    {
        return $this->items()[$code]->components ?? [];
    }
}
