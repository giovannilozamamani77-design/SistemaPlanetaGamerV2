<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_workdays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('work_date');
            $table->string('period', 16);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
            $table->unique(['user_id', 'work_date', 'period']);
        });
        Schema::create('attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduled_workday_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('first_pin_at');
            $table->string('status', 32)->default('PRESENTE_SIN_APERTURA');
            $table->timestamps();
        });
        Schema::create('workday_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('scheduled_workday_id')->constrained()->restrictOnDelete();
            $table->string('action', 32);
            $table->json('changes');
            $table->timestamp('created_at');
            $table->index(['scheduled_workday_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workday_audits');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('scheduled_workdays');
    }
};
