<?php

namespace App\Domain\Cajas\Data;

use App\Domain\Cajas\Queries\CashBoxTypeCatalog;

class CashBoxComponents
{
    /** @return array<string,string> */
    public static function forType(string $type): array
    {
        $items = app(CashBoxTypeCatalog::class)->components($type);

        return array_column(array_values(array_filter($items, fn (array $item): bool => $item['required'])), 'name', 'code');
    }

    public static function handoverReference(array $accounts, array $handover): array
    {
        $reference = array_map(fn (array $account): int => $account['initial_cents'], $accounts);
        $reference = array_replace($reference, array_intersect_key($handover, $reference));
        ksort($reference);

        return $reference;
    }

    public static function defaultShifts(): array
    {
        return [
            'manana' => ['start' => '08:00', 'end' => '14:00', 'liquidates' => false],
            'tarde' => ['start' => '14:00', 'end' => '20:00', 'liquidates' => true],
        ];
    }
}
