<?php

namespace App\Domain\ControlHorario\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringWorkShift extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('recurring_work_shifts as newer')
            ->whereColumn('newer.user_id', 'recurring_work_shifts.user_id')->whereColumn('newer.period', 'recurring_work_shifts.period')
            ->whereColumn('newer.revision', '>', 'recurring_work_shifts.revision'));
    }

    public function scopeEffectiveAt(Builder $query, CarbonInterface $instant): Builder
    {
        return $query->where('recurring_work_shifts.created_at', '<=', $instant)
            ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('recurring_work_shifts as newer')
                ->whereColumn('newer.user_id', 'recurring_work_shifts.user_id')->whereColumn('newer.period', 'recurring_work_shifts.period')
                ->where('newer.created_at', '<=', $instant)->whereColumn('newer.revision', '>', 'recurring_work_shifts.revision'));
    }
}
