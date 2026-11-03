<?php

namespace App\Domain\Tarjetas\Models;

use App\Support\Bolivianos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CardProduct extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['face_value_cents' => 'integer', 'purchase_price_cents' => 'integer', 'normal_price_cents' => 'integer', 'wholesale_price_cents' => 'integer', 'revision' => 'integer', 'is_active' => 'boolean', 'wholesale_enabled' => 'boolean'];
    }

    public function stock(): HasOne
    {
        return $this->hasOne(CardStock::class);
    }

    public function isCabina(): bool
    {
        return $this->company === 'VIVA' && in_array($this->face_value_cents, [4000, 10000], true);
    }

    public function cutLabel(): string
    {
        return ($this->isCabina() ? 'Cabina Viva de Bs ' : 'Bs ').Bolivianos::formatCompact($this->face_value_cents);
    }

    public function label(): string
    {
        return $this->isCabina() ? $this->cutLabel() : $this->company.' · '.$this->cutLabel();
    }

    public function snapshot(): array
    {
        return $this->only(['id', 'company', 'face_value_cents', 'purchase_price_cents', 'normal_price_cents', 'wholesale_price_cents', 'wholesale_enabled', 'revision']);
    }
}
