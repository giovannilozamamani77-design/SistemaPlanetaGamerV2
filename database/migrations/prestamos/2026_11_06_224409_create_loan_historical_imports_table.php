<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_historical_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->unique()->constrained('loans')->restrictOnDelete();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->string('external_reference', 120);
            $table->char('reference_key', 64)->unique();
            $table->bigInteger('fund_delta_cents');
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('loan_historical_imports') && DB::table('loan_historical_imports')->exists()) {
            throw new RuntimeException('Conserva las importaciones confirmadas; aplica una corrección hacia adelante.');
        }
        Schema::dropIfExists('loan_historical_imports');
    }
};
