<?php

namespace App\Domain\Cajas\Models;

use App\Domain\Cajas\Queries\CashBoxTypeCatalog;
use Database\Factories\Cajas\CashBoxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashBox extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_capital_cents' => 'integer', 'is_active' => 'boolean', 'revision' => 'integer'];
    }

    protected static function newFactory(): CashBoxFactory
    {
        return CashBoxFactory::new();
    }

    public function cashBoxType(): BelongsTo
    {
        return $this->belongsTo(CashBoxType::class, 'type', 'code');
    }

    public function family(): string
    {
        return app(CashBoxTypeCatalog::class)->family($this->type);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashBoxSession::class);
    }

    public function configurations(): HasMany
    {
        return $this->hasMany(CashBoxConfiguration::class);
    }

    public function latestConfiguration(): HasOne
    {
        return $this->hasOne(CashBoxConfiguration::class)->latestOfMany();
    }

    public function audits(): HasMany
    {
        return $this->hasMany(CashBoxAudit::class);
    }
}
