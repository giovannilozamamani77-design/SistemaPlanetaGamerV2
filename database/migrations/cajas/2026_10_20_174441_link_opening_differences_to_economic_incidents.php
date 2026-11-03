<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_box_receipt_discrepancies', function (Blueprint $table): void {
            $table->foreignId('economic_incident_id')->nullable()->unique()->constrained('economic_incidents')->restrictOnDelete();
            $table->string('classification', 24)->nullable();
        });
        Schema::table('economic_incidents', function (Blueprint $table): void {
            $table->text('receipt_justification')->nullable();
            $table->timestamp('receipt_justified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_box_receipt_discrepancies', function (Blueprint $table): void {
            $table->dropForeign(['economic_incident_id']);
            $table->dropUnique(['economic_incident_id']);
            $table->dropColumn(['economic_incident_id', 'classification']);
        });
        Schema::table('economic_incidents', fn (Blueprint $table) => $table->dropColumn(['receipt_justification', 'receipt_justified_at']));
    }
};
