<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->unsignedInteger('original_days')->change();
            $table->json('terms_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('loans')->whereNotNull('terms_snapshot')->exists()) {
            throw new RuntimeException('No se revierte la auditoría de condiciones con préstamos registrados; aplica una corrección hacia adelante.');
        }
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn('terms_snapshot');
        });
    }
};
