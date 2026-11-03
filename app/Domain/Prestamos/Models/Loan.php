<?php

namespace App\Domain\Prestamos\Models;

use App\Domain\Prestamos\Calculators\LoanCalculator;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Loan extends Model
{
    public $timestamps = false;

    protected $table = 'loans';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['terms_snapshot' => 'array', 'borrower_snapshot' => 'array', 'object_snapshot' => 'array', 'account_snapshot' => 'array', 'cashier_snapshot' => 'array', 'capital_cents' => 'integer', 'original_rate_bps' => 'integer', 'original_days' => 'integer', 'original_interest_cents' => 'integer', 'original_total_cents' => 'integer', 'start_date' => 'immutable_date', 'original_due_date' => 'immutable_date', 'due_date' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    public function latestTermChange(): HasOne
    {
        return $this->hasOne(LoanTermChange::class)->latestOfMany();
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(LoanSettlement::class);
    }

    public function annulment(): HasOne
    {
        return $this->hasOne(LoanAnnulment::class);
    }

    public function objectExpenses(): HasMany
    {
        return $this->hasMany(LoanObjectExpense::class);
    }

    public function sale(): HasOne
    {
        return $this->hasOne(LoanObjectSale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayStatus(): string
    {
        return app(LoanCalculator::class)->status($this->due_date->format('Y-m-d'), $this->status);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El registro confirmado no se reescribe.'));
        static::deleting(fn () => throw new \LogicException('El historial no se elimina.'));
    }
}
