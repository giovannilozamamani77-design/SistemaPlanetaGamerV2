<?php

namespace App\Domain\Cajas\Data;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\CashBoxTypeCatalog;
use App\Support\Bolivianos;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashBoxAccountSettings
{
    public static function categories(): array
    {
        return ['efectivo' => 'Efectivo', 'banco' => 'Cuenta bancaria', 'billetera' => 'Billetera / Yape', 'tigo_money' => 'Tigo Money', 'recarga' => 'Recargas y paquetes'];
    }

    public static function defaults(CashBox $box): array
    {
        $rows = app(CashBoxTypeCatalog::class)->accounts($box->id);
        $own = $rows->map(fn ($row): array => ['code' => $row->code, 'name' => $row->name, 'category' => $row->category, 'reference' => $row->reference, 'is_active' => (bool) $row->is_active])->keyBy('code')->all();
        foreach (app(CashBoxTypeCatalog::class)->components($box->type) as $row) {
            if (! isset($own[$row['code']])) {
                $own[$row['code']] = $row + ['is_active' => true];
            }
        }

        return array_values($own);
    }

    public static function normalize(CashBox $box, array $data): array
    {
        $defaults = array_column(self::defaults($box), null, 'code');
        if (! empty($data['additional_accounts'])) {
            Validator::make($data, ['additional_accounts' => ['array', 'max:50'], 'additional_accounts.*.code' => ['required', 'string', 'distinct', 'max:20', 'regex:/\A[a-z][a-z0-9_]*\z/']])->validate();
            foreach ($data['additional_accounts'] as $row) {
                if (isset($data['accounts'][$row['code']])) {
                    throw ValidationException::withMessages(['additional_accounts' => 'Ese código ya identifica otro saldo de esta caja.']);
                }
                $data['accounts'][$row['code']] = $row;
            }
        }
        if (isset($data['account_rows'])) {
            Validator::make($data, ['account_rows' => ['required', 'array', 'min:1', 'max:50'], 'account_rows.*.code' => ['required', 'string', 'distinct', 'max:20', 'regex:/\A[a-z][a-z0-9_]*\z/']])->validate();
            $rows = array_column($data['account_rows'], null, 'code');
        } else {
            $rows = $data['accounts'] ?? [];
        }
        Validator::make(['accounts' => $rows], ['accounts' => ['required', 'array', 'min:1', 'max:50']])->validate();
        $previous = CashBoxConfiguration::where('cash_box_id', $box->id)->latest('id')->first();
        $hasSessions = CashBoxSession::where('cash_box_id', $box->id)->exists();
        $hasDeclared = collect($rows)->contains(fn ($row): bool => is_array($row) && filled($row['initial'] ?? null));
        $first_count_pending = ! $hasSessions && ! $hasDeclared && (! $previous || $previous->first_count_pending);
        $accounts = [];
        $all = [];
        foreach ($rows as $code => $row) {
            if (is_array($row) && ! filled($row['initial'] ?? null)) {
                $row['initial'] = Bolivianos::format((int) ($previous?->accounts[$code]['initial_cents'] ?? 0));
            }
            Validator::make(['code' => $code, 'account' => $row], ['code' => ['required', 'string', 'max:20', 'regex:/\A[a-z][a-z0-9_]*\z/'], 'account' => ['required', 'array:code,name,initial,category,reference,is_active'],
                'account.name' => ['required', 'string', 'max:100'], 'account.initial' => ['required', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
                'account.category' => ['sometimes', 'required', Rule::in(array_keys(self::categories()))], 'account.reference' => ['nullable', 'string', 'max:120'], 'account.is_active' => ['sometimes', 'boolean']])->validate();
            $category = $row['category'] ?? $defaults[$code]['category'] ?? null;
            if (! $category || (isset($defaults[$code]) && $category !== $defaults[$code]['category'])) {
                throw ValidationException::withMessages(['accounts' => 'Cada componente necesita una categoría válida; una cuenta existente conserva su categoría.']);
            }
            $all[$code] = ['name' => trim($row['name']), 'initial_cents' => Bolivianos::cents($row['initial']), 'category' => $category, 'reference' => trim($row['reference'] ?? '') ?: null, 'is_active' => (bool) ($row['is_active'] ?? true)];
            if ($all[$code]['is_active']) {
                $accounts[$code] = $all[$code];
            }
        }
        if (! collect($accounts)->contains(fn (array $a): bool => $a['category'] === 'efectivo')) {
            throw ValidationException::withMessages(['accounts' => 'La caja debe conservar un componente de efectivo activo.']);
        }
        $family = app(CashBoxTypeCatalog::class)->family($box->type);
        $source = $data['settlement_source'] ?? ($family === 'BCP' ? 'bcp' : 'yape');
        Validator::make(['settlement_source' => $source], ['settlement_source' => ['required', 'string', 'max:20']])->validate();
        if (($accounts[$source]['category'] ?? null) !== ($family === 'BCP' ? 'banco' : 'billetera')) {
            throw ValidationException::withMessages(['settlement_source' => 'Selecciona una cuenta activa del origen de liquidación: banco BCP o Yape operativo Tigo.']);
        }
        ksort($accounts);

        return compact('accounts', 'all', 'source', 'first_count_pending');
    }

    public static function assertContinuity(CashBox $box, array $all, array $accounts): void
    {
        $previous = CashBoxConfiguration::where('cash_box_id', $box->id)->latest('id')->first();
        $last = CashBoxSession::where('cash_box_id', $box->id)->latest('id')->first();
        if (! $last) {
            return;
        }
        foreach ($previous?->accounts ?? [] as $code => $old) {
            if (isset($all[$code]) && $all[$code]['initial_cents'] !== $old['initial_cents']) {
                throw ValidationException::withMessages(['accounts' => 'Los saldos de una caja utilizada proceden del cierre autorizado; no se reinicializan desde configuración.']);
            }
        }
        foreach ($last->handover_balances ?? [] as $code => $amount) {
            if (! isset($accounts[$code]) && $amount !== 0) {
                throw ValidationException::withMessages(['accounts' => 'No puedes desactivar u omitir un componente con saldo de entrega. Registra primero el traspaso real.']);
            }
        }
        foreach ($all as $code => $row) {
            if (! isset($previous?->accounts[$code]) && $row['initial_cents'] !== 0) {
                throw ValidationException::withMessages(['accounts' => 'Un componente añadido a una caja utilizada comienza en cero. Su saldo real se registra en la apertura o mediante un movimiento.']);
            }
        }
    }
}
