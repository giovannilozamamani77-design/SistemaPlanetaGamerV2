<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveLoanTypeAction
{
    public function execute(User $actor, array $data, ?LoanObjectType $target = null): LoanObjectType
    {
        app(LoanAuthorization::class)->authorize($actor, 'configureObjects');
        $v = Validator::make($data, ['name' => ['required', 'string', 'max:120'], 'rate' => ['required'], 'is_active' => ['required', 'boolean'], 'expected_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $rate = LoanInput::money($v['rate'], 'rate');
        if ($rate > 999999) {
            throw ValidationException::withMessages(['rate' => 'La tarifa supera el límite admitido.']);
        }
        $values = ['name' => trim($v['name']), 'normalized_name' => LoanInput::normalize($v['name']), 'rate_bps' => $rate, 'is_active' => (bool) $v['is_active']];
        $result = app(LoanCommandRunner::class)->execute($actor, 'configureObjects', 'TYPE_SAVE', $v['request_key'], ['id' => $target?->id, 'values' => $values, 'revision' => (int) $v['expected_revision']], function ($fund, $user) use ($target, $values, $v): array {
            $type = $target ? LoanObjectType::whereKey($target->id)->lockForUpdate()->firstOrFail() : null;
            if (($type?->revision ?? 0) !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['name' => 'El tipo cambió. Vuelve a abrirlo.']);
            }
            if (LoanObjectType::where('normalized_name', $values['normalized_name'])->when($type, fn ($q) => $q->where('id', '!=', $type->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Ya existe este tipo de objeto.']);
            }
            $before = $type?->toArray();
            if ($type) {
                $type->update($values + ['revision' => $type->revision + 1]);
            } else {
                $type = LoanObjectType::create($values + ['revision' => 1, 'created_by' => $user->id]);
            }

            return ['id' => $type->id, 'before' => $before, 'after' => $type->fresh()->toArray()];
        });

        return LoanObjectType::findOrFail($result['id']);
    }
}
