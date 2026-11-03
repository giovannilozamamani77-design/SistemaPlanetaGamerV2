<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_box_schedule_events', function (Blueprint $t): void {
            $t->id();
            $t->string('source_key', 120)->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind', 16);
            $t->foreignId('pin_audit_id')->constrained('user_audits')->restrictOnDelete();
            $t->foreignId('access_id')->nullable()->constrained('cash_box_accesses')->restrictOnDelete();
            $t->foreignId('cash_box_session_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('scheduled_workday_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('family', 24)->nullable();
            $t->timestamp('occurred_at');
            $t->string('status', 16)->default('PENDING');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error_code', 40)->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->index(['status', 'id']);
            $t->index(['user_id', 'kind', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_box_schedule_events');
    }
};
