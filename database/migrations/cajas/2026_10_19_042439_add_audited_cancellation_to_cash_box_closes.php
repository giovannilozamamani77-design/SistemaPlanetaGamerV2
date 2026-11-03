<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_closes', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->dropUnique('cash_box_closes_cash_box_session_id_unique');
            $table->unique(['cash_box_session_id', 'active_slot'], 'cash_box_closes_session_active_unique');
        });
        Schema::table('cash_box_counter_readings', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('cash_box_closes')->whereNotNull('cancelled_at')->exists()) {
            throw new RuntimeException('No se puede retirar el historial de anulaciones. Usa una migración hacia adelante.');
        }
        Schema::table('cash_box_counter_readings', fn (Blueprint $table) => $table->dropColumn('voided_at'));
        Schema::table('cash_box_closes', function (Blueprint $table): void {
            $table->dropUnique('cash_box_closes_session_active_unique');
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason', 'active_slot']);
            $table->unique('cash_box_session_id');
        });
    }
};
