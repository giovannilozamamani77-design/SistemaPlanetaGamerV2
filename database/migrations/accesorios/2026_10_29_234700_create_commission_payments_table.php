<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('payee_name');
            $t->foreignId('registered_by_user_id')->constrained('users')->restrictOnDelete();
            $t->string('registered_by_name');
            $t->string('source', 30);
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('earned_cents');
            $t->unsignedBigInteger('before_paid_cents');
            $t->unsignedBigInteger('before_pending_cents');
            $t->unsignedBigInteger('after_pending_cents');
            $t->string('reference', 100)->nullable();
            $t->string('note', 300)->nullable();
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
            $t->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payments');
    }
};
