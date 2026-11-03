<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->string('export_scope', 16)->default('SUMMARY');
            $table->string('detail_metric', 16)->nullable();
            $table->string('detail_source', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', fn (Blueprint $table) => $table->dropColumn(['export_scope', 'detail_metric', 'detail_source']));
    }
};
