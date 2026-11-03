<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_sessions', function (Blueprint $t): void {
            $t->json('current_balances')->nullable();
            $t->unsignedInteger('revision')->default(0);
        });
        DB::table('cash_box_sessions')->update(['current_balances' => DB::raw('opening_balances')]);
        Schema::create('cash_box_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('kind', 40);
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('result_id');
            $t->timestamp('created_at');
        });
        Schema::create('cash_box_commission_accounts', function (Blueprint $t): void {
            $t->id();
            $t->string('destination', 120);
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->timestamp('created_at');
        });
        Schema::create('cash_box_operations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('source', 20)->nullable();
            $t->string('destination', 20)->nullable();
            $t->unsignedBigInteger('amount_cents');
            $t->string('fee_type', 20);
            $t->unsignedInteger('bill_count')->default(0);
            $t->unsignedBigInteger('suggested_fee_cents');
            $t->unsignedBigInteger('fee_cents');
            $t->string('fee_component', 20);
            $t->json('deltas');
            $t->text('reason')->nullable();
            $t->string('status', 16)->default('VIGENTE');
            $t->timestamp('created_at');
            $t->index(['cash_box_session_id', 'id']);
        });
        Schema::create('cash_box_corrections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_operation_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('cash_box_session_id')->constrained()->restrictOnDelete();
            $t->text('reason');
            $t->string('status', 16)->default('PENDIENTE');
            $t->text('decision_reason')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->timestamp('decided_at')->nullable();
        });
        Schema::create('cash_box_early_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_session_id')->constrained()->restrictOnDelete();
            $t->string('period', 16);
            $t->text('reason');
            $t->string('status', 16)->default('PENDIENTE');
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_reason')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('decided_at')->nullable();
            $t->unique(['cash_box_session_id', 'period']);
        });
        Schema::create('cash_box_closes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_session_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('period', 16);
            $t->json('shift_snapshot');
            $t->json('counted_balances');
            $t->json('real_balances');
            $t->json('expected_balances');
            $t->json('comparison');
            $t->bigInteger('difference_cents');
            $t->unsignedBigInteger('commission_cents');
            $t->unsignedBigInteger('approved_surplus_cents')->default(0);
            $t->unsignedBigInteger('liquidated_cents')->default(0);
            $t->unsignedBigInteger('capital_cents');
            $t->boolean('requires_liquidation');
            $t->boolean('liquidation_completed')->default(false);
            $t->string('settlement_source', 20);
            $t->foreignId('commission_account_id')->nullable()->constrained('cash_box_commission_accounts')->restrictOnDelete();
            $t->string('settlement_destination', 120)->nullable();
            $t->text('reason')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_reason')->nullable();
            $t->foreignId('economic_incident_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('release_reason')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('released_at')->nullable();
            $t->index(['cash_box_id', 'id']);
        });
        Schema::create('cash_box_liquidations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_close_id')->constrained()->restrictOnDelete();
            $t->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('theoretical_cents');
            $t->unsignedBigInteger('safe_cents');
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('remaining_cents');
            $t->string('source', 20);
            $t->string('destination', 120);
            $t->string('operation_reference', 120)->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
            $t->unique(['destination', 'operation_reference']);
        });
        Schema::create('cash_box_liquidation_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_liquidation_id')->constrained()->restrictOnDelete();
            $t->foreignId('cash_box_close_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_cents');
            $t->unique(['cash_box_liquidation_id', 'cash_box_close_id'], 'cb_liquidation_close_unique');
        });
        Schema::create('cash_box_capital_restorations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_close_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 24)->default('APORTE_ADMINISTRATIVO');
            $t->string('destination', 20)->nullable();
            $t->string('component', 20);
            $t->unsignedBigInteger('amount_cents');
            $t->text('receipt');
            $t->timestamp('created_at');
        });
        Schema::create('cash_box_counter_readings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $t->foreignId('cash_box_close_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('month', 7);
            $t->unsignedBigInteger('reading');
            $t->unsignedBigInteger('delta')->default(0);
            $t->boolean('baseline')->default(false);
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
            $t->index(['cash_box_id', 'month', 'id'], 'cb_counter_period_index');
        });
    }

    public function down(): void
    {
        foreach (['cash_box_counter_readings', 'cash_box_capital_restorations', 'cash_box_liquidation_allocations', 'cash_box_liquidations', 'cash_box_closes', 'cash_box_early_requests', 'cash_box_corrections', 'cash_box_operations', 'cash_box_commission_accounts', 'cash_box_commands'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('cash_box_sessions', function (Blueprint $t): void {
            $t->dropColumn(['current_balances', 'revision']);
        });
    }
};
