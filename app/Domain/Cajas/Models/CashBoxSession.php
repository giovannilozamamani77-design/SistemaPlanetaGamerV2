<?php

namespace App\Domain\Cajas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBoxSession extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function box(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(CashBoxConfiguration::class, 'configuration_id');
    }

    protected function casts(): array
    {
        return ['operating_capital_cents' => 'integer', 'current_balances' => 'array', 'revision' => 'integer', 'opening_balances' => 'array', 'expected_balances' => 'array', 'comparison' => 'array', 'handover_balances' => 'array',
            'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime',
            'base_capital_cents' => 'integer'];
    }
}
