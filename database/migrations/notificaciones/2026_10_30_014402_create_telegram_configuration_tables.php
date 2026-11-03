<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bot_settings', function (Blueprint $t): void {
            $t->id();
            $t->string('module', 30)->unique();
            $t->string('name', 100);
            $t->text('token');
            $t->string('token_fingerprint', 64)->unique();
            $t->boolean('is_enabled')->default(false);
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('telegram_destinations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bot_id')->constrained('telegram_bot_settings')->restrictOnDelete();
            $t->foreignId('responsible_id')->constrained('users')->restrictOnDelete();
            $t->string('name', 100);
            $t->string('chat_id', 32);
            $t->string('kind', 20);
            $t->boolean('is_enabled')->default(false);
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['bot_id', 'chat_id']);
        });
        Schema::create('telegram_configuration_changes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name', 160);
            $t->string('kind', 20);
            $t->unsignedBigInteger('source_id');
            $t->json('snapshot');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_configuration_changes');
        Schema::dropIfExists('telegram_destinations');
        Schema::dropIfExists('telegram_bot_settings');
    }
};
