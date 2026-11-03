<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_store_sessions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('card_store_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unsignedTinyInteger('active_slot')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->unique(['card_store_id', 'active_slot']);
        });
        Schema::create('card_store_counts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('card_store_id')->constrained()->restrictOnDelete();
            $t->foreignId('card_store_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('responsible_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('kind', 8);
            $t->unsignedBigInteger('store_revision');
            $t->unsignedBigInteger('cash_expected_cents');
            $t->unsignedBigInteger('cash_counted_cents');
            $t->unsignedBigInteger('cash_reserved_expected_cents')->default(0);
            $t->unsignedBigInteger('cash_reserved_counted_cents')->default(0);
            $t->unsignedBigInteger('cash_available_counted_cents')->default(0);
            $t->unsignedBigInteger('shortage_cents');
            $t->json('lines');
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
            $t->unique(['card_store_session_id', 'kind']);
        });
        Schema::create('card_store_reserves', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('card_store_id')->constrained()->restrictOnDelete();
            $t->foreignId('card_store_count_id')->constrained()->restrictOnDelete();
            $t->foreignId('card_product_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('original_amount');
            $t->unsignedBigInteger('held_amount');
            $t->unsignedBigInteger('missing_amount')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamp('created_at');
            $t->index(['card_store_id', 'released_at']);
        });
        Schema::table('card_stores', function (Blueprint $t): void {
            $t->timestamp('session_control_started_at')->nullable();
        });
        Schema::table('card_inventory_movements', function (Blueprint $t): void {
            $t->foreignId('card_store_session_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('card_inventory_movements', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('card_store_session_id');
        });
        Schema::table('card_stores', fn (Blueprint $t) => $t->dropColumn('session_control_started_at'));
        Schema::dropIfExists('card_store_reserves');
        Schema::dropIfExists('card_store_counts');
        Schema::dropIfExists('card_store_sessions');
    }
};
