<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessory_products', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 60)->unique();
            $t->string('name', 160);
            $t->string('category', 100)->nullable();
            $t->unsignedInteger('minimum_stock')->default(0);
            $t->text('description')->nullable();
            $t->unsignedInteger('quantity')->default(0);
            $t->unsignedBigInteger('stock_value_cents')->default(0);
            $t->unsignedBigInteger('sale_price_cents');
            $t->unsignedInteger('revision')->default(0);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamps();
        });
        Schema::create('accessory_stock_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained('accessory_products')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->string('kind', 20);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_cost_cents');
            $t->unsignedBigInteger('total_cost_cents');
            $t->string('supplier', 160)->nullable();
            $t->string('note', 500)->nullable();
            $t->unsignedInteger('previous_revision');
            $t->unsignedInteger('before_quantity');
            $t->unsignedInteger('after_quantity');
            $t->unsignedBigInteger('before_value_cents');
            $t->unsignedBigInteger('after_value_cents');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['product_id', 'previous_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_stock_entries');
        Schema::dropIfExists('accessory_products');
    }
};
