<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_event_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bot_id')->constrained('telegram_bot_settings')->restrictOnDelete();
            $t->string('event_code', 60);
            $t->boolean('prepared')->default(false);
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedInteger('bot_revision');
            $t->unsignedInteger('template_version')->default(1);
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['bot_id', 'event_code']);
        });
        Schema::create('telegram_event_destinations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_setting_id')->constrained('telegram_event_settings')->cascadeOnDelete();
            $t->foreignId('destination_id')->constrained('telegram_destinations')->restrictOnDelete();
            $t->unsignedInteger('destination_revision');
            $t->unique(['event_setting_id', 'destination_id'], 'telegram_event_destination_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_event_destinations');
        Schema::dropIfExists('telegram_event_settings');
    }
};
