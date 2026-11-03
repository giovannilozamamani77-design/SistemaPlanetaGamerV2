<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_stores', function (Blueprint $t) {
            $t->unsignedInteger('entel_credit_bonus_bps')->default(800);
            $t->unsignedInteger('entel_packages_bonus_bps')->default(1500);
        });
        Schema::table('entel_digital_movements', fn (Blueprint $t) => $t->unsignedBigInteger('debited_cents')->default(0));
        Schema::create('entel_recharges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('card_store_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('kind', 12);
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedInteger('bonus_rate_bps');
            $t->unsignedBigInteger('customer_bonus_cents');
            $t->unsignedBigInteger('customer_total_cents');
            $t->foreignId('entel_digital_movement_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('card_inventory_movement_id')->unique()->constrained()->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['card_store_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entel_recharges');
        Schema::table('entel_digital_movements', fn (Blueprint $t) => $t->dropColumn('debited_cents'));
        Schema::table('card_stores', fn (Blueprint $t) => $t->dropColumn(['entel_credit_bonus_bps', 'entel_packages_bonus_bps']));
    }
};
