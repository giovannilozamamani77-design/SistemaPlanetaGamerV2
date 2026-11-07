<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_sale_cancellation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('card_sale_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 12)->default('PENDIENTE');
            $table->text('reason');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('card_sale_cancellation_requests')->exists()) {
            throw new RuntimeException('El historial de solicitudes debe conservarse.');
        }
        Schema::dropIfExists('card_sale_cancellation_requests');
    }
};
