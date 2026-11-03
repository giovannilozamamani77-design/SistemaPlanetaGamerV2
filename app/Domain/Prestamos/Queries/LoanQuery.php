<?php

namespace App\Domain\Prestamos\Queries;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Calculators\LoanLateFeeCalculator;
use App\Domain\Prestamos\Calculators\LoanRecoveryCalculator;
use App\Domain\Prestamos\Calculators\LoanRegistrationTerms;
use App\Domain\Prestamos\Calculators\LoanTermCalculator;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Data\LoanReceiptData;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Domain\Prestamos\Models\LoanTermChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LoanQuery
{
    public function loans(User $actor): Builder
    {
        app(LoanAuthorization::class)->authorize($actor, 'viewAny');

        return Loan::query()->with(['user', 'latestTermChange', 'settlement', 'annulment', 'sale'])->withSum('objectExpenses as recovery_expense_cents', 'amount_cents');
    }

    public function fundMovements(User $actor): Builder
    {
        app(LoanAuthorization::class)->authorize($actor, 'manageFund');

        return LoanMovement::with('user');
    }

    public function types(User $actor): Builder
    {
        app(LoanAuthorization::class)->authorize($actor, 'viewAny');

        return LoanObjectType::query();
    }

    public function clients(User $actor): Builder
    {
        $user = app(LoanAuthorization::class)->authorize($actor, 'viewAny');
        $query = Borrower::query();
        if (! Gate::forUser($user)->allows('create', Loan::class) && ! Gate::forUser($user)->allows('viewAll', Loan::class)) {
            $query->whereIn('id', $this->loans($user)->select('borrower_id')->reorder());
        }

        return $query;
    }

    public function borrower(User $actor, string $ci): ?array
    {
        app(LoanAuthorization::class)->authorize($actor, 'create');

        return Borrower::where('ci', LoanInput::normalize($ci))->first()?->only(['ci', 'name', 'phone']);
    }

    public function preview(User $actor, array $data): ?array
    {
        try {
            return $this->reviewRegistration($actor, $data, false);
        } catch (ValidationException) {
            return null;
        }
    }

    public function schedulePreview(User $actor, array $data): ?array
    {
        app(LoanAuthorization::class)->authorize($actor, 'create');
        try {
            return app(LoanRegistrationTerms::class)->schedule($data);
        } catch (ValidationException) {
            return null;
        }
    }

    public function reviewRegistration(User $actor, array $data, bool $checkRevision = true): array
    {
        app(LoanAuthorization::class)->authorize($actor, 'create');
        if (($data['schedule_mode'] ?? 'AUTO') === 'MANUAL' || ($data['interest_mode'] ?? 'AUTO') === 'MANUAL') {
            app(LoanAuthorization::class)->authorize($actor, 'manualTerms');
        }
        $type = LoanObjectType::whereKey($data['type_id'] ?? 0)->where('is_active', true)->first();
        if (! $type || ($checkRevision && $type->revision !== (int) ($data['type_revision'] ?? 0))) {
            throw ValidationException::withMessages(['type_id' => 'El tipo o su tarifa cambió. Selecciónalo de nuevo antes de confirmar.']);
        }
        $terms = ['type_name' => $type->name] + app(LoanRegistrationTerms::class)->calculate($data, $type->rate_bps);
        if ($checkRevision) {
            $fund = LoanFund::where('code', 'YAPE')->first();
            if (! $fund?->initialized_at || $fund->balance_cents < $terms['capital_cents']) {
                throw ValidationException::withMessages(['capital' => 'Capital insuficiente. Solicita a Administración que registre el aporte en Yape Préstamos.']);
            }
            $today = CarbonImmutable::now('America/La_Paz')->toDateString();
            if ($terms['start_date'] > $today || ($terms['start_date'] !== $today && ! $actor->fresh()->hasRole('administrador'))) {
                throw ValidationException::withMessages(['start_date' => 'La carga de una fecha anterior requiere Administración.']);
            }
        }

        return $terms;
    }

    public function availableCapital(User $actor): int
    {
        app(LoanAuthorization::class)->authorize($actor, 'create');
        app(LoanAuthorization::class)->authorize($actor, 'viewAny');

        return (int) (LoanFund::where('code', 'YAPE')->value('balance_cents') ?? 0);
    }

    public function capitalAvailability(User $actor, mixed $capital): array
    {
        $available = $this->availableCapital($actor);
        try {
            $requested = LoanInput::money($capital, 'capital');
        } catch (ValidationException) {
            $requested = null;
        }
        $shortfall = $requested === null ? 0 : max(0, $requested - $available);

        return ['available_cents' => $available, 'requested_cents' => $requested, 'shortfall_cents' => $shortfall, 'insufficient' => $shortfall > 0];
    }

    public function summary(User $actor): array
    {
        $u = app(LoanAuthorization::class)->authorize($actor, 'viewAny');
        $fund = LoanFund::where('code', 'YAPE')->first();
        $q = Loan::query();
        $today = CarbonImmutable::now('America/La_Paz')->toDateString();
        $cut = CarbonImmutable::now('America/La_Paz')->subDays(7)->toDateString();

        return ['total' => (clone $q)->count(), 'initialized' => (bool) $fund?->initialized_at, 'available_cents' => (int) ($fund?->balance_cents ?? 0), 'active' => (clone $q)->where('status', 'ACTIVO')->where('due_date', '>=', $today)->count(), 'late' => (clone $q)->where('status', 'ACTIVO')->where('due_date', '<', $today)->where('due_date', '>=', $cut)->count(), 'lost' => (clone $q)->where(fn ($lost) => $lost->where('status', 'PERDIDA_TOTAL')->orWhere(fn ($dated) => $dated->where('status', 'ACTIVO')->where('due_date', '<', $cut)))->count(), 'account' => $fund?->only(['bank', 'number', 'holder', 'qr_path', 'revision']) ?? []];
    }

    public function detail(User $actor, int $id): array
    {
        $loan = $this->loans($actor)->whereKey($id)->firstOrFail();

        return app(LoanReceiptData::class)->present(['collection_cents' => app(LoanTermCalculator::class)->amountToCollect($loan), 'late_fee_cents' => app(LoanLateFeeCalculator::class)->amount($loan), 'sale' => $this->visibleSale($actor, $loan->sale?->summary), 'expense_cents' => (int) $loan->recovery_expense_cents, 'expenses' => $loan->objectExpenses()->orderBy('id')->get()->map(fn ($event) => $event->summary)->all(), 'annulment' => $loan->annulment?->summary, 'settlement' => $loan->settlement?->summary, 'status' => $loan->displayStatus(), 'current' => app(LoanTermCalculator::class)->current($loan), 'terms_snapshot' => $loan->terms_snapshot ?? [], 'id' => $loan->id, 'borrower' => $loan->borrower_snapshot, 'object' => $loan->object_snapshot, 'capital_cents' => $loan->capital_cents, 'rate_bps' => $loan->original_rate_bps, 'days' => $loan->original_days, 'interest_cents' => $loan->original_interest_cents, 'total_cents' => $loan->original_total_cents, 'start_date' => $loan->start_date->format('Y-m-d'), 'due_date' => $loan->original_due_date->format('Y-m-d'), 'registered_by' => $loan->cashier_snapshot['name'], 'created_at' => $loan->created_at->timezone('America/La_Paz')->format('d/m/Y H:i')]);
    }

    private function visibleSale(User $actor, ?array $sale): ?array
    {
        if ($sale === null || app(LoanAuthorization::class)->authorize($actor, 'viewAny')->hasRole('administrador')) {
            return $sale;
        }

        return array_diff_key($sale, array_flip(['loan_profit_cents', 'sale_profit_cents', 'total_profit_cents']));
    }

    public function recoveryCash(User $actor): int
    {
        app(LoanAuthorization::class)->authorize($actor, 'manageLost');

        return (int) (LoanFund::where('code', 'YAPE')->value('balance_cents') ?? 0);
    }

    public function recoveryPreview(User $actor, int $loanId, mixed $price = null): array
    {
        app(LoanAuthorization::class)->authorize($actor, 'sell');
        $loan = $this->loans($actor)->findOrFail($loanId);
        try {
            $cents = filled($price) ? LoanInput::money($price, 'price') : null;

            return $this->visibleSale($actor, app(LoanRecoveryCalculator::class)->quote($loan, (int) $loan->recovery_expense_cents, $cents));
        } catch (ValidationException $exception) {
            return ['error' => collect($exception->errors())->flatten()->first()];
        }
    }

    public function termChanges(User $actor, int $id): Builder
    {
        $this->loans($actor)->whereKey($id)->firstOrFail();

        return LoanTermChange::where('loan_id', $id);
    }

    public function termReceipt(User $actor, int $id): LoanTermChange
    {
        app(LoanAuthorization::class)->authorize($actor, 'viewAny');
        $event = LoanTermChange::findOrFail($id);
        $this->loans($actor)->whereKey($event->loan_id)->firstOrFail();

        return $event;
    }

    public function termPreview(User $actor, int $id, string $kind, int $days, int $periods): array
    {
        app(LoanAuthorization::class)->authorize($actor, 'renew');
        $loan = $this->loans($actor)->whereKey($id)->firstOrFail();
        if (! in_array($days, [15, 30], true) || $periods < 1 || $periods > 4) {
            return ['error' => 'Selecciona un plazo y entre uno y cuatro periodos.'];
        }
        try {
            return app(LoanTermCalculator::class)->quote($loan, $kind, $days, $periods);
        } catch (ValidationException $exception) {
            return ['error' => collect($exception->errors())->flatten()->first()];
        }
    }

    public function filterStatus(Builder $q, ?string $status): Builder
    {
        $today = CarbonImmutable::now('America/La_Paz');

        return match ($status) {
            'ACTIVO' => $q->where('status', 'ACTIVO')->where('due_date', '>=', $today->toDateString()),'CON_MULTA' => $q->where('status', 'ACTIVO')->where('due_date', '<', $today->toDateString())->where('due_date', '>=', $today->subDays(7)->toDateString()),'PERDIDA_TOTAL' => $q->where(fn ($lost) => $lost->where('status', 'PERDIDA_TOTAL')->orWhere(fn ($dated) => $dated->where('status', 'ACTIVO')->where('due_date', '<', $today->subDays(7)->toDateString()))),'VENDIDO' => $q->where('status', 'VENDIDO'), 'ANULADO' => $q->where('status', 'ANULADO'), 'RECOGIDO_PAGADO' => $q->where('status', 'RECOGIDO_PAGADO'),null,'' => $q,default => $q->whereRaw('1 = 0')
        };
    }
}
