<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phone_unit_id')->constrained('phone_units')->restrictOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('accessory_exchange_quotes')->restrictOnDelete();
            $table->unsignedInteger('settings_revision');
            $table->unsignedBigInteger('previous_price_cents');
            $table->unsignedBigInteger('price_cents');
            $table->json('pricing_snapshot');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_price_changes');
    }
};
