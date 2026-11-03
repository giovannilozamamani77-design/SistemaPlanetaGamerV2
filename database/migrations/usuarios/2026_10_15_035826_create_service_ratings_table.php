<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashier_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('value', ['buena', 'regular', 'mala']);
            $table->uuid('submission_id')->unique();
            $table->timestamp('recorded_at');
            $table->index(['cashier_user_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ratings');
    }
};
