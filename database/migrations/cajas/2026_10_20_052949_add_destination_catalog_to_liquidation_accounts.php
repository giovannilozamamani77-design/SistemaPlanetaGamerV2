<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_commission_accounts', function (Blueprint $table): void {
            $table->string('name', 120)->default('Yape de comisiones');
            $table->string('account_key', 36)->default('legacy');
            $table->index(['account_key', 'id']);
        });
        Schema::table('cash_box_configurations', function (Blueprint $table): void {
            $table->string('settlement_destination_key', 36)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_configurations', fn (Blueprint $table) => $table->dropColumn('settlement_destination_key'));
        Schema::table('cash_box_commission_accounts', function (Blueprint $table): void {
            $table->dropIndex(['account_key', 'id']);
            $table->dropColumn(['name', 'account_key']);
        });
    }
};
