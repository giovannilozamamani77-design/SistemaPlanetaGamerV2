<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Infrastructure\Accesorios\AccessoryReceiptPdf;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
function receiptAccessoryAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    Http::preventStrayRequests();
    $u = User::factory()->create(['name' => 'Cajera de prueba']);
    $u->assignRole('administrador');
    test()->actingAs($u);

    return $u;
}
function receiptAccessorySale(User $u): AccessorySale
{
    $p = app(CreateAccessoryProductAction::class)->execute($u, ['code' => '0000123456789', 'name' => 'Cargador rápido USB C', 'description' => 'Carga rápida de 25 W con cable', 'category' => 'Cargadores', 'sale_price' => '25', 'request_key' => (string) Str::uuid()]);
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $p->id, 'quantity' => 10, 'unit_cost' => '12.34', 'kind' => 'INITIAL', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $p->refresh();

    return app(RegisterAccessorySaleAction::class)->execute($u, ['lines' => [['product_id' => $p->id, 'quantity' => 2, 'expected_revision' => $p->revision, 'expected_price_cents' => 2500]], 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => (string) Str::uuid()]);
}
it('C253 prints and regenerates receipt from saved snapshots even after product and cashier changes without another charge', function () {
    $u = receiptAccessoryAdmin();
    $sale = receiptAccessorySale($u);
    $p = AccessoryProduct::first();
    $p->update(['name' => 'Nombre cambiado', 'description' => 'Detalle nuevo', 'sale_price_cents' => 9900]);
    $u->update(['name' => 'Otro nombre']);
    $url = route('accesorios.recibo', ['sale' => $sale->id]);
    $this->get($url)->assertOk()->assertSee('Cargador rápido USB C')->assertSee('Carga rápida de 25 W con cable')->assertSee('Cajera de prueba')->assertSee('Bs 50')->assertDontSee('Nombre cambiado')->assertDontSee('12.34')->assertHeader('Cache-Control', 'no-store, private');
    $this->get($url.'?print=1')->assertOk();
    expect(AccessorySale::count())->toBe(1)->and(CommercialMovement::count())->toBe(1)->and(CommercialFund::first()->balance_cents)->toBe(5000)->and($p->fresh()->quantity)->toBe(8);
    Http::assertNothingSent();
});
it('C253 returns a real private Letter PDF and optionally exports only a synthetic receipt for local layout verification', function () {
    $u = receiptAccessoryAdmin();
    $sale = receiptAccessorySale($u);
    $r = $this->get(route('accesorios.recibo-pdf', ['sale' => $sale->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $bytes = $r->getContent();
    expect($bytes)->toStartWith('%PDF-');
    if ($path = getenv('C253_RECEIPT_QA_PATH')) {
        file_put_contents($path, $bytes);
    }
    expect(AccessorySale::count())->toBe(1)->and(CommercialMovement::count())->toBe(1);
});
it('C253 requires active receipt permission and never exposes another receipt to unauthenticated or revoked users', function () {
    $u = receiptAccessoryAdmin();
    $sale = receiptAccessorySale($u);
    $url = route('accesorios.recibo', ['sale' => $sale->id]);
    $this->get(route('accesorios.recibo', ['sale' => 999999]))->assertNotFound();
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs($u);
    $u->forceFill(['is_active' => false])->save();
    $this->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect();
});
it('C253 receipt is available to cashier without exposing acquisition cost or profit', function () {
    $u = receiptAccessoryAdmin();
    $sale = receiptAccessorySale($u);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier)->get(route('accesorios.recibo', ['sale' => $sale->id]))->assertOk()->assertDontSee('Costo aplicado')->assertDontSee('Ganancia')->assertSee('Cargador rápido USB C');
});

it('C253 does not invent descriptions for legacy sale lines that lack their original snapshot', function () {
    $u = receiptAccessoryAdmin();
    $sale = receiptAccessorySale($u);
    DB::table('accessory_sale_lines')->where('sale_id', $sale->id)->update(['product_description' => null]);
    AccessoryProduct::first()->update(['description' => 'Este detalle no existía en la venta antigua']);
    $this->get(route('accesorios.recibo', ['sale' => $sale->id]))->assertOk()->assertDontSee('Este detalle no existía')->assertSee('Cargador rápido USB C');
});

it('C306 keeps Letter paper and paginates long receipts inside half-sheet printable areas', function () {
    $u = receiptAccessoryAdmin();
    $sale = receiptAccessorySale($u);
    $receipt = app(AccessorySaleQuery::class)->receipt($u, $sale->id);
    $pdf = app(AccessoryReceiptPdf::class)->render($receipt);
    $pdf->output();
    $canvas = $pdf->getDomPDF()->getCanvas();
    expect($canvas->get_width())->toBe(612.0)->and($canvas->get_height())->toBe(792.0)->and($canvas->get_page_count())->toBe(1);
    $receipt['lines'] = array_fill(0, 30, $receipt['lines'][0]);
    $long = app(AccessoryReceiptPdf::class)->render($receipt);
    $long->output();
    expect($long->getDomPDF()->getCanvas()->get_page_count())->toBeGreaterThan(1);
    expect(AccessorySale::count())->toBe(1)->and(CommercialMovement::count())->toBe(1);
    Http::assertNothingSent();
});
