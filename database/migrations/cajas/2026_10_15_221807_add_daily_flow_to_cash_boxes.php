<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_sessions', function (Blueprint $t): void {
            $t->unsignedBigInteger('operating_capital_cents')->nullable();
        });
        Schema::table('cash_box_closes', function (Blueprint $t): void {
            $t->unsignedTinyInteger('workflow_version')->default(1);
            $t->unsignedBigInteger('provisional_surplus_cents')->default(0);
            $t->unsignedBigInteger('unavailable_commission_cents')->default(0);
            $t->timestamp('surplus_recognized_at')->nullable();
            $t->timestamp('closure_approved_at')->nullable();
            $t->json('temporary_funds_snapshot')->nullable();
        });
        Schema::create('cash_box_temporary_funds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_in_session_id')->constrained('cash_box_sessions')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 16);
            $t->string('component', 20);
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('remaining_cents');
            $t->text('reason');
            $t->timestamp('created_at');
            $t->index(['cash_box_id', 'remaining_cents']);
        });
        Schema::create('cash_box_temporary_fund_movements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_temporary_fund_id')->constrained(indexName: 'cb_tfm_fund_fk')->restrictOnDelete();
            $t->foreignId('cash_box_session_id')->constrained(indexName: 'cb_tfm_session_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users', indexName: 'cb_tfm_actor_fk')->restrictOnDelete();
            $t->string('component', 20);
            $t->bigInteger('delta_cents');
            $t->text('reason');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_box_temporary_fund_movements');
        Schema::dropIfExists('cash_box_temporary_funds');
        Schema::table('cash_box_closes', fn (Blueprint $t) => $t->dropColumn(['workflow_version', 'provisional_surplus_cents', 'unavailable_commission_cents', 'surplus_recognized_at', 'closure_approved_at', 'temporary_funds_snapshot']));
        Schema::table('cash_box_sessions', fn (Blueprint $t) => $t->dropColumn('operating_capital_cents'));
    }
};
