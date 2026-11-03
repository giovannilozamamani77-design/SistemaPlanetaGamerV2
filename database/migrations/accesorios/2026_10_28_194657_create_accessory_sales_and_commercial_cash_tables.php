<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessory_product_price_changes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained('accessory_products')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->string('price_mode', 12);
            $t->unsignedBigInteger('old_price_cents');
            $t->unsignedBigInteger('new_price_cents');
            $t->unsignedBigInteger('cost_basis_cents');
            $t->unsignedInteger('pricing_revision');
            $t->unsignedInteger('markup_bps');
            $t->unsignedInteger('previous_revision');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['product_id', 'previous_revision']);
        });
        Schema::create('accessory_sales', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->string('payment_method', 12);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('total_cents');
            $t->unsignedBigInteger('cost_cents');
            $t->unsignedInteger('pricing_revision');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
        });
        Schema::create('accessory_sale_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sale_id')->constrained('accessory_sales')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('accessory_products')->restrictOnDelete();
            $t->string('product_code', 60);
            $t->string('product_name', 160);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_cents');
            $t->unsignedBigInteger('total_cents');
            $t->unsignedBigInteger('cost_cents');
            $t->unsignedInteger('previous_revision');
            $t->unsignedInteger('before_quantity');
            $t->unsignedInteger('after_quantity');
            $t->unsignedBigInteger('before_value_cents');
            $t->unsignedBigInteger('after_value_cents');
            $t->unique(['sale_id', 'product_id']);
            $t->unique(['product_id', 'previous_revision']);
        });
        Schema::create('accessory_commercial_funds', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 20)->unique();
            $t->unsignedBigInteger('balance_cents')->default(0);
            $t->unsignedInteger('revision')->default(0);
        });
        Schema::create('accessory_commercial_movements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('fund_id')->constrained('accessory_commercial_funds')->restrictOnDelete();
            $t->foreignId('sale_id')->nullable()->unique()->constrained('accessory_sales')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->string('kind', 30);
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('before_cents');
            $t->unsignedBigInteger('after_cents');
            $t->unsignedInteger('previous_revision');
            $t->timestamp('created_at');
            $t->unique(['fund_id', 'previous_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_commercial_movements');
        Schema::dropIfExists('accessory_commercial_funds');
        Schema::dropIfExists('accessory_sale_lines');
        Schema::dropIfExists('accessory_sales');
        Schema::dropIfExists('accessory_product_price_changes');
    }
};
