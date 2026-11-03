<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_close_drafts', function (Blueprint $table) {
            $table->foreignId('liquidation_account_id')->nullable()->constrained('cash_box_commission_accounts');
            $table->text('liquidation_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_close_drafts', function (Blueprint $table) {
            $table->dropForeign(['liquidation_account_id']);
            $table->dropColumn(['liquidation_account_id', 'liquidation_context']);
        });
    }
};
