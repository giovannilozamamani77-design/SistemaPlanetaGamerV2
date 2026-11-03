<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessory_categories', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100);
            $t->string('normalized_name', 100)->unique();
            $t->string('tracking', 12);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
        });
        Schema::table('accessory_products', function (Blueprint $t): void {
            $t->foreignId('category_id')->nullable()->constrained('accessory_categories')->restrictOnDelete();
            $t->unsignedBigInteger('reference_cost_cents')->nullable();
            $t->string('image_path')->nullable();
        });
        Schema::table('accessory_sale_lines', function (Blueprint $t): void {
            $t->string('product_image_path')->nullable();
        });
        Schema::create('accessory_product_registrations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->unique()->constrained('accessory_products')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->json('snapshot');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_product_registrations');
        Schema::table('accessory_sale_lines', fn (Blueprint $t) => $t->dropColumn('product_image_path'));
        Schema::table('accessory_products', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('category_id');
            $t->dropColumn(['reference_cost_cents', 'image_path']);
        });
        Schema::dropIfExists('accessory_categories');
    }
};
