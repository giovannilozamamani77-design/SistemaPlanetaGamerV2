<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_object_expenses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->unsignedBigInteger('amount_cents');
            $t->json('summary');
            $t->timestamp('created_at');
            $t->index(['loan_id', 'id']);
        });
        Schema::create('loan_object_sales', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('loan_id')->unique()->constrained('loans')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->json('summary');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_object_sales');
        Schema::dropIfExists('loan_object_expenses');
    }
};
