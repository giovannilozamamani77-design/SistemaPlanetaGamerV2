<?php

namespace App\Domain\Cajas\Contracts;

interface ReservedCashBoxFunds
{
    /** @return array<string, int> Amount physically held per component, excluded from operational capital. */
    public function forBox(int $boxId): array;
}
