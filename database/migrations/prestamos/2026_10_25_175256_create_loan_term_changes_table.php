<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_term_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->unsignedBigInteger('previous_revision')->default(0);
            $table->string('kind', 16);
            $table->json('summary');
            $table->timestamp('created_at');
            $table->unique(['loan_id', 'previous_revision']);
            $table->index(['loan_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_term_changes');
    }
};
