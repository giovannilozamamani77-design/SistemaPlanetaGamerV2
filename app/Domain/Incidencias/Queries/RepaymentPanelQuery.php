<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RepaymentPanelQuery
{
    public function filters(array $data): array
    {
        return Validator::make($data, [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'method' => ['nullable', Rule::in(['EFECTIVO', 'DEPOSITO', 'QR', 'SUELDO'])],
            'status' => ['nullable', Rule::in(['pending', 'verified', 'rejected'])],
        ])->validate();
    }

    public function query(User $actor, string $section, array $filters = []): Builder
    {
        $user = app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar');
        $f = $this->filters($filters);
        $q = match ($section) {
            'reports' => app(PendingRepaymentReportsQuery::class)->execute($user),
            'global' => GlobalRecovery::query()->with(['user', 'declarant']),
            'appeals' => app(PendingIncidentAppealsQuery::class)->execute($user),
            default => abort(404),
        };
        if (filled($f['user_id'] ?? null)) {
            if ($section === 'reports') {
                $q->whereHas('economicIncident', fn ($q) => $q->where('user_id', $f['user_id']));
            } else {
                $q->where('user_id', $f['user_id']);
            }
        }
        $date = $section === 'appeals' ? 'receipt_justified_at' : 'created_at';
        if (filled($f['desde'] ?? null)) {
            $q->where($date, '>=', CarbonImmutable::parse($f['desde'], 'America/La_Paz')->startOfDay()->utc());
        }
        if (filled($f['hasta'] ?? null)) {
            $q->where($date, '<', CarbonImmutable::parse($f['hasta'], 'America/La_Paz')->addDay()->startOfDay()->utc());
        }
        if ($section !== 'appeals') {
            if (filled($f['method'] ?? null)) {
                $q->where('method', $f['method']);
            }
            if ($section === 'reports' && in_array($f['status'] ?? null, ['verified', 'rejected'], true)) {
                $q->whereRaw('1=0');
            }
            if ($section === 'global') {
                match ($f['status'] ?? null) {
                    'pending' => $q->whereNull('confirmed_at')->whereNull('rejected_at'),
                    'verified' => $q->whereNotNull('confirmed_at'),
                    'rejected' => $q->whereNotNull('rejected_at'),default => null,
                };
            }
        }

        return $q;
    }

    public function overview(User $actor, array $filters = []): array
    {
        $admin = app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar');

        return [
            'pending_cents' => (int) EconomicIncident::whereIn('status', ['CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'])->sum('pending_cents'),
            'reports' => $this->query($admin, 'reports', $filters)->count(),
            'global' => $this->query($admin, 'global', $filters)->whereNull('confirmed_at')->whereNull('rejected_at')->count(),
            'appeals' => $admin->can('incidencias.confirmar') ? $this->query($admin, 'appeals', $filters)->count() : 0,
        ];
    }
}
