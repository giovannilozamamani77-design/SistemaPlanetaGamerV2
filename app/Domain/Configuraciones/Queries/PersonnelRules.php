<?php

namespace App\Domain\Configuraciones\Queries;

use App\Domain\Configuraciones\Models\PersonnelRuleVersion;
use Carbon\CarbonInterface;

class PersonnelRules
{
    private array $cache = [];

    /** @return array<string,int|bool|null> */
    public function current(): array
    {
        return $this->at(now(), 'current');
    }

    /** @return array<string,int|bool|null> */
    public function at(CarbonInterface $instant, ?string $key = null): array
    {
        $key ??= (string) $instant->getTimestamp();
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $version = PersonnelRuleVersion::where('effective_from', '<=', $instant)->orderByDesc('id')->first();

        return $this->cache[$key] = array_merge(['version_id' => 0, 'tolerance_minutes' => 15, 'grace_minutes' => 0, 'delay_multiplier' => 2, 'multiplier_minutes' => 30, 'automatic_credit' => true, 'high_difference_cents' => 10000, 'salary_rate_cents' => 5000, 'equivalent_minutes' => 360], $version?->values ?? [], ['grace_minutes' => 0, 'version_id' => $version?->id ?? 0]);
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
