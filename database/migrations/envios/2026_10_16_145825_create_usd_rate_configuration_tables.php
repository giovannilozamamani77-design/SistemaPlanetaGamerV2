<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usd_rate_references', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 60);
            $table->unsignedBigInteger('rate');
            $table->timestamp('quoted_at')->unique();
            $table->timestamp('next_update_at');
            $table->timestamp('fetched_at');
        });
        Schema::create('usd_rate_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('buy_rate');
            $table->unsignedBigInteger('sell_rate');
            $table->unsignedBigInteger('reference_rate')->nullable();
            $table->timestamp('reference_quoted_at')->nullable();
            $table->string('reason', 500);
            $table->uuid('request_key')->unique();
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usd_rate_settings');
        Schema::dropIfExists('usd_rate_references');
    }
};
