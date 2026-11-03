<?php

namespace App\Domain\Tarjetas\Calculators;

class CardStoreCountSummary
{
    public function make(int $expectedCash, int $countedCash, int $reservedCash, int $availableCash, int $shortage, array $lines): array
    {
        $companies = [];
        foreach ($lines as $line) {
            $company = $line['company'];
            if (! isset($companies[$company])) {
                $companies[$company] = ['company' => $company, 'expected' => 0, 'counted' => 0, 'missing' => 0, 'surplus' => 0, 'reserved' => 0, 'available' => 0, 'lines' => []];
            }
            $row = ['label' => $line['label'], 'expected' => $line['expected'], 'counted' => $line['counted'], 'missing' => max(0, $line['expected'] - $line['counted']), 'surplus' => max(0, $line['counted'] - $line['expected']), 'reserved' => $line['reserved_counted'], 'available' => $line['available_counted']];
            foreach (['expected', 'counted', 'missing', 'surplus', 'reserved', 'available'] as $key) {
                $companies[$company][$key] += $row[$key];
            }$companies[$company]['lines'][] = $row;
        }

        $stock = [];
        foreach (['expected', 'counted', 'missing', 'surplus', 'reserved', 'available'] as $key) {
            $stock[$key] = array_sum(array_column($companies, $key));
        }

        return ['cash' => ['expected' => $expectedCash, 'counted' => $countedCash, 'missing' => max(0, $expectedCash - $countedCash), 'surplus' => max(0, $countedCash - $expectedCash), 'reserved' => $reservedCash, 'available' => $availableCash], 'shortage_cents' => $shortage, 'stock' => $stock, 'companies' => array_values($companies)];
    }

    public function historyGroups(array $summary): array
    {
        $companies = [];
        $emptyCompanies = [];
        foreach ($summary['companies'] as $group) {
            $visible = [];
            $empty = [];
            foreach ($group['lines'] as $line) {
                if ($line['expected'] > 0 || $line['counted'] > 0 || $line['reserved'] > 0) {
                    $visible[] = $line;
                } else {
                    $empty[] = $line;
                }
            }
            if ($visible) {
                $companies[] = array_replace($group, ['lines' => $visible]);
            }
            if ($empty) {
                $emptyCompanies[] = ['company' => $group['company'], 'lines' => $empty];
            }
        }

        return ['companies' => $companies, 'empty_companies' => $emptyCompanies];
    }
}
