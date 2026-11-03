<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_liquidations', function (Blueprint $table) {
            $table->string('receipt_path')->nullable();
            $table->string('receipt_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_liquidations', fn (Blueprint $table) => $table->dropColumn(['receipt_path', 'receipt_sha256']));
    }
};
