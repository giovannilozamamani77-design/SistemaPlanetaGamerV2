<?php

namespace App\Domain\Reportes\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReportExport extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['generated_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La auditoría de exportación no se reescribe.'));
        static::deleting(fn () => throw new LogicException('La auditoría de exportación se conserva.'));
    }
}
