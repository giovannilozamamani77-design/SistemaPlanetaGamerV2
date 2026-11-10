<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessoryProduct extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['image_path'];

    protected function casts(): array
    {
        return ['cost_pending' => 'boolean', 'category_id' => 'integer', 'reference_cost_cents' => 'integer', 'minimum_stock' => 'integer', 'quantity' => 'integer', 'stock_value_cents' => 'integer', 'sale_price_cents' => 'integer', 'revision' => 'integer', 'created_by' => 'integer'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AccessoryStockEntry::class, 'product_id');
    }
}
