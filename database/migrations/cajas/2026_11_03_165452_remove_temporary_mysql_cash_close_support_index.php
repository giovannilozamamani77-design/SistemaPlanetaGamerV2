<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasIndex('cash_box_closes', 'cash_close_mysql_fk_support') && Schema::hasIndex('cash_box_closes', 'cash_box_closes_session_active_unique')) {
            Schema::table('cash_box_closes', fn (Blueprint $table) => $table->dropIndex('cash_close_mysql_fk_support'));
        }
    }

    public function down(): void
    {
        // Compatibility cleanup has no business data to revert.
    }
};
