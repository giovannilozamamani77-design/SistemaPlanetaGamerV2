<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_products', function (Blueprint $t): void {
            $t->id();
            $t->string('company', 12);
            $t->unsignedBigInteger('face_value_cents');
            $t->unsignedBigInteger('purchase_price_cents');
            $t->unsignedBigInteger('normal_price_cents');
            $t->unsignedBigInteger('wholesale_price_cents');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company', 'face_value_cents']);
        });
        Schema::create('card_stores', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 30)->unique();
            $t->unsignedBigInteger('cash_balance_cents')->default(0);
            $t->timestamp('initialized_at')->nullable();
            $t->foreignId('initialized_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('revision')->default(0);
            $t->timestamps();
        });
        Schema::create('card_stocks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('card_store_id')->constrained('card_stores')->restrictOnDelete();
            $t->foreignId('card_product_id')->constrained('card_products')->restrictOnDelete();
            $t->unsignedBigInteger('quantity')->default(0);
            $t->timestamps();
            $t->unique(['card_store_id', 'card_product_id']);
        });
        Schema::create('card_inventory_movements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('card_store_id')->constrained('card_stores')->restrictOnDelete();
            $t->foreignId('card_product_id')->nullable()->constrained('card_products')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind', 30);
            $t->unsignedBigInteger('quantity');
            $t->unsignedBigInteger('unit_cost_cents')->nullable();
            $t->unsignedBigInteger('total_cost_cents')->nullable();
            $t->bigInteger('cash_delta_cents');
            $t->unsignedBigInteger('stock_before')->nullable();
            $t->unsignedBigInteger('stock_after')->nullable();
            $t->unsignedBigInteger('cash_before_cents');
            $t->unsignedBigInteger('cash_after_cents');
            $t->json('product_snapshot')->nullable();
            $t->string('reference', 200)->nullable();
            $t->timestamps();
            $t->index(['card_store_id', 'id']);
            $t->index(['card_product_id', 'id']);
        });
        Schema::create('card_inventory_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('action', 40);
            $t->string('payload_hash', 64);
            $t->json('payload');
            $t->json('result');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_inventory_commands');
        Schema::dropIfExists('card_inventory_movements');
        Schema::dropIfExists('card_stocks');
        Schema::dropIfExists('card_stores');
        Schema::dropIfExists('card_products');
    }
};
