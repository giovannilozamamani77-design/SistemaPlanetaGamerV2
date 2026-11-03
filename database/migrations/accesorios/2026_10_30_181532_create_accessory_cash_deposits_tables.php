<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessory_cash_deposits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('movement_id')->unique()->constrained('accessory_commercial_movements')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('card_deposit_accounts')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->json('destination_snapshot');
            $t->unsignedBigInteger('amount_cents');
            $t->string('operation_reference', 200)->nullable();
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at')->index();
        });
        Schema::create('accessory_cash_deposit_reversals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('deposit_id')->unique()->constrained('accessory_cash_deposits')->restrictOnDelete();
            $t->foreignId('movement_id')->unique()->constrained('accessory_commercial_movements')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->string('reason', 300);
            $t->unsignedBigInteger('amount_cents');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_cash_deposit_reversals');
        Schema::dropIfExists('accessory_cash_deposits');
    }
};
