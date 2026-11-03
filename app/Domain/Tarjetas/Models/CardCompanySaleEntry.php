<?php

namespace App\Domain\Tarjetas\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** Read-only receipt/company projection; totals use the original frozen lines. */
class CardCompanySaleEntry extends CardSaleRegisterEntry
{
    public function lines(): HasMany
    {
        return $this->hasMany(CardSaleLine::class, 'card_sale_id', 'line_sale_id');
    }

    public function companyLines(): Collection
    {
        return $this->lines->filter(fn ($line) => ($line->product_snapshot['company'] ?? 'SIN_EMPRESA') === $this->company)->values();
    }
}
