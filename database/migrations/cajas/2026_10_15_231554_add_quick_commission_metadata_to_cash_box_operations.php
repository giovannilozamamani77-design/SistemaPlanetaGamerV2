<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_operations', function (Blueprint $table): void {
            $table->boolean('commission_only')->default(false);
            $table->string('movement', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_operations', function (Blueprint $table): void {
            $table->dropColumn(['commission_only', 'movement']);
        });
    }
};
