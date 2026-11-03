<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessory_pricing_settings', function (Blueprint $t): void {
            $t->id();
            $t->string('code')->unique();
            $t->string('exchange_mode', 20)->default('PHYSICAL');
            $t->unsignedBigInteger('physical_rate_micros')->nullable();
            $t->unsignedBigInteger('adjustment_micros')->default(0);
            $t->boolean('auto_refresh')->default(true);
            $t->json('rules');
            $t->unsignedBigInteger('revision')->default(0);
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('accessory_pricing_changes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('settings_id')->constrained('accessory_pricing_settings')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name', 160);
            $t->uuid('request_key')->unique();
            $t->char('payload_hash', 64);
            $t->unsignedBigInteger('previous_revision');
            $t->json('before');
            $t->json('after');
            $t->timestamp('created_at');
            $t->unique(['settings_id', 'previous_revision']);
        });
        Schema::create('accessory_exchange_quotes', function (Blueprint $t): void {
            $t->id();
            $t->string('provider', 80);
            $t->unsignedBigInteger('rate_micros');
            $t->timestamp('source_at');
            $t->timestamp('fetched_at');
            $t->char('fingerprint', 64)->unique();
            $t->index(['source_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_exchange_quotes');
        Schema::dropIfExists('accessory_pricing_changes');
        Schema::dropIfExists('accessory_pricing_settings');
    }
};
