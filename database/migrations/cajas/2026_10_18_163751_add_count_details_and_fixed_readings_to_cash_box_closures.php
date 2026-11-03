<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_closes', function (Blueprint $table) {
            $table->json('count_details')->nullable();
        });
        Schema::table('cash_box_counter_readings', function (Blueprint $table) {
            $table->unsignedBigInteger('fixed_reading')->nullable();
            $table->unsignedBigInteger('fixed_delta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_closes', function (Blueprint $table) {
            $table->dropColumn('count_details');
        });
        Schema::table('cash_box_counter_readings', function (Blueprint $table) {
            $table->dropColumn(['fixed_reading', 'fixed_delta']);
        });
    }
};
