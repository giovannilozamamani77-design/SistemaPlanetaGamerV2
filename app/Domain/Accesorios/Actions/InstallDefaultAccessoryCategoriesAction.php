<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Models\AccessoryCategory;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallDefaultAccessoryCategoriesAction
{
    public const CATEGORIES = ['Teléfonos' => 'IMEI', 'Memorias' => 'QUANTITY', 'Mandos' => 'QUANTITY', 'Accesorios de PlayStation' => 'QUANTITY', 'Audífonos' => 'QUANTITY', 'Cargadores' => 'QUANTITY', 'Audífonos inalámbricos' => 'QUANTITY', 'Accesorios para teléfonos' => 'QUANTITY', 'Gaming' => 'QUANTITY'];

    public function execute(User $actor): array
    {
        app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');

        return DB::transaction(function () use ($actor): array {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
            $created = 0;
            $existing = 0;
            $conflicts = [];
            foreach (self::CATEGORIES as $name => $tracking) {
                $key = Str::lower(Str::ascii($name));
                $old = AccessoryCategory::where('normalized_name', $key)->first();
                if ($old) {
                    $existing++;
                    if ($old->tracking !== $tracking) {
                        $conflicts[] = $old->name;
                    }

                    continue;
                }
                app(CreateAccessoryCategoryAction::class)->execute($u, ['name' => $name, 'tracking' => $tracking, 'request_key' => (string) Str::uuid()]);
                $created++;
            }

            return ['created' => $created, 'existing' => $existing, 'conflicts' => $conflicts];
        }, 3);
    }
}
