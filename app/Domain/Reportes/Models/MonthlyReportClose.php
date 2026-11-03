<?php

namespace App\Domain\Reportes\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MonthlyReportClose extends Model
{
    protected $table = 'report_monthly_closes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'confirmed_at' => 'immutable_datetime', 'accepted_pending' => 'boolean', 'source_count' => 'integer', 'pending_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El cierre mensual original no se modifica.'));
        static::deleting(fn () => throw new LogicException('El cierre mensual original no se elimina.'));
    }
}
