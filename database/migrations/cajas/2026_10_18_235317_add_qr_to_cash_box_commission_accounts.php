<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_commission_accounts', function (Blueprint $table) {
            $table->string('qr_path')->nullable();
            $table->string('qr_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_commission_accounts', fn (Blueprint $table) => $table->dropColumn(['qr_path', 'qr_sha256']));
    }
};
