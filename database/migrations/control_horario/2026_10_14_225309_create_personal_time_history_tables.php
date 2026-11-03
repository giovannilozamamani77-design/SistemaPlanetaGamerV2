<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_incidents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('scheduled_workday_id')->constrained()->restrictOnDelete();
            $t->string('kind', 24);
            $t->string('status', 32);
            $t->string('source_key')->unique();
            $t->timestamp('observed_at')->nullable();
            $t->unsignedInteger('real_minutes')->default(0);
            $t->unsignedInteger('nominal_minutes')->default(0);
            $t->unsignedInteger('pending_minutes')->default(0);
            $t->text('decision_reason')->nullable();
            $t->string('reason_code', 48)->nullable();
            $t->text('reason')->nullable();
            $t->json('calculation');
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status', 'kind']);
        });
        Schema::create('time_movements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('time_incident_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('bucket', 24);
            $t->integer('delta_minutes');
            $t->string('event_key')->unique();
            $t->json('metadata');
            $t->timestamp('created_at');
            $t->index(['user_id', 'bucket', 'created_at']);
        });
        Schema::create('monthly_leaves', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('month', 7);
            $t->string('status', 16)->default('DISPONIBLE');
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'month']);
        });
        Schema::create('leave_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('monthly_leave_id')->constrained()->restrictOnDelete();
            $t->foreignId('scheduled_workday_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('time_incident_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('kind', 32);
            $t->string('status', 24)->default('PENDIENTE');
            $t->string('request_key')->unique();
            $t->text('reason');
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        Schema::create('time_history_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 48);
            $t->string('event_key')->unique();
            $t->json('details');
            $t->timestamp('created_at');
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['time_history_events', 'leave_requests', 'monthly_leaves', 'time_movements', 'time_incidents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
