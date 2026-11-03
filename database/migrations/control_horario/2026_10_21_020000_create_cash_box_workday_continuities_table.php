<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_box_workday_continuities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('cash_box_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('scheduled_workday_id')->constrained()->restrictOnDelete();
            $t->foreignId('opening_event_id')->constrained('cash_box_schedule_events')->restrictOnDelete();
            $t->string('evidence_kind', 16);
            $t->foreignId('operation_id')->nullable()->constrained('cash_box_operations')->restrictOnDelete();
            $t->timestamp('observed_at');
            $t->timestamp('effective_at');
            $t->string('status', 16)->default('PENDING');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error_code', 40)->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->unique(['cash_box_session_id', 'scheduled_workday_id'], 'cash_box_continuity_session_day_unique');
            $t->index(['status', 'id']);
            $t->index(['scheduled_workday_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_box_workday_continuities');
    }
};
