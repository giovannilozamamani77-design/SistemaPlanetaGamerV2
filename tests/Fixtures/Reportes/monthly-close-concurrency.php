<?php

use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Reportes\Actions\ConfirmMonthlyReportCloseAction;
use App\Domain\Reportes\Queries\MonthlyReportSnapshotQuery;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$root = dirname(__DIR__, 3);
$file = realpath($argv[2] ?? '');
$allowed = realpath($root.'/storage/framework/testing');
if (! $file || ! $allowed || ! str_starts_with($file, $allowed.DIRECTORY_SEPARATOR) || ! str_starts_with(basename($file), 'c359-')) {
    throw new RuntimeException('Test database outside the allowed test directory.');
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $file, 'database.connections.sqlite.busy_timeout' => 5000, 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
DB::purge('sqlite');
if (realpath(config('database.connections.sqlite.database')) !== $file) {
    throw new RuntimeException('Unsafe test database.');
}
Http::preventStrayRequests();
if ($argv[1] === 'setup') {
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    (new UsuariosPermissionsSeeder)->run();
    foreach ([1, 2] as $number) {
        $user = User::factory()->create(['name' => 'Solo prueba '.$number, 'pin_hash' => Hash::make('1234')]);
        $user->assignRole('administrador');
    }
    LoanMovement::create(['user_id' => 1, 'kind' => 'OBJECT_SALE', 'delta_cents' => 12345, 'before_cents' => 0, 'after_cents' => 12345, 'created_at' => '2020-01-15 16:00:00']);
    echo 'ready';
} else {
    $actor = User::findOrFail((int) $argv[3]);
    $capture = app(MonthlyReportSnapshotQuery::class)->execute($actor, '2020-01');
    $input = ['month' => '2020-01', 'request_uuid' => (string) Str::uuid(), 'preview_hash' => $capture['hash'], 'accept_pending' => false];
    file_put_contents($file.'.ready.'.$actor->id, 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($file.'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test barrier timed out.');
        }
        usleep(10000);
    }
    $close = app(ConfirmMonthlyReportCloseAction::class)->execute($actor, $input, '1234');
    echo json_encode(['id' => $close->id, 'month' => $close->month, 'sales_cents' => $close->snapshot['report']['sales_cents'], 'count' => DB::table('report_monthly_closes')->count()], JSON_THROW_ON_ERROR);
}
