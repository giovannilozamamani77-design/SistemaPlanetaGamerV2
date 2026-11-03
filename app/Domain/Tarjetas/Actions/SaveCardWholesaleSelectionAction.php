<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveCardWholesaleSelectionAction
{
    public function execute(User $actor, array $data): void
    {
        app(CardInventoryAuthorization::class)->authorize($actor, 'update');
        $v = Validator::make($data, ['enabled' => ['required', 'array:ENTEL,TIGO,VIVA'], 'enabled.ENTEL' => ['present', 'array'], 'enabled.TIGO' => ['present', 'array'], 'enabled.VIVA' => ['present', 'array'], 'enabled.*.*' => ['integer', 'min:1'], 'revisions' => ['present', 'array'], 'revisions.*' => ['integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $enabled = [];
        foreach ($v['enabled'] as $company => $ids) {
            $enabled[$company] = array_map('intval', $ids);
            sort($enabled[$company]);
        }ksort($enabled);
        $revisions = array_map('intval', $v['revisions']);
        ksort($revisions);
        app(CardInventoryCommandRunner::class)->execute($actor, 'update', 'WHOLESALE_SELECTION', $v['request_key'], ['enabled' => $enabled, 'revisions' => $revisions], function () use ($enabled, $revisions): array {
            $products = CardProduct::where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            $current = $products->pluck('revision', 'id')->map(fn ($n) => (int) $n)->all();
            ksort($current);
            if ($current !== $revisions) {
                throw ValidationException::withMessages(['enabled.ENTEL' => 'El catálogo cambió. Cierra y vuelve a abrir Ajustes para cargar los datos actuales.']);
            }
            $selected = [];
            foreach ($enabled as $company => $ids) {
                foreach ($ids as $id) {
                    $p = $products->firstWhere('id', $id);
                    if (! $p || $p->company !== $company || isset($selected[$id])) {
                        throw ValidationException::withMessages(['enabled.'.$company => 'Selecciona únicamente cortes activos de esta empresa.']);
                    }$selected[$id] = true;
                }
            }
            $changes = [];
            foreach ($products as $p) {
                $next = isset($selected[$p->id]);
                if ($p->wholesale_enabled === $next) {
                    continue;
                }$changes[] = ['product_id' => $p->id, 'before' => $p->wholesale_enabled, 'after' => $next];
                $p->update(['wholesale_enabled' => $next, 'revision' => $p->revision + 1]);
            }

            return ['changes' => $changes];
        });
    }
}
