<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_message_templates', function (Blueprint $t): void {
            $t->id();
            $t->string('module', 30);
            $t->string('event_code', 60);
            $t->longText('body');
            $t->string('image_mode', 12);
            $t->longText('image')->nullable();
            $t->unsignedInteger('revision');
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['module', 'event_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_message_templates');
    }
};
