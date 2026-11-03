<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payment_reversals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_id')->unique()->constrained('commission_payments')->restrictOnDelete();
            $t->foreignId('voided_by_user_id')->constrained('users')->restrictOnDelete();
            $t->string('voided_by_name');
            $t->string('reason', 300);
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('earned_cents');
            $t->unsignedBigInteger('before_paid_cents');
            $t->unsignedBigInteger('before_pending_cents');
            $t->unsignedBigInteger('after_pending_cents');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payment_reversals');
    }
};
