<?php

namespace App\Domain\Liquidaciones\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable version; writes are owned by SetCashBoxCommissionAccountAction. */
class LiquidationDestination extends Model
{
    protected $table = 'cash_box_commission_accounts';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean'];
    }
}
