<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_box_configurations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('base_capital_cents');
            $t->json('accounts');
            $t->json('shifts');
            $t->text('reason');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
            $t->index(['cash_box_id', 'id']);
        });
        Schema::create('cash_box_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name', 100);
            $t->unique(['cash_box_id', 'code']);
        });
        Schema::create('cash_box_sessions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('configuration_id')->constrained('cash_box_configurations')->restrictOnDelete();
            $t->foreignId('previous_session_id')->nullable()->constrained('cash_box_sessions')->restrictOnDelete();
            $t->foreignId('scheduled_workday_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 32)->default('ABIERTA');
            $t->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $t->unsignedBigInteger('base_capital_cents');
            $t->json('opening_balances');
            $t->json('expected_balances');
            $t->json('comparison');
            $t->json('handover_balances')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->unique(['cash_box_id', 'active_slot']);
            $t->index(['user_id', 'opened_at']);
        });
        Schema::create('cash_box_accesses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('pin_audit_id')->constrained('user_audits')->restrictOnDelete();
            $t->foreignId('configuration_id')->constrained('cash_box_configurations')->restrictOnDelete();
            $t->foreignId('cash_box_session_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('scheduled_workday_id')->nullable()->constrained()->restrictOnDelete();
            $t->json('expected_balances');
            $t->timestamp('verified_at');
            $t->uuid('request_key')->unique();
        });
        Schema::create('cash_box_session_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('access_id')->unique()->constrained('cash_box_accesses')->restrictOnDelete();
            $t->string('action', 20);
            $t->timestamp('created_at');
        });
        Schema::create('cash_box_receipt_discrepancies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_session_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('previous_session_id')->nullable()->constrained('cash_box_sessions')->restrictOnDelete();
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->bigInteger('difference_cents');
            $t->json('comparison');
            $t->text('reason');
            $t->string('status', 20)->default('EN_REVISION');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_box_receipt_discrepancies');
        Schema::dropIfExists('cash_box_session_events');
        Schema::dropIfExists('cash_box_accesses');
        Schema::dropIfExists('cash_box_sessions');
        Schema::dropIfExists('cash_box_accounts');
        Schema::dropIfExists('cash_box_configurations');
    }
};
