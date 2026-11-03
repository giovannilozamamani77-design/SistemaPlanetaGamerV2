<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('base_rate');
            $table->unsignedBigInteger('sell_rate');
            $table->unsignedBigInteger('buy_rate');
            $table->string('cost_source', 500);
            $table->unsignedInteger('valid_minutes')->default(10);
            $table->boolean('reference_enabled')->default(false);
            $table->boolean('enabled')->default(true);
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
        });
        Schema::create('exchange_references', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->unsignedBigInteger('rate');
            $table->timestamp('quoted_at');
            $table->timestamp('next_update_at');
            $table->timestamp('fetched_at');
            $table->unique(['provider', 'quoted_at']);
        });
        Schema::create('international_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_box_id')->constrained('cash_boxes')->restrictOnDelete();
            $table->foreignId('created_in_session_id')->constrained('cash_box_sessions')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('exchange_policy_id')->constrained('exchange_policies')->restrictOnDelete();
            $table->foreignId('processing_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('direction', 8);
            $table->string('status', 40);
            $table->string('component');
            $table->string('sender_name', 160);
            $table->string('sender_whatsapp', 20);
            $table->string('recipient_name', 160);
            $table->string('pix_key', 160)->nullable();
            $table->string('bank', 160)->nullable();
            $table->json('quote');
            $table->unsignedBigInteger('bob_cents');
            $table->unsignedBigInteger('brl_cents');
            $table->unsignedBigInteger('principal_cents');
            $table->unsignedBigInteger('margin_cents');
            $table->unsignedBigInteger('local_held_cents')->default(0);
            $table->unsignedBigInteger('admin_principal_cents')->default(0);
            $table->foreignId('commission_operation_id')->nullable()->unique()->constrained('cash_box_operations')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->index(['cash_box_id', 'status']);
            $table->index(['requested_by', 'id']);
            $table->index(['status', 'id']);
        });
        Schema::create('international_transfer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('international_transfer_id')->constrained('international_transfers')->restrictOnDelete();
            $table->foreignId('cash_box_session_id')->constrained('cash_box_sessions')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('previous_status', 40);
            $table->string('next_status', 40);
            $table->bigInteger('cash_delta_cents');
            $table->bigInteger('held_delta_cents');
            $table->string('reference', 120)->nullable();
            $table->string('reference_scope', 40)->nullable();
            $table->text('receipt');
            $table->string('receipt_file_path')->nullable();
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['reference_scope', 'reference']);
            $table->index(['international_transfer_id', 'id']);
        });
        Schema::table('cash_box_closes', function (Blueprint $table): void {
            $table->json('reserved_funds_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_closes', fn (Blueprint $table) => $table->dropColumn('reserved_funds_snapshot'));
        Schema::dropIfExists('international_transfer_events');
        Schema::dropIfExists('international_transfers');
        Schema::dropIfExists('exchange_references');
        Schema::dropIfExists('exchange_policies');
    }
};
