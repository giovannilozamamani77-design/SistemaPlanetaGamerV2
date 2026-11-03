<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_funds', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->bigInteger('balance_cents')->default(0);
            $t->unsignedBigInteger('revision')->default(0);
            $t->timestamp('initialized_at')->nullable();
            $t->foreignId('initialized_by')->nullable()->constrained('users');
            $t->string('bank', 120)->nullable();
            $t->string('number', 120)->nullable();
            $t->string('holder', 120)->nullable();
            $t->string('qr_path')->nullable();
            $t->string('qr_sha256', 64)->nullable();
        });
        Schema::create('loan_object_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('normalized_name', 120)->unique();
            $t->unsignedInteger('rate_bps');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users');
        });
        Schema::create('loan_borrowers', function (Blueprint $t) {
            $t->id();
            $t->string('ci', 60)->unique();
            $t->string('name', 160);
            $t->string('phone', 40)->nullable();
        });
        Schema::create('loans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('borrower_id')->constrained('loan_borrowers');
            $t->foreignId('object_type_id')->constrained('loan_object_types');
            $t->foreignId('user_id')->constrained('users');
            $t->uuid('request_key')->unique();
            $t->json('borrower_snapshot');
            $t->json('object_snapshot');
            $t->json('account_snapshot');
            $t->json('cashier_snapshot');
            $t->unsignedBigInteger('capital_cents');
            $t->unsignedInteger('original_rate_bps');
            $t->unsignedTinyInteger('original_days');
            $t->unsignedBigInteger('original_interest_cents');
            $t->unsignedBigInteger('original_total_cents');
            $t->date('start_date');
            $t->date('original_due_date');
            $t->date('due_date')->index();
            $t->string('status', 40)->default('ACTIVO');
            $t->string('delivery_method', 20);
            $t->timestamp('created_at');
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('loan_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loan_id')->nullable()->constrained('loans');
            $t->foreignId('user_id')->constrained('users');
            $t->string('kind', 30);
            $t->bigInteger('delta_cents');
            $t->unsignedBigInteger('before_cents');
            $t->unsignedBigInteger('after_cents');
            $t->string('reference', 200)->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('loan_commands', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('user_id')->constrained('users');
            $t->string('kind', 40);
            $t->string('payload_hash', 64);
            $t->json('result');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['loan_commands', 'loan_movements', 'loans', 'loan_borrowers', 'loan_object_types', 'loan_funds'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
