<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('economic_incidents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->string('source_module', 32);
            $t->string('source_reference', 160);
            $t->string('component', 48);
            $t->string('status', 32)->default('DETECTADA');
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('pending_cents')->default(0);
            $t->json('policy_snapshot')->nullable();
            $t->text('reason');
            $t->text('decision')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['user_id', 'status', 'occurred_at']);
            $t->unique(['source_module', 'source_reference', 'component'], 'economic_source_component_unique');
        });
        Schema::create('recovery_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('economic_incident_id')->constrained()->restrictOnDelete();
            $t->foreignId('declared_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('paid_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('request_key')->unique();
            $t->string('kind', 24);
            $t->string('method', 24);
            $t->unsignedBigInteger('amount_cents');
            $t->string('operation_reference')->nullable();
            $t->string('destination')->nullable();
            $t->string('salary_period', 7)->nullable();
            $t->text('note');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->unique(['destination', 'operation_reference'], 'recovery_operation_unique');
        });
        Schema::create('economic_history_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('economic_incident_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 48);
            $t->string('event_key')->unique();
            $t->json('details');
            $t->timestamp('created_at');
            $t->index(['economic_incident_id', 'created_at']);
        });
        Schema::create('economic_month_closures', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('month', 7);
            $t->unsignedBigInteger('total_cents');
            $t->boolean('condoned');
            $t->json('cases');
            $t->timestamp('created_at');
            $t->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        foreach (['economic_month_closures', 'economic_history_events', 'recovery_payments', 'economic_incidents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
