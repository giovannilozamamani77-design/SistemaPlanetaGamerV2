<?php

namespace App\Domain\Cajas\Models;

use App\Domain\Liquidaciones\Models\LiquidationDestination;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CashBoxClose extends Model
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

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(LiquidationDestination::class, 'commission_account_id');
    }

    public function assertNotCancelled(): void
    {
        if ($this->cancelled_at) {
            throw ValidationException::withMessages(['reason' => 'Este cierre fue anulado. Solo se conserva para consulta del historial.']);
        }
    }

    public function countedTotal(): int
    {
        return (int) array_sum($this->counted_balances ?? []);
    }

    public function realTotal(): int
    {
        return (int) array_sum($this->real_balances ?? []);
    }

    public function operatingTotal(): int
    {
        return $this->realTotal() - (int) array_sum($this->reserved_funds_snapshot ?? []);
    }

    public function liquidationLabel(): string
    {
        if ($this->cancelled_at) {
            return 'Anulado';
        }

        return ! $this->requires_liquidation ? 'No requerida' : ($this->liquidation_completed ? 'Liquidado' : 'Pendiente');
    }

    protected function casts(): array
    {
        return ['cancelled_at' => 'immutable_datetime', 'count_details' => 'array', 'reserved_funds_snapshot' => 'array', 'workflow_version' => 'integer', 'provisional_surplus_cents' => 'integer', 'unavailable_commission_cents' => 'integer', 'temporary_funds_snapshot' => 'array', 'closure_approved_at' => 'immutable_datetime', 'surplus_recognized_at' => 'immutable_datetime', 'shift_snapshot' => 'array', 'counted_balances' => 'array', 'real_balances' => 'array', 'expected_balances' => 'array', 'comparison' => 'array', 'requires_liquidation' => 'boolean', 'liquidation_completed' => 'boolean', 'created_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }
}
