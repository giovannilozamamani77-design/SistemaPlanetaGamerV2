<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_products', function (Blueprint $t): void {
            $t->boolean('wholesale_enabled')->default(false);
        });
        DB::table('card_products')->where('face_value_cents', 1000)->update(['wholesale_enabled' => true]);
        Schema::table('card_stores', function (Blueprint $t): void {
            $t->unsignedInteger('wholesale_min_quantity')->default(5);
        });
        Schema::create('card_sales', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('card_store_id')->constrained('card_stores')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('mode', 12);
            $t->string('payment_method', 12);
            $t->unsignedInteger('wholesale_min_quantity');
            $t->unsignedBigInteger('total_quantity');
            $t->unsignedBigInteger('total_cents');
            $t->unsignedBigInteger('cash_before_cents');
            $t->unsignedBigInteger('cash_after_cents');
            $t->timestamp('created_at');
            $t->index(['card_store_id', 'id']);
        });
        Schema::create('card_sale_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('card_sale_id')->constrained('card_sales')->restrictOnDelete();
            $t->foreignId('card_product_id')->constrained('card_products')->restrictOnDelete();
            $t->foreignId('card_inventory_movement_id')->unique()->constrained('card_inventory_movements')->restrictOnDelete();
            $t->unsignedBigInteger('quantity');
            $t->unsignedBigInteger('unit_price_cents');
            $t->unsignedBigInteger('total_cents');
            $t->json('product_snapshot');
            $t->unique(['card_sale_id', 'card_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_sale_lines');
        Schema::dropIfExists('card_sales');
        Schema::table('card_stores', fn (Blueprint $t) => $t->dropColumn('wholesale_min_quantity'));
        Schema::table('card_products', fn (Blueprint $t) => $t->dropColumn('wholesale_enabled'));
    }
};
