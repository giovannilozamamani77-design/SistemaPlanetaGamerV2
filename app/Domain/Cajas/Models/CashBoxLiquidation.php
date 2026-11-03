<?php

namespace App\Domain\Cajas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBoxLiquidation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function box(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function close(): BelongsTo
    {
        return $this->belongsTo(CashBoxClose::class, 'cash_box_close_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
