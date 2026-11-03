<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_brands', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 80);
            $t->string('normalized_name', 100)->unique();
            $t->timestamps();
        });
        Schema::create('phone_models', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('brand_id')->constrained('phone_brands')->restrictOnDelete();
            $t->string('name', 100);
            $t->string('normalized_name', 130);
            $t->unique(['brand_id', 'normalized_name']);
            $t->timestamps();
        });
        Schema::create('phone_variants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('model_id')->constrained('phone_models')->restrictOnDelete();
            $t->unsignedSmallInteger('ram_gb');
            $t->unsignedSmallInteger('storage_gb');
            $t->unique(['model_id', 'ram_gb', 'storage_gb']);
            $t->timestamps();
        });
        Schema::create('phone_units', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('variant_id')->constrained('phone_variants')->restrictOnDelete();
            $t->string('condition', 10);
            $t->string('color', 60);
            $t->string('physical_state', 1000)->nullable();
            $t->string('included_accessories', 500)->nullable();
            $t->string('origin', 200)->nullable();
            $t->string('status', 20)->default('AVAILABLE');
            $t->index(['variant_id', 'status']);
            $t->string('purchase_currency', 3);
            $t->unsignedBigInteger('purchase_amount_cents');
            $t->unsignedBigInteger('purchase_rate_micros')->nullable();
            $t->unsignedBigInteger('expenses_cents');
            $t->unsignedBigInteger('historical_cost_cents');
            $t->unsignedBigInteger('sale_price_cents');
            $t->json('pricing_snapshot');
            $t->json('registration_snapshot');
            $t->string('image_path')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamps();
        });
        Schema::create('phone_imeis', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('unit_id')->constrained('phone_units')->restrictOnDelete();
            $t->unsignedTinyInteger('slot');
            $t->string('imei', 15)->unique();
            $t->unique(['unit_id', 'slot']);
        });
    }

    public function down(): void
    {
        foreach (['phone_imeis', 'phone_units', 'phone_variants', 'phone_models', 'phone_brands'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
