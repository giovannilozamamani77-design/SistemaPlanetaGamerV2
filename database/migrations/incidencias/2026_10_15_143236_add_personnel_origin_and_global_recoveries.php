<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('economic_incidents', function (Blueprint $t): void {
            $t->string('box_label', 120)->nullable();
            $t->string('cause', 24)->default('CAJERA');
            $t->unsignedBigInteger('forgiven_cents')->default(0);
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
        });
        Schema::create('global_recoveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('declared_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('method', 24);
            $t->unsignedBigInteger('amount_cents');
            $t->string('operation_reference')->nullable();
            $t->string('destination')->nullable();
            $t->string('salary_period', 7)->nullable();
            $t->text('note');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->unique(['destination', 'operation_reference']);
            $t->index(['user_id', 'created_at']);
        });
        Schema::table('recovery_payments', function (Blueprint $t): void {
            $t->foreignId('global_recovery_id')->nullable()->constrained('global_recoveries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recovery_payments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('global_recovery_id');
        });
        Schema::dropIfExists('global_recoveries');
        Schema::table('economic_incidents', function (Blueprint $t): void {
            $t->dropColumn(['box_label', 'cause', 'forgiven_cents', 'period_start', 'period_end']);
        });
    }
};
