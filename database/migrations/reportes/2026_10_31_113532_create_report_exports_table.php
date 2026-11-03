<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('document_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->string('format', 8);
            $table->string('period_mode', 12);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('previous_start')->nullable();
            $table->date('previous_end')->nullable();
            $table->timestamp('generated_at');
            $table->string('document_sha256', 64);
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
