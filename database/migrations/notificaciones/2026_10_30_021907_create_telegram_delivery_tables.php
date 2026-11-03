<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_dispatches', function (Blueprint $t) {
            $t->id();
            $t->string('event_key', 64)->unique();
            $t->string('module', 30);
            $t->string('event_code', 60);
            $t->string('source_reference', 160);
            $t->text('message')->nullable();
            $t->string('payload_hash', 64);
            $t->string('status', 30);
            $t->string('reason', 50)->nullable();
            $t->timestamps();
            $t->index(['module', 'id']);
        });
        Schema::create('telegram_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dispatch_id')->constrained('telegram_dispatches')->restrictOnDelete();
            $t->foreignId('bot_id')->constrained('telegram_bot_settings')->restrictOnDelete();
            $t->foreignId('destination_id')->constrained('telegram_destinations')->restrictOnDelete();
            $t->foreignId('event_setting_id')->constrained('telegram_event_settings')->restrictOnDelete();
            $t->string('destination_name', 100);
            $t->text('chat_id');
            $t->string('chat_fingerprint', 64);
            $t->json('snapshot');
            $t->string('status', 30)->default('PENDIENTE');
            $t->unsignedInteger('automatic_attempts')->default(0);
            $t->unsignedInteger('attempt_count')->default(0);
            $t->boolean('manual_pending')->default(false);
            $t->string('message_id', 64)->nullable();
            $t->string('last_code', 50)->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('queued_at')->nullable();
            $t->uuid('claim_token')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamps();
            $t->unique(['dispatch_id', 'chat_fingerprint'], 'telegram_delivery_fact_chat_unique');
            $t->index(['status', 'next_attempt_at']);
            $t->index(['status', 'lease_until']);
        });
        Schema::create('telegram_delivery_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('delivery_id')->constrained('telegram_deliveries')->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('kind', 10);
            $t->string('status', 30);
            $t->string('code', 50)->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->unique(['delivery_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_delivery_attempts');
        Schema::dropIfExists('telegram_deliveries');
        Schema::dropIfExists('telegram_dispatches');
    }
};
