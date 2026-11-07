<?php

namespace App\Domain\Prestamos\Models;

use Illuminate\Database\Eloquent\Model;

class HistoricalLoanImport extends Model
{
    public $timestamps = false;

    protected $table = 'loan_historical_imports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime', 'fund_delta_cents' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('La importación confirmada conserva su auditoría.'));
        static::deleting(fn () => throw new \LogicException('La importación confirmada no se elimina.'));
    }
}
