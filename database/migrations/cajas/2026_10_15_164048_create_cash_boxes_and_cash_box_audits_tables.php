<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_boxes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->unsignedBigInteger('base_capital_cents');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('cash_box_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('action', 30);
            $table->string('payload_hash', 64);
            $table->json('before')->nullable();
            $table->json('after');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['cash_box_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_box_audits');
        Schema::dropIfExists('cash_boxes');
    }
};
