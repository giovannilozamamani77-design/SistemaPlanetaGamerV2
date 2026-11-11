<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('time_incident_id')->unique()->constrained('time_incidents')->restrictOnDelete();
            $table->string('employee_name');
            $table->foreignId('registered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('registered_by_name');
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('before_pending_cents');
            $table->unsignedBigInteger('after_pending_cents');
            $table->string('reason', 500);
            $table->json('snapshot');
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_deductions');
    }
};
