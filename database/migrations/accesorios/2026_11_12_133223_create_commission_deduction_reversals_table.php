<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_deduction_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deduction_id')->unique()->constrained('commission_deductions')->restrictOnDelete();
            $table->foreignId('voided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('voided_by_name');
            $table->string('reason', 500);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('before_pending_cents');
            $table->unsignedBigInteger('after_pending_cents');
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_deduction_reversals');
    }
};
