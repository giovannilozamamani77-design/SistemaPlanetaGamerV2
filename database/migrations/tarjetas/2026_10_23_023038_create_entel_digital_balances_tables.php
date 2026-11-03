<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entel_digital_accounts', function (Blueprint $t): void {
            $t->id();
            $t->string('kind', 12)->unique();
            $t->unsignedBigInteger('balance_cents')->default(0);
            $t->timestamp('initialized_at');
            $t->foreignId('initialized_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('entel_digital_movements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('entel_digital_account_id')->constrained('entel_digital_accounts')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->json('payload');
            $t->string('operation', 12);
            $t->unsignedBigInteger('credited_cents');
            $t->unsignedBigInteger('paid_cents')->nullable();
            $t->unsignedBigInteger('bonus_cents')->nullable();
            $t->unsignedBigInteger('bonus_rate_bps')->nullable();
            $t->unsignedBigInteger('balance_before_cents');
            $t->unsignedBigInteger('balance_after_cents');
            $t->string('reference', 200)->nullable();
            $t->timestamp('created_at');
            $t->index(['entel_digital_account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entel_digital_movements');
        Schema::dropIfExists('entel_digital_accounts');
    }
};
