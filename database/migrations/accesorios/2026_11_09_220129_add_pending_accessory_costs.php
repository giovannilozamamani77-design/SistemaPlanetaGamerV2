<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessory_products', fn (Blueprint $table) => $table->boolean('cost_pending')->default(false));
        Schema::table('accessory_stock_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('unit_cost_cents')->nullable()->change();
            $table->unsignedBigInteger('total_cost_cents')->nullable()->change();
        });
        Schema::table('accessory_sales', fn (Blueprint $table) => $table->unsignedBigInteger('cost_cents')->nullable()->change());
        Schema::table('accessory_sale_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('cost_cents')->nullable()->change();
            $table->json('pending_commission_snapshot')->nullable();
        });
        Schema::create('accessory_cost_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('accessory_products')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_cost_cents');
            $table->unsignedInteger('remaining_quantity');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('accessory_products')->where('cost_pending', true)->exists() || DB::table('accessory_cost_resolutions')->exists()) {
            throw new RuntimeException('Hay costos pendientes o resueltos que deben conservarse.');
        }
        Schema::dropIfExists('accessory_cost_resolutions');
        Schema::table('accessory_sale_lines', function (Blueprint $table): void {
            $table->dropColumn('pending_commission_snapshot');
            $table->unsignedBigInteger('cost_cents')->nullable(false)->change();
        });
        Schema::table('accessory_sales', fn (Blueprint $table) => $table->unsignedBigInteger('cost_cents')->nullable(false)->change());
        Schema::table('accessory_stock_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('unit_cost_cents')->nullable(false)->change();
            $table->unsignedBigInteger('total_cost_cents')->nullable(false)->change();
        });
        Schema::table('accessory_products', fn (Blueprint $table) => $table->dropColumn('cost_pending'));
    }
};
