<?php

namespace App\Domain\Configuraciones\Actions;

use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\CreateCashBoxAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Spatie\Permission\Models\Role;

class InitializeEmptyInstallationAction
{
    public function execute(string $email, #[SensitiveParameter] string $password): User
    {
        Validator::make(compact('email', 'password'), ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'min:12', 'max:72']])->validate();

        return DB::transaction(function () use ($email, $password): User {
            $this->assertEmpty();
            app(UsuariosPermissionsSeeder::class)->run();
            Role::where('name', 'administrador')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
            $this->assertEmpty();
            foreach (glob(database_path('seeders').'/*/*PermissionsSeeder.php') as $file) {
                $class = 'Database\\Seeders\\'.basename(dirname($file)).'\\'.basename($file, '.php');
                app($class)->run();
            }
            $user = User::create(['name' => 'Giovanni', 'email' => $email, 'password' => $password]);
            $user->assignRole('administrador');
            $this->createInitialBoxes($user);
            $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 0, 'revision' => 0]);
            $rows = json_decode(file_get_contents(database_path('seeders/Tarjetas/initial-card-catalog.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach ($rows as $row) {
                $product = CardProduct::create($row + ['revision' => 1, 'created_by' => $user->id]);
                CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $product->id, 'quantity' => 0, 'cost_value_cents' => 0, 'cost_revision' => 0]);
            }

            return $user;
        }, 3);
    }

    private function createInitialBoxes(User $administrator): void
    {
        foreach ([
            ['code' => 'BCP_1', 'name' => 'Tablet Blanca', 'type' => 'BCP', 'base_capital' => '16000'],
            ['code' => 'BCP_2', 'name' => 'Tablet Negra', 'type' => 'BCP', 'base_capital' => '16000'],
            ['code' => 'PTM', 'name' => 'Tigo Money', 'type' => 'TIGO_MONEY', 'base_capital' => '10000'],
        ] as $definition) {
            $box = app(CreateCashBoxAction::class)->execute($administrator, $definition + [
                'is_active' => true,
                'reason' => 'Alta inicial autorizada para la puesta en marcha.',
                'request_key' => (string) Str::uuid(),
            ]);
            $accounts = array_map(fn (string $name): array => ['name' => $name, 'initial' => null], CashBoxComponents::forType($definition['type']));
            app(ConfigureCashBoxAction::class)->execute($administrator, $box, [
                'base_capital' => $definition['base_capital'],
                'accounts' => $accounts,
                'expected_revision' => $box->revision,
                'reason' => 'Capital base inicial; saldos reales pendientes del primer conteo.',
                'request_key' => (string) Str::uuid(),
            ]);
        }
    }

    private function assertEmpty(): void
    {
        $technical = ['migrations', 'permissions', 'roles', 'role_has_permissions', 'cash_box_types'];
        foreach (Schema::getTableListing() as $table) {
            $name = basename(str_replace('.', '/', $table));
            if (in_array($name, $technical, true) || str_starts_with($name, 'sqlite_')) {
                continue;
            }
            if (DB::table($name)->exists()) {
                throw ValidationException::withMessages(['installation' => 'Instalación cancelada: requiere una base vacía migrada. No se borró ni reemplazó ningún dato.']);
            }
        }
    }
}
