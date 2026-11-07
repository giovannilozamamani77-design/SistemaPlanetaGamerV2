<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_sale_cancellations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('card_sale_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('card_store_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->text('reason');
            $t->unsignedBigInteger('total_cents');
            $t->unsignedBigInteger('total_quantity');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('card_sale_cancellations')->exists()) {
            throw new RuntimeException('No se elimina una auditoría con anulaciones registradas.');
        } Schema::dropIfExists('card_sale_cancellations');
    }
};
