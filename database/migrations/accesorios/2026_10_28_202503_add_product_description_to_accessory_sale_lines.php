<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessory_sale_lines', function (Blueprint $table): void {
            $table->text('product_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accessory_sale_lines', function (Blueprint $table): void {
            $table->dropColumn('product_description');
        });
    }
};
