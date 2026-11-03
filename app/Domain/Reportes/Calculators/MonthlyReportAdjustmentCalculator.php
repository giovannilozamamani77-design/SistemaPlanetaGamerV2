<?php

namespace App\Domain\Reportes\Calculators;

use LogicException;

class MonthlyReportAdjustmentCalculator
{
    public function calculate(array $original, array $before, array $current): array
    {
        $after = $current;
        $after['report']['comparison'] = $original['report']['comparison'];
        foreach (['sales', 'collections'] as $metric) {
            if ($original['report']['comparison']['available']) {
                $after['report']['comparison'][$metric] = app(DailyComparisonCalculator::class)->compare($current['report'][$metric.'_cents'], $original['report']['comparison'][$metric]['previous_cents']);
            }
        }
        $changes = [];
        foreach (['records', 'pending'] as $section) {
            $previous = array_column($before[$section], null, 'key');
            $next = array_column($after[$section], null, 'key');
            $keys = array_values(array_unique([...array_keys($previous), ...array_keys($next)]));
            sort($keys);
            foreach ($keys as $key) {
                $old = $previous[$key] ?? null;
                $new = $next[$key] ?? null;
                if ($this->comparable($old) === $this->comparable($new)) {
                    continue;
                }
                $changes[] = ['section' => $section, 'key' => $key, 'kind' => $old === null ? 'ADDED' : ($new === null ? 'REMOVED' : 'CHANGED'), 'before' => $old, 'after' => $new,
                    'sales_delta_cents' => $section === 'records' ? ($new['sales_cents'] ?? 0) - ($old['sales_cents'] ?? 0) : 0,
                    'collections_delta_cents' => $section === 'records' ? ($new['collections_cents'] ?? 0) - ($old['collections_cents'] ?? 0) : 0];
            }
        }
        $sales = array_sum(array_column($changes, 'sales_delta_cents'));
        $collections = array_sum(array_column($changes, 'collections_delta_cents'));
        if ($current['report']['sales_cents'] - $before['report']['sales_cents'] !== $sales || $current['report']['collections_cents'] - $before['report']['collections_cents'] !== $collections) {
            throw new LogicException('Las diferencias no coinciden con las contribuciones registradas.');
        }

        return ['snapshot' => $after, 'snapshot_hash' => $this->hash($after), 'changes' => $changes, 'sales_delta_cents' => $sales, 'collections_delta_cents' => $collections];
    }

    public function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function comparable(?array $row): ?array
    {
        if ($row !== null) {
            unset($row['actor_name']);
        }

        return $row;
    }
}
