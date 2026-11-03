<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cash_box_corrections', function (Blueprint $table) {
            $table->unsignedBigInteger('response_version')->default(0);
            $table->unsignedBigInteger('viewed_response_version')->default(0);
            $table->timestamp('response_read_at')->nullable();
            $table->index(['cash_box_session_id', 'status'], 'cash_box_corrections_session_status_index');
        });
        DB::table('cash_box_corrections')->whereNotNull('decided_at')->update(['response_version' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_box_corrections', function (Blueprint $table) {
            $table->dropIndex('cash_box_corrections_session_status_index');
            $table->dropColumn(['response_version', 'viewed_response_version', 'response_read_at']);
        });
    }
};
