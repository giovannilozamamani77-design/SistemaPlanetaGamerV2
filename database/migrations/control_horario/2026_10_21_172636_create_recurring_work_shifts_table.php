<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_work_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('period', 16);
            $table->unsignedInteger('revision');
            $table->boolean('enabled');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->timestamps();
            $table->unique(['user_id', 'period', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_work_shifts');
    }
};
