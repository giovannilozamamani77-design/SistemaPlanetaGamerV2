<?php

namespace App\Domain\Reportes\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MonthlyReportAdjustment extends Model
{
    protected $table = 'report_monthly_adjustments';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'changes' => 'array', 'sequence' => 'integer', 'sales_delta_cents' => 'integer', 'collections_delta_cents' => 'integer', 'pending_count' => 'integer', 'accepted_pending' => 'boolean', 'confirmed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El ajuste confirmado no se modifica.'));
        static::deleting(fn () => throw new LogicException('El ajuste confirmado no se elimina.'));
    }
}
